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
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use plagiarism_inspera\apiclient\api_client;

/**
 * The main scheduled task for the originality plugin.
 *
 * This task runs periodically (e.g., every 5 minutes) to:
 * 1. Send new file submissions ('report_requested') to the API.
 * 2. Poll the API for the status of pending submissions ('pending').
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_files extends scheduled_task {
    /**
     * Returns the name of this task (shown in admin screens)
     */
    public function get_name(): string {
        return get_string('sendfiles', 'plagiarism_inspera');
    }

    /**
     * Execute task.
     */
    public function execute() {
        global $DB;

        require_once(__DIR__ . '/../../lib.php');

        $client = new api_client();

        // Step 1: Process new files (report_requested)
        $newfiles = $DB->get_recordset('plagiarism_inspera_subs', ['status' => 'report_requested']);
        foreach ($newfiles as $file) {
            mtrace("Processing fileid: {$file->id} (create submission + upload)");
            plagiarism_inspera_send_file($file, $client);

            // allow memory cleanup
            unset($file);
        }
        $newfiles->close();

        // Step 2: Poll pending files
        $pendingfiles = $DB->get_recordset('plagiarism_inspera_subs', ['status' => 'pending']);
        foreach ($pendingfiles as $file) {
            mtrace("Polling fileid: {$file->id} (check status)");
            plagiarism_inspera_poll_file_status($file, $client);
            unset($file);
        }
        $pendingfiles->close();
    }
}
