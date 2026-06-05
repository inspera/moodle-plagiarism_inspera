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
 * Service class for queuing files to Inspera Originality.
 *
 * @package     plagiarism_inspera
 * @copyright   2025 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services;

/**
 * Service class for queuing files to Inspera Originality.
 */
class queue_service {
    /** @var \moodle_database */
    private $db;

    /** @var array In-memory cache for plugin config per course module. */
    private array $configcache = [];

    /** @var array|null In-memory cache for global allowed file types. */
    private ?array $defaultallowedtypes = null;

    /**
     * Constructor.
     *
     * @param \moodle_database $db
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Queues a specific file or online text reference for processing by the plagiarism API.
     *
     * @param int $cmid The course module ID.
     * @param int $userid The ID of the user who submitted.
     * @param \stored_file|object $file The Moodle stored_file object, or object with 'filepath'.
     * @param int|null $relateduserid The user ID this was submitted on behalf of.
     * @param int|null $submissionid The specific submission or attempt ID.
     * @return void
     */
    public function queue_file(
        int $cmid,
        int $userid,
        mixed $file,
        ?int $relateduserid = null,
        ?int $submissionid = null
    ): void {

        // 1. Check if plugin is actually enabled for this CM (using in-memory cache).
        if (!isset($this->configcache[$cmid])) {
            $this->configcache[$cmid] = $this->db->get_records_menu(
                'plagiarism_inspera_config',
                ['cm' => $cmid],
                '',
                'name, value'
            ) ?: []; // Fallback to empty array if nothing found.
        }

        $plagiarismvalues = $this->configcache[$cmid];

        if (empty($plagiarismvalues['use_originality'])) {
            return;
        }

        // 2. Resolve Submission ID from the file if not explicitly provided.
        if (empty($submissionid) && $file instanceof \stored_file) {
            $comp = $file->get_component();
            if ($comp === 'assignsubmission_file' || $comp === 'assignsubmission_onlinetext') {
                $submissionid = $file->get_itemid();
            }
        }
        $submissionid = (int)$submissionid;

        // 3. Determine identifiers based on file type.
        if ($file instanceof \stored_file) {
            $filename = $file->get_filename();
            $storedfileid = $file->get_id();
            $identifier = null;
        } else if (is_object($file) && isset($file->filepath)) {
            $filename = basename($file->filepath);
            $storedfileid = null;
            $identifier = $file->filepath;
        } else {
            return;
        }

        // 4. Extension check.
        $pathinfo = pathinfo($filename);
        if (empty($pathinfo['extension'])) {
            return;
        }
        $ext = strtolower($pathinfo['extension']);

        if (!$this->is_file_type_allowed($ext, $plagiarismvalues)) {
            return;
        }

        // 5. Save or update the record.
        $this->save_submission_record(
            $cmid,
            $userid,
            $submissionid,
            $storedfileid,
            $identifier,
            $relateduserid
        );
    }

    /**
     * Checks if the file extension is allowed by plugin settings.
     *
     * @param string $ext
     * @param array $plagiarismvalues
     * @return bool
     */
    private function is_file_type_allowed(string $ext, array $plagiarismvalues): bool {
        // Explicit integer comparison is safer for Moodle database flags.
        $allowall = isset($plagiarismvalues['originality_allowallfile']) ?
            ((int)$plagiarismvalues['originality_allowallfile'] === 1) : true;

        if ($allowall) {
            // Lazy-load and cache the allowed types.
            if ($this->defaultallowedtypes === null) {
                global $CFG;
                require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');
                $this->defaultallowedtypes = plagiarism_inspera_default_allowed_file_types(true);
            }
            return array_key_exists($ext, $this->defaultallowedtypes);
        } else {
            $allowedtypes = !empty($plagiarismvalues['originality_selectfiletypes'])
                ? explode(',', $plagiarismvalues['originality_selectfiletypes'])
                : [];

            $allowedtypes[] = 'html';
            $allowedtypes[] = 'htm';

            return in_array($ext, $allowedtypes);
        }
    }

