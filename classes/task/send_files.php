<?php
namespace plagiarism_originality\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use plagiarism_originality\local\api_client;

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
            mtrace("Processing fileid: {$file->id} (create submission + upload)");

            originality_send_file($file, $client);

            // allow memory cleanup
            unset($file);
        }
        $newfiles->close();

        // Step 2: Poll pending files
        $pendingfiles = $DB->get_recordset('plagiarism_originality_subs', ['status' => 'pending']);
        foreach ($pendingfiles as $file) {
            mtrace("Polling fileid: {$file->id} (check status)");

            originality_poll_file_status($file, $client);

            unset($file);
        }
        $pendingfiles->close();
    }
}
