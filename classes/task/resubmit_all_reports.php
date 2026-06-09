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

        // PATHWAY 1: ASSIGNMENT MODULE.
        if ($modname === 'assign') {
            $assigninstance = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

            // PART A: PROCESS FILES.
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', false, 'timemodified', false);
            mtrace("Found " . count($files) . " candidate assignment files.");

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

                if ($this->should_process($record)) {
                    mtrace("Queuing Assignment File ID: " . $storedfileid);
                    plagiarism_inspera_queue_file($cmid, $file->get_userid(), $file, null, $itemid);
                }
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

                    if ($this->should_process($record)) {
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

            foreach ($recordset as $record) {
                if ($this->should_process($record)) {
                    $postid = $record->submissionid;

                    // 1. Handle Inline Text Resubmission.
                    if (empty($record->storedfileid)) {
                        $message = $DB->get_field($posttable, 'message', ['id' => $postid], IGNORE_MISSING);
                        if ($message !== false) {
                            mtrace("Queuing Forum Online Text for Post ID: " . $postid);
                            $tempfileobject = plagiarism_inspera_create_temp_file(
                                $cmid,
                                $cm->course,
                                $record->userid,
                                $message,
                                $postid
                            );
                            $dummyfile = new \stdClass();
                            $dummyfile->filepath = $tempfileobject->filepath;
                            plagiarism_inspera_queue_file(
                                $cmid,
                                $record->userid,
                                $dummyfile,
                                null,
                                $postid
                            );
                        }
                    } else {
                        $fs = get_file_storage();
                        $file = $fs->get_file_by_id($record->storedfileid);
                        if ($file) {
                            mtrace("Queuing Forum File Attachment ID: " . $record->storedfileid);
                            plagiarism_inspera_queue_file($cmid, $record->userid, $file, null, $postid);
                        }
                    }
                }
            }

            // Always close recordsets to release database connection locks!
            $recordset->close();
        } else if ($modname === 'workshop') {
            // Fetch all tracked submissions using a memory-safe recordset.
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE cm = ? AND status != 'superseded'
                    ORDER BY timecreated DESC, id DESC";
            $recordset = $DB->get_recordset_sql($sql, [$cmid]);

            mtrace("Processing tracking records for Workshop CMID: " . $cmid);

            foreach ($recordset as $record) {
                if ($this->should_process($record)) {
                    $submissionid = $record->submissionid;

                    // 1. Handle Inline Text Resubmission.
                    if (empty($record->storedfileid)) {
                        // Workshop stores online text in the 'content' column of the 'workshop_submissions' table.
                        $content = $DB->get_field('workshop_submissions', 'content', ['id' => $submissionid], IGNORE_MISSING);
                        if ($content !== false && trim(strip_tags($content)) !== '') {
                            mtrace("Queuing Workshop Online Text for Submission ID: " . $submissionid);
                            $tempfileobject = plagiarism_inspera_create_temp_file(
                                $cmid,
                                $cm->course,
                                $record->userid,
                                $content,
                                $submissionid
                            );
                            $dummyfile = new \stdClass();
                            $dummyfile->filepath = $tempfileobject->filepath;
                            plagiarism_inspera_queue_file(
                                $cmid,
                                $record->userid,
                                $dummyfile,
                                null,
                                $submissionid
                            );
                        }
                    } else {
                        $fs = get_file_storage();
                        $file = $fs->get_file_by_id($record->storedfileid);
                        if ($file) {
                            mtrace("Queuing Workshop File Attachment ID: " . $record->storedfileid);
                            plagiarism_inspera_queue_file(
                                $cmid,
                                $record->userid,
                                $file,
                                null,
                                $submissionid
                            );
                        }
                    }
                }
            }

            // Always close recordsets to release database connection locks!
            $recordset->close();
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
            $tenminsago = time() - (10 * 60);
            if ($record->timemodified < $tenminsago) {
                return true;
            }
        }

        if (in_array($status, ['pending', 'finished', 'superseded'])) {
            return false;
        }

        return false;
    }
}