    /**
     * Inserts or updates the submission record in the database.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $submissionid
     * @param int|null $storedfileid
     * @param string|null $identifier
     * @param int|null $relateduserid
     * @return void
     */
    private function save_submission_record(
        int $cmid,
        int $userid,
        int $submissionid,
        ?int $storedfileid,
        ?string $identifier,
        ?int $relateduserid
    ): void {
        $existingrecord = null;

        if ($storedfileid) {
            if ($submissionid > 0) {
                $existingrecord = $this->db->get_record(
                    'plagiarism_inspera_subs',
                    [
                        'cm' => $cmid,
                        'submissionid' => $submissionid,
                        'storedfileid' => $storedfileid,
                    ]
                );
            } else {
                $existingrecord = $this->db->get_record(
                    'plagiarism_inspera_subs',
                    [
                        'cm' => $cmid,
                        'userid' => $userid,
                        'storedfileid' => $storedfileid,
                    ]
                );
            }
        } else if ($identifier) {
            if ($submissionid > 0) {
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                         WHERE cm = ? AND userid = ? AND submissionid = ? AND storedfileid IS NULL
                      ORDER BY timecreated DESC, id DESC";
                $existingrecord = $this->db->get_record_sql(
                    $sql,
                    [$cmid, $userid, $submissionid],
                    IGNORE_MULTIPLE
                );
            } else {
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                         WHERE cm = ? AND userid = ? AND identifier = ? AND storedfileid IS NULL
                      ORDER BY timecreated DESC, id DESC";
                $existingrecord = $this->db->get_record_sql(
                    $sql,
                    [$cmid, $userid, $identifier],
                    IGNORE_MULTIPLE
                );
            }
        }

        $currenttime = time();

        if ($existingrecord) {
            $status = $existingrecord->status ?? '';

            // SCENARIO 1: FILES (Immutable in Moodle).
            if ($storedfileid) {
                if (in_array($status, ['error', 'external_error'])) {
                    $existingrecord->status = 'report_requested';
                    $existingrecord->description = '';
                    $existingrecord->externalid = '';
                    $existingrecord->timemodified = $currenttime;
                    $this->db->update_record('plagiarism_inspera_subs', $existingrecord);
                }
                return;
            }

            // SCENARIO 2: ONLINE TEXT (Mutable).
            if ($identifier) {
                // If the text actually changed (identifier mismatch),
                // or if we are explicitly re-queuing a pending/finished record, we must supersede the old one.
                if ($existingrecord->identifier !== $identifier || in_array($status, ['pending', 'finished'])) {
                    $existingrecord->status = 'superseded';
                    $existingrecord->timemodified = $currenttime;
                    $this->db->update_record('plagiarism_inspera_subs', $existingrecord);
                    // Fall through to create a NEW record.
                } else {
                    // The text is identical to the existing record.
                    if ($status === 'report_requested') {
                        $existingrecord->timemodified = $currenttime;
                        $this->db->update_record('plagiarism_inspera_subs', $existingrecord);
                        return;
                    }

                    if (in_array($status, ['error', 'external_error'])) {
                        $existingrecord->status = 'report_requested';
                        $existingrecord->description = '';
                        $existingrecord->externalid = '';
                        $existingrecord->timemodified = $currenttime;
                        $this->db->update_record('plagiarism_inspera_subs', $existingrecord);
                        return;
                    }
                }
            }
        }

        // 3. Create NEW record.
        $record = new \stdClass();
        $record->cm = $cmid;
        $record->userid = $userid;
        $record->relateduserid = $relateduserid;
        $record->submissionid = $submissionid;
        $record->storedfileid = $storedfileid;
        $record->identifier = $identifier;
        $record->status = 'report_requested';
        $record->timecreated = $currenttime;
        $record->timemodified = $currenttime;
        $record->description = '';

        $this->db->insert_record('plagiarism_inspera_subs', $record);
    }
}
