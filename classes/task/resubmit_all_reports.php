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

    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $cmid = $data->cmid;

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

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cmid);
        $assign_instance = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

        // ---------------------------------------------------------
        // PART A: PROCESS FILES (Individual & Group)
        // ---------------------------------------------------------
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', false, 'timemodified', false);

        mtrace("Found " . count($files) . " candidate files.");

        foreach ($files as $file) {
            if ($file->get_filename() === '.') continue;

            $storedfileid = $file->get_id();
            $itemid = $file->get_itemid(); // The Submission ID (shared by group members)

            // Fetch latest record for this file.
            $sql = "SELECT * FROM {plagiarism_inspera_subs} 
                    WHERE storedfileid = ? 
                    ORDER BY timecreated DESC, id DESC";
            $record = $DB->get_record_sql($sql, [$storedfileid], IGNORE_MULTIPLE);

            if ($this->should_process($record)) {
                mtrace("Queuing File ID: " . $storedfileid);
                plagiarism_inspera_queue_file($cmid, $file->get_userid(), $file, null, $itemid);
            }
        }

        // ---------------------------------------------------------
        // PART B: PROCESS ONLINE TEXT (Individual Only)
        // ---------------------------------------------------------
        // Only process if team submission is NOT enabled.
        if (empty($assign_instance->teamsubmission)) {
            $sql = "SELECT s.id as submissionid, s.userid, ot.onlinetext
                    FROM {assign_submission} s
                    JOIN {assignsubmission_onlinetext} ot ON ot.submission = s.id
                    WHERE s.assignment = ? AND s.status = 'submitted'";

            $text_submissions = $DB->get_records_sql($sql, [$assign_instance->id]);

            mtrace("Found " . count($text_submissions) . " online text submissions.");

            foreach ($text_submissions as $sub) {
                $sql = "SELECT * FROM {plagiarism_inspera_subs} 
                        WHERE submissionid = ? AND storedfileid IS NULL
                        ORDER BY timecreated DESC, id DESC";

                $record = $DB->get_record_sql($sql, [$sub->submissionid], IGNORE_MULTIPLE);

                if ($this->should_process($record)) {
                    mtrace("Queuing Online Text for Submission ID: " . $sub->submissionid);
                    $temp_file_object = plagiarism_inspera_create_temp_file($cmid, $cm->course, $sub->userid, $sub->onlinetext, $sub->submissionid);
                    $dummy_file = new \stdClass();
                    $dummy_file->filepath = $temp_file_object->filepath;
                    plagiarism_inspera_queue_file($cmid, $sub->userid, $dummy_file, null, $sub->submissionid);
                }
            }
        } else {
            mtrace("Skipping Online Text: Team submission is enabled.");
        }

        mtrace("Resubmit All Task Completed.");
    }

    /**
     * Helper to determine if a record matches Inspera's "Retry" criteria.
     */
    private function should_process($record) {
        if (!$record) {
            return true;
        }

        $status = $record->status;
        if ($status === 'error' || $status === 'external_error') {
            return true;
        }

        if ($status === 'report_requested') {
            $ten_mins_ago = time() - (10 * 60);
            if ($record->timemodified < $ten_mins_ago) {
                return true;
            }
        }

        // Do not re-process active or completed items.
        if (in_array($status, ['pending', 'finished', 'superseded'])) {
            return false;
        }

        return false;
    }
}
