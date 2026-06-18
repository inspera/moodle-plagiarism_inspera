<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Service class for manual resubmission recovery checks.
 *
 * @package     plagiarism_inspera
 * @copyright   2026 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services;

use plagiarism_inspera\apiclient\api_client;

/**
 * Handles admin manual pre-flight recovery checks before forcing fresh submission.
 */
class resubmission_recovery_service {
    /** @var \moodle_database */
    private \moodle_database $db;

    /**
     * Constructor.
     *
     * @param \moodle_database $db Database instance.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Determines if a submission record is eligible for recovery.
     *
     * @param \stdClass $record Submission record from {plagiarism_inspera_subs}.
     * @return bool True if the record can be recovered/queued, otherwise false.
     */
    public function is_eligible(\stdClass $record): bool {
        $status = $record->status ?? '';

        // Allow retry for transient error states. 'fatal_error' requires manual intervention.
        if (in_array($status, ['error', 'external_error'], true)) {
            return true;
        }

        // Allow retry for 'report_requested' if stuck for > 10 minutes.
        if ($status === 'report_requested') {
            $lastmodified = (int)($record->timemodified ?? $record->timecreated ?? time());
            if ((time() - $lastmodified) > 600) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attempts recovery using an already-loaded submission record to avoid redundant DB reads.
     *
     *  Returns one of: recovered, queued, api_error, not_eligible.
     *
     * @param \stdClass $record Record from {plagiarism_inspera_subs}.
     * @param api_client $client API client.
     * @return string
     */
    public function resubmit_record(\stdClass $record, api_client $client): string {
        if (!$this->is_eligible($record)) {
            return 'not_eligible';
        }

        return $this->process_eligible_record($record, $client);
    }

    /**
     * Process one record by id.
     *
     * Returns one of: recovered, queued, api_error, not_eligible, not_found.
     *
     * @param int $id Record id.
     * @param api_client $client API client.
     * @return string
     */
    public function resubmit_single(int $id, api_client $client): string {
        $record = $this->db->get_record('plagiarism_inspera_subs', ['id' => $id]);
        if (!$record) {
            return 'not_found';
        }

        // Delegate directly; resubmit_record() handles the eligibility check natively.
        return $this->resubmit_record($record, $client);
    }

    /**
     * Process many records by id.
     *
     * @param int[] $ids Record ids.
     * @param api_client $client API client.
     * @return \stdClass {selected, recovered, queued, skipped}
     */
    public function resubmit_bulk(array $ids, api_client $client): \stdClass {
        // Normalize to unique integer IDs up-front to prevent duplicate/malformed inputs inflating the skipped counter.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn($id) => $id > 0
        )));

        $result = (object) [
            'selected' => count($ids),
            'recovered' => 0,
            'queued' => 0,
            'skipped' => 0,
        ];

        if (empty($ids)) {
            return $result;
        }

        [$insql, $inparams] = $this->db->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $records = $this->db->get_records_select('plagiarism_inspera_subs', "id $insql", $inparams);

        foreach ($records as $record) {
            // Use the unified eligibility check here!
            if (!$this->is_eligible($record)) {
                $result->skipped++;
                continue;
            }

            $outcome = $this->process_eligible_record($record, $client);
            if ($outcome === 'recovered') {
                $result->recovered++;
            } else if ($outcome === 'queued') {
                $result->queued++;
            } else {
                // Catch 'api_error' (or any future failure states) so the UI counts match.
                $result->skipped++;
            }
        }

        // Count ids that were selected but not found in DB.
        $result->skipped += max(0, $result->selected - count($records));

