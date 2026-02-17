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
 * Ad-hoc task to trigger resubmission of all files in a specific assignment.
 */
class resubmit_all_reports extends \core\task\adhoc_task {

    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $cmid = $data->cmid;

        mtrace("Starting Resubmit All for CMID: " . $cmid);

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cmid);

        // Fetch assignment details
        $assign_instance = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

        // ---------------------------------------------------------
        // PART A: PROCESS FILES
        // ---------------------------------------------------------
        $fs = get_file_storage();

        // Get all submission files (only the current active ones)
        $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', false, 'timemodified', false);

        mtrace("Found " . count($files) . " candidate files.");

        foreach ($files as $file) {
            if ($file->get_filename() === '.') continue;

            $storedfileid = $file->get_id();
            $userid = $file->get_userid();
            $itemid = $file->get_itemid();

            // FIX: Get strictly the LATEST record for this file
            // If duplicates exist, we only care about the most recent one.
            $sql = "SELECT * FROM {plagiarism_inspera_subs} 
                    WHERE storedfileid = ? 
                    ORDER BY timecreated DESC, id DESC";

            // get_record_sql with IGNORE_MULTIPLE automatically fetches the first one (the latest)
            $record = $DB->get_record_sql($sql, [$storedfileid], IGNORE_MULTIPLE);

            if ($this->should_process($record)) {
                mtrace("Queuing File ID: " . $storedfileid . " (User: $userid)");

                if ($record) {
                    $record->status = 'retrying';
                    $record->error = '';
                    $DB->update_record('plagiarism_inspera_subs', $record);
                }

                plagiarism_inspera_queue_file($cmid, $userid, $file, null, $itemid);
            }
        }

        // ---------------------------------------------------------
        // PART B: PROCESS ONLINE TEXT
        // ---------------------------------------------------------
        $sql = "SELECT s.id as submissionid, s.userid, ot.onlinetext
                FROM {assign_submission} s
                JOIN {assignsubmission_onlinetext} ot ON ot.submission = s.id
                WHERE s.assignment = ? AND s.status = 'submitted'";

        $text_submissions = $DB->get_records_sql($sql, [$assign_instance->id]);

        mtrace("Found " . count($text_submissions) . " online text submissions.");

        foreach ($text_submissions as $sub) {

            // FIX: Get strictly the LATEST record for this online text
            // Online text will DEFINITELY have superseded rows. We must ignore them.
            $sql = "SELECT * FROM {plagiarism_inspera_subs} 
                    WHERE submissionid = ? AND storedfileid IS NULL
                    ORDER BY timecreated DESC, id DESC";

            $record = $DB->get_record_sql($sql, [$sub->submissionid], IGNORE_MULTIPLE);

            if ($this->should_process($record)) {
                mtrace("Queuing Online Text for Submission ID: " . $sub->submissionid);

                if ($record) {
                    $record->status = 'retrying';
                    $record->error = '';
                    $DB->update_record('plagiarism_inspera_subs', $record);
                }

                // 1. Create the temp file (returns an OBJECT containing filepath and filename)
                $temp_file_object = plagiarism_inspera_create_temp_file($cmid, $cm->course, $sub->userid, $sub->onlinetext);

                // 2. Create the dummy object expected by queue_file
                $dummy_file = new \stdClass();
                // FIX: Extract only the string path, not the whole object
                $dummy_file->filepath = $temp_file_object->filepath;

                // 3. Queue it
                plagiarism_inspera_queue_file($cmid, $sub->userid, $dummy_file, null, $sub->submissionid);
            }
        }

        mtrace("Resubmit All Task Completed.");
    }

    /**
     * Determines if a submission needs to be (re)queued based on Inspera status logic.
     * * Logic:
     * - No record -> YES (queued)
     * - Error / External Error -> YES (queued)
     * - report_requested (Queued) > 10 mins -> YES (re-queued)
     * - pending -> NO
     * - finished -> NO
     * * @param stdClass|false $record The plagiarism_inspera_subs record
     * @return bool
     */
    private function should_process($record) {
        // 1. No record exists -> Queue it
        if (!$record) {
            return true;
        }

        $status = $record->status;

        // 2. Error states -> Retry
        if ($status === 'error' || $status === 'external_error') {
            return true;
        }

        // 3. Queued but stuck? (report_requested > 10 mins)
        if ($status === 'report_requested') {
            $ten_mins_ago = time() - (10 * 60);
            if ($record->timemodified < $ten_mins_ago) {
                mtrace(" -> Found stuck item (queued > 10m). Retrying.");
                return true;
            }
        }

        // 4. Pending (Processing) or Finished (Success) -> Skip
        if ($status === 'pending' || $status === 'finished' || $status === 'processing' || $status === 'superseded') {
            return false;
        }

        return false;
    }
}
