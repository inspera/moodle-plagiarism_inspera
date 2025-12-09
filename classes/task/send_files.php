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
 * Scheduled task to send and check files with the Originality API.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use plagiarism_originality\apiclient\api_client;

/**
 * The main scheduled task for the originality plugin.
 *
 * This task runs periodically (e.g., every 5 minutes) to:
 * 1. Send new file submissions ('report_requested') to the API.
 * 2. Poll the API for the status of pending submissions ('pending').
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_files extends scheduled_task {

    /**
     * Returns the name of this task (shown in admin screens)
     */
    public function get_name(): string {
        return get_string('sendfiles', 'plagiarism_originality');
    }

    /**
     * Execute task.
     */
    public function execute() {
        global $DB;

        require_once(__DIR__ . '/../../lib.php');

        $client = new api_client();

        // Step 1: Process new files (report_requested)
        $newfiles = $DB->get_recordset('plagiarism_originality_subs', ['status' => 'report_requested']);
        foreach ($newfiles as $file) {
            // Check if we should wait for assignment due date before processing
            if ($this->should_wait_for_due_date($file)) {
                mtrace("Skipping fileid: {$file->id} - waiting for assignment due date");
                unset($file);
                continue;
            }

            mtrace("Processing fileid: {$file->id} (create submission + upload)");
            plagiarism_originality_send_file($file, $client);

            // allow memory cleanup
            unset($file);
        }
        $newfiles->close();

        // Step 2: Poll pending files
        $pendingfiles = $DB->get_recordset('plagiarism_originality_subs', ['status' => 'pending']);
        foreach ($pendingfiles as $file) {
            mtrace("Polling fileid: {$file->id} (check status)");
            plagiarism_originality_poll_file_status($file, $client);
            unset($file);
        }
        $pendingfiles->close();
    }

    /**
     * Checks if we should wait for the assignment due date before processing a file.
     *
     * @param \stdClass $file The plagiarism submission record
     * @return bool True if we should wait, false if we should process now
     */
    private function should_wait_for_due_date($file): bool {
        global $DB;

        // Static cache to store assignment details during this cron execution.
        static $assignmentcache = [];
        static $userextensionscache = [];

        // Check cache first
        if (array_key_exists($file->cm, $assignmentcache)) {
            $assignconfig = $assignmentcache[$file->cm];
        } else {
            try {
                // Use get_coursemodule_from_id for efficient lookup
                $cm = get_coursemodule_from_id('assign', $file->cm, 0, false, MUST_EXIST);

                // Fetch only required assignment fields
                $assignment = $DB->get_record('assign',
                    ['id' => $cm->instance],
                    'id, duedate, submissiondrafts',
                    MUST_EXIST
                );

                $assignmentcache[$file->cm] = $assignment;
                $assignconfig = $assignment;

            } catch (\dml_exception $e) {
                // Module not found or not an assignment - cache and process immediately
                mtrace("Warning: Could not load assignment for cm {$file->cm}: {$e->getMessage()}");
                $assignmentcache[$file->cm] = false;
                return false;
            } catch (\moodle_exception $e) {
                mtrace("Warning: Course module {$file->cm} not found or invalid");
                $assignmentcache[$file->cm] = false;
                return false;
            }
        }

        // If lookup failed, process immediately
        if ($assignconfig === false) {
            return false;
        }

        // If submissiondrafts is 1 - process immediately (submit button required)
        if (!empty($assignconfig->submissiondrafts)) {
            return false;
        }

        // Get the actual due date for this specific user
        $duedate = $assignconfig->duedate;

        // Check for user-specific extension
        $cachekey = $assignconfig->id . '_' . $file->userid;
        if (array_key_exists($cachekey, $userextensionscache)) {
            $extensiondate = $userextensionscache[$cachekey];
        } else {
            // Check assign_user_flags for individual extensions
            $userflags = $DB->get_record('assign_user_flags',
                ['assignment' => $assignconfig->id, 'userid' => $file->userid],
                'extensionduedate'
            );

            $extensiondate = $userflags && !empty($userflags->extensionduedate) ? $userflags->extensionduedate : null;
            $userextensionscache[$cachekey] = $extensiondate;
        }

        // Use extension date if available, otherwise use assignment due date
        if ($extensiondate !== null) {
            $duedate = $extensiondate;
        }

        // No due date set - process immediately
        if (empty($duedate)) {
            return false;
        }

        // Check if due date has passed
        if (time() < $duedate) {
            // Optional: verbose logging for debugging
            if (debugging('', DEBUG_DEVELOPER)) {
                $duetype = $extensiondate !== null ? 'extension' : 'assignment';
                mtrace("Waiting for {$duetype} due date: " . userdate($duedate) . " for fileid: {$file->id}");
            }
            return true;
        }

        return false;
    }
}