        return $result;
    }

    /**
     * Process one eligible error record.
     *
     * @param \stdClass $record Record from plagiarism_inspera_subs.
     * @param api_client $client API client.
     * @return string recovered|queued|api_error
     */
    private function process_eligible_record(\stdClass $record, api_client $client): string {
        if (!empty($record->externalid)) {
            try {
                $status = $client->check_document_status($record->externalid);
                if (!isset($status->status)) {
                    debugging("Recovery pre-flight failed for fileid {$record->id}: " .
                        "missing status in API response", DEBUG_DEVELOPER);
                    return 'api_error';
                }
                // 1. Finished: Recover completely.
                if ((int)$status->status === 1) {
                    $this->mark_as_recovered((int)$record->id, $status);
                    return 'recovered';
                }

                // 2. Processing/Queued: Resume polling, unless stuck for > 48 hours.
                if (in_array((int)$status->status, [0, -1], true)) {
                    $graceperiodreference = (int)($record->timemodified ?? $record->timecreated ?? time());
                    $elapsedseconds = time() - $graceperiodreference;

                    if ($elapsedseconds < (2 * DAYSECS)) {
                        $this->resume_polling((int)$record->id);
                        // Returning 'queued' gracefully increments your existing UI counters.
                        return 'queued';
                    }
                    // If it's older than 48h, Inspera's process is likely dead.
                    // Fall through to wipe the ID and send a fresh payload.
                }
            } catch (\Throwable $e) {
                // Log the network/API failure to Moodle's debug logs.
                debugging("Recovery pre-flight failed for fileid {$record->id}: " . $e->getMessage(), DEBUG_DEVELOPER);

                // ABORT processing to prevent data loss. Do NOT fall through to fresh submission!
                return 'api_error';
            }
        }

        $this->queue_for_fresh_submission((int)$record->id);
        return 'queued';
    }

    /**
     * Resumes polling for a document that is actively processing on Inspera's side.
     */
    private function resume_polling(int $recordid): void {
        $updaterecord = new \stdClass();
        $updaterecord->id = $recordid;
        $updaterecord->status = 'pending';
        $updaterecord->description = 'Document processing found active via pre-flight check. Resumed polling.';
        $updaterecord->timemodified = time();

        $this->db->update_record('plagiarism_inspera_subs', $updaterecord);
    }

    /**
     * Mark record as instantly recovered.
     *
     * @param int $id Record id.
     * @param \stdClass $status API status payload.
     * @return void
     */
    private function mark_as_recovered(int $id, \stdClass $status): void {
        $record = new \stdClass();
        $record->id = $id;
        $record->status = 'finished';
        $record->similarity = $status->similarity ?? null;
        $record->originality_score = $status->originality_percentage ?? null;
        $record->originality = $status->originality ?? null;
        $record->translation_similarity = $this->extract_translation_similarity($status);
        $record->ai_index = $this->extract_ai_index($status);
        $record->character_replacement = $this->extract_character_replacement($status);
        $record->hidden_text = $status->hiddenText ?? null;
        $record->image_as_text = $status->imageAsText ?? null;
        $record->timemodified = time();
        $record->description = 'Recovered via pre-flight check; external report already finished.';

        $this->db->update_record('plagiarism_inspera_subs', $record);
    }

    /**
     * Queue record for fresh submission and reset timeout timers.
     *
     * @param int $id Record id.
     * @return void
     */
    private function queue_for_fresh_submission(int $id): void {
        $now = time();
        $record = new \stdClass();
        $record->id = $id;
        $record->status = 'report_requested';
        $record->externalid = null;
        $record->similarity = null;
        $record->originality_score = null;
        $record->translation_similarity = null;
        $record->ai_index = null;
        $record->originality = null;
        $record->character_replacement = null;
        $record->hidden_text = null;
        $record->image_as_text = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->description = 'Queued for fresh submission after pre-flight check.';

        $this->db->update_record('plagiarism_inspera_subs', $record);
    }

    /**
     * Extract translation similarity from either API key variant.
     *
     * @param \stdClass $status
     * @return mixed
     */
    private function extract_translation_similarity(\stdClass $status): mixed {
        if (isset($status->translationSimilarity)) {
            return $status->translationSimilarity;
        }
        if (isset($status->translation_similarity)) {
            return $status->translation_similarity;
        }
        return null;
    }

    /**
     * Extract AI index from either API key variant.
     *
     * @param \stdClass $status
     * @return mixed
     */
    private function extract_ai_index(\stdClass $status): mixed {
        if (isset($status->ai_index)) {
            return $status->ai_index;
        }
        if (isset($status->Ai_index)) {
            return $status->Ai_index;
        }
        return null;
    }

    /**
     * Extract character replacement from API key variants.
     *
     * @param \stdClass $status
     * @return mixed
     */
    private function extract_character_replacement(\stdClass $status): mixed {
        if (isset($status->characterReplacement)) {
            return $status->characterReplacement;
        }
        if (isset($status->characterReplacements)) {
            return $status->characterReplacements;
        }
        return null;
    }
}
