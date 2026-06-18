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
 * Scheduled task to resubmit all reports.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * Ad-hoc task to trigger resubmission of all reports in an assignment.
 * Logic:
 * - Files: Supported for both Individual and Group assignments.
 * - Online Text: Supported for Individual assignments only.
 */
class resubmit_all_reports extends \core\task\adhoc_task {
    /**
     * Execute the task to resubmit all reports.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $cmid = (int)$data->cmid;

        // Save the 'last_resubmit_run' timestamp.
        $params = ['cm' => $cmid, 'name' => 'last_resubmit_run'];
        $config = $DB->get_record('plagiarism_inspera_config', $params);

        if (!$config) {
            $config = new \stdClass();
            $config->cm = $cmid;
            $config->name = 'last_resubmit_run';
            $config->value = time();
            $DB->insert_record('plagiarism_inspera_config', $config);
        } else {
            $config->value = time();
            $DB->update_record('plagiarism_inspera_config', $config);
        }

        mtrace("Starting Resubmit All for CMID: " . $cmid);

        // Dynamic Module Identification to protect polymorphic execution.
        $modname = $DB->get_field_sql(
            "SELECT m.name FROM {modules} m JOIN {course_modules} cm ON cm.module = m.id WHERE cm.id = ?",
            [$cmid]
        );

        if (!$modname) {
            mtrace("Error: Course module ID {$cmid} does not exist.");
            return;
        }

        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cmid);
        $client = new \plagiarism_inspera\apiclient\api_client();
        $recoveryservice = new \plagiarism_inspera\services\resubmission_recovery_service($DB);

        // PATHWAY 1: ASSIGNMENT MODULE.
        if ($modname === 'assign') {
            $assigninstance = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

            // PART A: PROCESS FILES.
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', false, 'timemodified', false);
            mtrace("Found " . count($files) . " candidate assignment files.");

            $bulkassignrecordids = [];

            foreach ($files as $file) {
                if ($file->get_filename() === '.') {
                    continue;
                }
                $storedfileid = $file->get_id();
                $itemid = $file->get_itemid();

                // Added cm scoping to file queries.
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                        WHERE cm = ? AND storedfileid = ?
                        ORDER BY timecreated DESC, id DESC";
                $record = $DB->get_record_sql($sql, [$cmid, $storedfileid], IGNORE_MULTIPLE);

                if (!$record) {
                    mtrace("Queuing Assignment File ID: " . $storedfileid);
                    plagiarism_inspera_queue_file($cmid, $file->get_userid(), $file, null, $itemid);
                    continue;
                }

                if ($this->should_process($record, $recoveryservice)) {
                    $bulkassignrecordids[] = (int)$record->id;
                }
            }
            if (!empty($bulkassignrecordids)) {
                $bulkresult = $recoveryservice->resubmit_bulk($bulkassignrecordids, $client);
                mtrace("Bulk Processed Assignment Files: {$bulkresult->recovered} recovered via pre-flight, " .
                    "{$bulkresult->queued} queued for fresh submission, {$bulkresult->skipped} skipped/aborted.");
            }

            // PART B: PROCESS ONLINE TEXT.
            if (empty($assigninstance->teamsubmission)) {
                $sql = "SELECT s.id as submissionid, s.userid, ot.onlinetext
                        FROM {assign_submission} s
                        JOIN {assignsubmission_onlinetext} ot ON ot.submission = s.id
                        WHERE s.assignment = ? AND s.status = 'submitted'";

                $textsubmissions = $DB->get_records_sql($sql, [$assigninstance->id]);
                mtrace("Found " . count($textsubmissions) . " online text assignments.");

                foreach ($textsubmissions as $sub) {
                    // Added cm scoping to text lookups to protect against polymorphic collisions.
                    $sql = "SELECT * FROM {plagiarism_inspera_subs}
                            WHERE cm = ? AND submissionid = ? AND storedfileid IS NULL
                            ORDER BY timecreated DESC, id DESC";
                    $record = $DB->get_record_sql($sql, [$cmid, $sub->submissionid], IGNORE_MULTIPLE);

                    if (!$record) {
                        mtrace("Queuing Assignment Online Text for Submission ID: " . $sub->submissionid);
                        $tempfileobject = plagiarism_inspera_create_temp_file(
                            $cmid,
                            $cm->course,
                            $sub->userid,
                            $sub->onlinetext,
                            $sub->submissionid
                        );
                        $dummyfile = new \stdClass();
                        $dummyfile->filepath = $tempfileobject->filepath;
                        plagiarism_inspera_queue_file(
                            $cmid,
                            $sub->userid,
                            $dummyfile,
                            null,
                            $sub->submissionid
                        );
                        continue;
                    }

                    if ($this->should_process($record, $recoveryservice)) {
                        $outcome = $recoveryservice->resubmit_record($record, $client);
                        if ($outcome === 'recovered') {
                            mtrace("Recovered Assignment Online Text for Submission ID {$sub->submissionid} via pre-flight.");
                            continue;
                        }
                        if ($outcome !== 'queued') {
                            continue;
                        }

                        // Queued can mean either "resumed polling" or "queued for fresh submission".
                        // Only refresh the online-text payload when a fresh submission was actually queued.
                        $refreshed = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], 'id,status', MUST_EXIST);
                        if ($refreshed->status !== 'report_requested') {
                            continue;
                        }

                        // Refresh online text payload by creating a fresh temp file reference.
                        mtrace("Updating Assignment Online Text identifier for Submission " .
                            "ID {$sub->submissionid} after pre-flight fallback.");
                        $tempfileobject = plagiarism_inspera_create_temp_file(
                            $cmid,
                            $cm->course,
                            $sub->userid,
                            $sub->onlinetext,
                            $sub->submissionid
                        );
                        $updaterecord = new \stdClass();
                        $updaterecord->id = $record->id;
                        $updaterecord->identifier = $tempfileobject->filepath;
                        $updaterecord->timemodified = time();
                        $DB->update_record('plagiarism_inspera_subs', $updaterecord);
                    }
                }
            }
        } else if ($modname === 'forum' || $modname === 'hsuforum') {
            $posttable = ($modname === 'hsuforum') ? 'hsuforum_posts' : 'forum_posts';

            // Fetch all tracked submissions using a memory-safe recordset.
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE cm = ? AND status != 'superseded'
                    ORDER BY timecreated DESC, id DESC";
            $recordset = $DB->get_recordset_sql($sql, [$cmid]);

            mtrace("Processing tracking records for Forum CMID: " . $cmid);

            $bulkforumrecordids = [];
            $fs = get_file_storage(); // Initialize file storage.

            foreach ($recordset as $record) {
                if ($this->should_process($record, $recoveryservice)) {
                    $postid = $record->submissionid;

                    // 1. Handle Inline Text Resubmission (Single Processing Required).
                    if ($record->storedfileid === null) {
                        // Validate source content exists BEFORE altering the tracking record state.
                        $message = $DB->get_field($posttable, 'message', ['id' => $postid], IGNORE_MISSING);
                        if ($message === false || trim(strip_tags($message)) === '') {
                            mtrace("Skipping orphaned Forum Online Text for Post ID {$postid}: content no longer exists.");
                            continue;
                        }

                        $outcome = $recoveryservice->resubmit_record($record, $client);

                        if ($outcome === 'recovered') {
                            mtrace("Recovered Forum Online Text for Post ID {$postid} via pre-flight.");
                            continue;
                        }
                        if ($outcome !== 'queued') {
                            continue;
                        }

                        $refreshed = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], 'id,status', MUST_EXIST);
                        if ($refreshed->status !== 'report_requested') {
                            continue;
                        }

                        mtrace("Updating Forum Online Text identifier for Post ID {$postid} after pre-flight fallback.");
                        $tempfileobject = plagiarism_inspera_create_temp_file(
                            $cmid,
                            $cm->course,
                            $record->userid,
                            $message,
                            $postid
                        );
                        $updaterecord = new \stdClass();
                        $updaterecord->id = $record->id;
                        $updaterecord->identifier = $tempfileobject->filepath;
                        $updaterecord->timemodified = time();
                        $DB->update_record('plagiarism_inspera_subs', $updaterecord);
                    } else if ((int)$record->storedfileid > 0) {
                        // 2. Handle File Attachment Resubmission (Batch Processing).
                        // Verify the physical file still exists before queuing.
                        if ($fs->get_file_by_id((int)$record->storedfileid)) {
                            $bulkforumrecordids[] = (int)$record->id;
                        } else {
                            mtrace("Skipping orphaned Forum File ID {$record->storedfileid} " .
                                " for Post ID {$postid}: physical file no longer exists.");
                        }
                    }
                }
            }

            // Always close recordsets to release database connection locks!
            $recordset->close();

            if (!empty($bulkforumrecordids)) {
                $bulkresult = $recoveryservice->resubmit_bulk($bulkforumrecordids, $client);
                mtrace(
                    "Bulk Processed Forum File Attachments: {$bulkresult->recovered} " .
                    "recovered via pre-flight, {$bulkresult->queued} queued/resumed for processing, " .
                    "{$bulkresult->skipped} skipped/aborted."
                );
            }
        } else if ($modname === 'workshop') {
            // Fetch all tracked submissions using a memory-safe recordset.
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE cm = ? AND status != 'superseded'
                    ORDER BY timecreated DESC, id DESC";
            $recordset = $DB->get_recordset_sql($sql, [$cmid]);

            mtrace("Processing tracking records for Workshop CMID: " . $cmid);

            $bulkworkshoprecordids = [];
            $fs = get_file_storage(); // Initialize file storage.

            foreach ($recordset as $record) {
                if ($this->should_process($record, $recoveryservice)) {
                    $submissionid = $record->submissionid;

                    // 1. Handle Inline Text Resubmission (Single Processing Required).
                    if ($record->storedfileid === null) {
                        // Validate source content exists BEFORE altering the tracking record state.
                        $content = $DB->get_field('workshop_submissions', 'content', ['id' => $submissionid], IGNORE_MISSING);
                        if ($content === false || trim(strip_tags($content)) === '') {
                            mtrace("Skipping orphaned Workshop Online Text for Submission ID " .
                                "{$submissionid}: content no longer exists.");
                            continue;
                        }

                        $outcome = $recoveryservice->resubmit_record($record, $client);

                        if ($outcome === 'recovered') {
                            mtrace("Recovered Workshop Online Text for Submission ID {$submissionid} via pre-flight.");
                            continue;
                        }
                        if ($outcome !== 'queued') {
                            continue;
                        }

                        $refreshed = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], 'id,status', MUST_EXIST);
                        if ($refreshed->status !== 'report_requested') {
                            continue;
                        }

                        mtrace(
                            "Updating Workshop Online Text identifier for " .
                            "Submission ID {$submissionid} after pre-flight fallback."
                        );
                        $tempfileobject = plagiarism_inspera_create_temp_file(
                            $cmid,
                            $cm->course,
                            $record->userid,
                            $content,
                            $submissionid
                        );
                        $updaterecord = new \stdClass();
                        $updaterecord->id = $record->id;
                        $updaterecord->identifier = $tempfileobject->filepath;
                        $updaterecord->timemodified = time();
                        $DB->update_record('plagiarism_inspera_subs', $updaterecord);
                    } else if ((int)$record->storedfileid > 0) {
                        // 2. Handle File Attachment Resubmission (Batch Processing).
                        // Verify the physical file still exists before queuing.
                        if ($fs->get_file_by_id((int)$record->storedfileid)) {
                            $bulkworkshoprecordids[] = (int)$record->id;
                        } else {
                            mtrace("Skipping orphaned Workshop File ID " .
                                "{$record->storedfileid} for Submission ID {$submissionid}: physical file no longer exists.");
                        }
                    }
                }
            }

            // Always close recordsets to release database connection locks!
            $recordset->close();

            if (!empty($bulkworkshoprecordids)) {
                $bulkresult = $recoveryservice->resubmit_bulk($bulkworkshoprecordids, $client);
                mtrace(
                    "Bulk Processed Workshop File Attachments: {$bulkresult->recovered} " .
                    "recovered via pre-flight, {$bulkresult->queued} queued for fresh submission, " .
                    "{$bulkresult->skipped} skipped/aborted."
                );
            }
        }

        mtrace("Resubmit All Task Completed.");
    }

    /**
     * Helper to determine if a record matches Inspera's "Retry" criteria.
     */
    private function should_process(
        ?\stdClass $record,
        \plagiarism_inspera\services\resubmission_recovery_service $recoveryservice
    ): bool {
        if (!$record) {
            return false;
        }

        return $recoveryservice->is_eligible($record);
    }
}
