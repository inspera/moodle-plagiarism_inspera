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

/**
 * Service class for handling workshop activities.
 */
class workshop_service {
    /** @var int Moodle's internal integer for the Workshop Assessment phase. */
    public const PHASE_ASSESSMENT = 30;

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
        global $CFG;

        $cm = get_coursemodule_from_id('workshop', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        // Include the lib once for the entire batch of submissions.
        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

        $settings = \plagiarism_inspera_get_cm_settings($cmid);
        $restriction = isset($settings['originality_restrictcontent'])
            ? (int)$settings['originality_restrictcontent']
            : PLAGIARISM_INSPERA_RESTRICTCONTENTNO;

        $submissions = $this->db->get_recordset(
            'workshop_submissions',
            ['workshopid' => $workshopid],
            'id ASC'
        );
        try {
            foreach ($submissions as $submission) {
                $this->queue_submission_files($cmid, (int)$cm->course, $submission, $restriction);
            }
        } finally {
            $submissions->close();
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
        global $CFG;

        $cm = get_coursemodule_from_id('workshop', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

        $submission = $this->db->get_record(
            'workshop_submissions',
            ['id' => $submissionid, 'workshopid' => $workshopid]
        );

        if (!$submission) {
            return;
        }

        // Fetch restriction.
        $settings = \plagiarism_inspera_get_cm_settings($cmid);
        $restriction = isset($settings['originality_restrictcontent'])
            ? (int)$settings['originality_restrictcontent']
            : PLAGIARISM_INSPERA_RESTRICTCONTENTNO;

        $this->queue_submission_files($cmid, (int)$cm->course, $submission, $restriction);
    }

    /**
     * Helper to retrieve and queue all files and online text for a given submission.
     *
     * @param int $cmid
     * @param int $courseid
     * @param \stdClass $submission
     * @param int $restriction The content restriction setting (0=Both, 1=Files, 2=Text).
     * @return void
     */
    private function queue_submission_files(
        int $cmid,
        int $courseid,
        \stdClass $submission,
        int $restriction
    ): void {
        $fs = get_file_storage();

        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return; // Fail gracefully if the module was deleted mid-processing.
        }

        // 1. HANDLE ONLINE TEXT.
        if (
            $restriction !== PLAGIARISM_INSPERA_RESTRICTCONTENTFILES &&
            !empty($submission->content) &&
            !empty(trim(strip_tags($submission->content)))
        ) {
            // Generate a unique fingerprint for this specific text state.
            $contenthash = md5(trim(strip_tags($submission->content)));
            $uniquefilename = "onlinetext_{$cmid}_{$submission->authorid}_{$submission->id}_{$contenthash}.html";

            // Check if this exact text version has already been queued.
            $sql = "SELECT id FROM {plagiarism_inspera_subs}
                     WHERE cm = ? AND userid = ? AND storedfileid IS NULL AND identifier LIKE ?";

            $existing = $this->db->get_record_sql(
                $sql,
                [$cmid, $submission->authorid, '%/' . $uniquefilename],
                IGNORE_MULTIPLE
            );

            // If we don't have a record for this hash, it's new/modified text.
            if (!$existing) {
                $tempfile = plagiarism_inspera_create_temp_file(
                    $cmid,
                    $courseid,
                    (int) $submission->authorid,
                    $submission->content,
                    (int) $submission->id,
                    $uniquefilename
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

        // 2. HANDLE UPLOADED FILES.
        if ($restriction !== PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT) {
            $fileareas = ['submission_attachment'];

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
                    // Ignore directories or empty references.
                    if ($file->is_directory() || $file->get_filename() === '.') {
                        continue;
                    }

                    $this->queueservice->queue_file(
                        $cmid,
                        (int)$submission->authorid,
                        $file,
                        null,
                        (int) $submission->id
                    );
                }
            }
        }
    }
}
