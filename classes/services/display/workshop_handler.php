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
 * Display handler for the Workshop module.
 *
 * This class implements the logic to retrieve and link plagiarism records
 * specifically for Workshop assessments.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_handler implements handler_interface {
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
     * Generates the HTML report links for the Workshop module.
     *
     * This handler processes both file attachments uploaded and online text.
     *
     * @param array $linkarray The Moodle link data (contains cmid, userid, file, content, etc.).
     * @param array $plagiarismvalues The plugin configuration for this specific course module.
     * @param bool $isgrader Whether the current viewing user has grading capabilities for this quiz.
     * @return string HTML output containing the originality status, or an empty string if not applicable.
     */
    public function get_links(array $linkarray, array $plagiarismvalues, bool $isgrader): string {
        $output = '';
        $cmid = $linkarray['cmid'];
        $userid = $linkarray['userid'];
        $displaytype = $plagiarismvalues['originality_display_type'] ?? 'similarity';

        // 1. ATTACHMENTS
        if (!empty($linkarray['file'])) {
            $file = $linkarray['file'];
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                     WHERE cm = ? AND userid = ? AND storedfileid = ? AND status != 'superseded'
                  ORDER BY timecreated DESC, id DESC";

            $record = $this->db->get_record_sql($sql, [$cmid, $userid, $file->get_id()], IGNORE_MULTIPLE);

            if (
                $record &&
                (
                    $isgrader ||
                    plagiarism_inspera_should_show_report(
                        $cmid,
                        $userid,
                        $plagiarismvalues,
                        $record
                    )
                )
            ) {
                $output .= $this->formatter->get_originality_status($record, $displaytype);
            }
        }

        // 2. ONLINE TEXT
        if (!empty($linkarray['content']) && empty($linkarray['file'])) {
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                     WHERE cm = ? AND userid = ? AND storedfileid IS NULL AND status != 'superseded'
                  ORDER BY timecreated DESC, id DESC";

            $textrecord = $this->db->get_record_sql($sql, [$cmid, $userid], IGNORE_MULTIPLE);

            if (
                $textrecord &&
                (
                    $isgrader ||
                    plagiarism_inspera_should_show_report(
                        $cmid,
                        $userid,
                        $plagiarismvalues,
                        $textrecord
                    )
                )
            ) {
                $output .= $this->formatter->get_originality_status($textrecord, $displaytype);
            }
        }

        return $output;
    }
}
