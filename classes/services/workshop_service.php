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
 * Service class for handling workshop activities.
 *
 * @package     plagiarism_inspera
 * @copyright   2025 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services;

\defined('MOODLE_INTERNAL') || die();

/**
 * Service class for handling workshop activities.
 */
class workshop_service {
    /** @var \moodle_database */
    private $db;

    /** @var queue_service */
    private $queueservice;

    /**
     * Constructor.
     *
     * @param \moodle_database $db
     * @param queue_service $queueservice
     */
    public function __construct(
        \moodle_database $db,
        queue_service $queueservice
    ) {
        $this->db = $db;
        $this->queueservice = $queueservice;
    }

    /**
     * Processes all submissions when the workshop phase switches to Assessment.
     *
     * @param int $workshopid
     * @param int $cmid
     * @return void
     */
    public function process_phase_switch(int $workshopid, int $cmid): void {
        $submissions = $this->db->get_records(
            'workshop_submissions',
            ['workshopid' => $workshopid],
            'id ASC'
        );

        if (empty($submissions)) {
            return;
        }

        foreach ($submissions as $submission) {
            $this->queue_submission_files($cmid, $submission);
        }
    }

    /**
     * Handles a single submission being updated after the phase switch (Late Submissions).
     *
     * @param int $workshopid
     * @param int $cmid
     * @param int $submissionid
     * @return void
     */
    public function process_late_submission(
        int $workshopid,
        int $cmid,
        int $submissionid
    ): void {
        $submission = $this->db->get_record(
            'workshop_submissions',
            ['id' => $submissionid, 'workshopid' => $workshopid]
        );

        if (!$submission) {
            return;
        }

        $this->queue_submission_files($cmid, $submission);
    }

    /**
     * Helper to retrieve and queue all files and online text for a given submission.
     *
     * @param int $cmid
     * @param \stdClass $submission
     * @return void
     */
    private function queue_submission_files(
        int $cmid,
        \stdClass $submission
    ): void {
        global $CFG;
        $fs = get_file_storage();
        $context = \context_module::instance($cmid);

        // 1. HANDLE ONLINE TEXT.
        // Moodle workshops store inline text directly in the submission content field.
        if (!empty($submission->content) && !empty(trim(strip_tags($submission->content)))) {
            require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

            $cm = get_coursemodule_from_id('workshop', $cmid, 0, false, IGNORE_MISSING);

            if ($cm) {
                $tempfile = plagiarism_inspera_create_temp_file(
                    $cmid,
                    $cm->course,
                    (int) $submission->authorid,
                    $submission->content,
                    (int) $submission->id
                );

                if ($tempfile) {
                    $this->queueservice->queue_file(
                        $cmid,
                        (int) $submission->authorid,
                        $tempfile,
                        null,
                        (int) $submission->id
                    );
                }
            }
        }

        // 2. HANDLE ATTACHMENTS.
        // submission_content: Images embedded in the editor.
        // submission_attachment: Physical files attached to the submission.
        $fileareas = ['submission_content', 'submission_attachment'];

        foreach ($fileareas as $filearea) {
            $files = $fs->get_area_files(
                $context->id,
                'mod_workshop',
                $filearea,
                $submission->id,
                'itemid, filepath, filename',
                false
            );

            if (empty($files)) {
                continue;
            }

            foreach ($files as $file) {
                // Ignore directory objects.
                if ($file->get_filename() === '.') {
                    continue;
                }

                $this->queueservice->queue_file(
                    $cmid,
                    (int) $submission->authorid,
                    $file,
                    null,
                    (int) $submission->id
                );
            }
        }
    }
}
