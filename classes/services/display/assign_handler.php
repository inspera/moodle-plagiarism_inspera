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
 * Handles the display of Inspera originality reports.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services\display;

/**
 * Display handler for the Assignment module.
 *
 * This class implements the logic to retrieve and link plagiarism records
 * specifically for Assignments.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_handler implements handler_interface {
    /** @var \moodle_database The Moodle database object. */
    private $db;

    /** @var report_formatter The HTML report formatting service. */
    private $formatter;

    /**
     * Constructor for the display handler.
     *
     * @param \moodle_database $db The Moodle database object.
     * @param report_formatter $formatter The HTML report formatting service.
     */
    public function __construct(\moodle_database $db, report_formatter $formatter) {
        $this->db = $db;
        $this->formatter = $formatter;
    }

    /**
     * Generates the HTML report links for the Assignment module.
     *
     * This handler processes both file attachments uploaded and online text.
     *
     * @param array $linkarray The Moodle link data (contains cmid, userid, file, content, etc.).
     * @param array $plagiarismvalues The plugin configuration for this specific course module.
     * @param bool $isgrader Whether the current viewing user has grading capabilities for this assignment.
     * @return string HTML output containing the originality status, or an empty string if not applicable.
     */
    public function get_links(array $linkarray, array $plagiarismvalues, bool $isgrader): string {
        global $CFG, $USER;
        $output = '';

        $cmid = (int)($linkarray['cmid'] ?? 0);
        $userid = $linkarray['userid'] ?? null;

        // If the hook doesn't provide a userid, assume the viewer is the currently logged-in user.
        $vieweruserid = !empty($userid) ? (int)$userid : (int)$USER->id;

        $displaytype = $plagiarismvalues['originality_display_type'] ?? 'similarity';

        // 1. ATTACHMENTS
        if (!empty($linkarray['file'])) {
            $file = $linkarray['file'];
            $comp = $file->get_component();

            if ($comp === 'assignsubmission_file' || $comp === 'assignsubmission_onlinetext') {
                $submissionid = $file->get_itemid();

                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                         WHERE submissionid = ? AND storedfileid = ? AND status != 'superseded'
                      ORDER BY timecreated DESC, id DESC";

                $record = $this->db->get_record_sql($sql, [$submissionid, $file->get_id()], IGNORE_MULTIPLE);

                if (
                    $record &&
                    (
                        $isgrader ||
                        plagiarism_inspera_should_show_report(
                            $cmid,
                            $vieweruserid,
                            $plagiarismvalues,
                            $record
                        )
                    )
                ) {
                    $output .= $this->formatter->get_originality_status($record, $displaytype);
                }
            }
        }

        // 2. ONLINE TEXT
        // Explicitly require a valid $userid to fetch the assignment submission.
        if (!empty($linkarray['content']) && empty($linkarray['file']) && !empty($userid)) {
            $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                require_once($CFG->dirroot . '/mod/assign/locallib.php');
                $assign = new \assign(\context_module::instance($cm->id), $cm, null);

                $submission = $assign->get_user_submission((int)$userid, false);

                if ($submission) {
                    $sql = "SELECT * FROM {plagiarism_inspera_subs}
                             WHERE submissionid = ? AND storedfileid IS NULL AND status != 'superseded'
                          ORDER BY timecreated DESC, id DESC";

                    $textrecord = $this->db->get_record_sql($sql, [$submission->id], IGNORE_MULTIPLE);

                    if (
                        $textrecord &&
                        (
                            $isgrader ||
                            plagiarism_inspera_should_show_report(
                                $cmid,
                                $vieweruserid,
                                $plagiarismvalues,
                                $textrecord
                            )
                        )
                    ) {
                        $output .= $this->formatter->get_originality_status($textrecord, $displaytype);
                    }
                }
            }
        }

        return $output;
    }
}
