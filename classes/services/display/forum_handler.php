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
 * Handles the display of Inspera originality reports for forum posts.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services\display;

/**
 * Display handler for Moodle Forum and HSUForum modules.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_handler implements handler_interface {
    /** @var \moodle_database The Moodle database object. */
    private $db;

    /** @var report_formatter The HTML report formatting service. */
    private $formatter;

    /**
     * Constructor for the forum display handler.
     *
     * @param \moodle_database $db The Moodle database object.
     * @param report_formatter $formatter The HTML report formatting service.
     */
    public function __construct(\moodle_database $db, report_formatter $formatter) {
        $this->db = $db;
        $this->formatter = $formatter;
    }

    /**
     * Generates the HTML report links for a forum post or attachment.
     *
     * @param array $linkarray The Moodle link data payload.
     * @param array $plagiarismvalues The plugin configuration for this module.
     * @param bool $isgrader Whether the viewing user has grading capabilities.
     * @return string HTML output containing the originality badge/link.
     */
    public function get_links(array $linkarray, array $plagiarismvalues, bool $isgrader): string {
        global $USER;
        $output = '';

        $cmid   = (int)($linkarray['cmid'] ?? 0);
        $userid = isset($linkarray['userid']) ? (int)$linkarray['userid'] : (int)$USER->id;
        $displaytype = $plagiarismvalues['originality_display_type'] ?? 'similarity';

        // SCENARIO 1: ATTACHMENTS (Moodle passes the file object).
        if (!empty($linkarray['file'])) {
            $file = $linkarray['file'];
            $comp = $file->get_component();

            if ($comp === 'mod_forum' || $comp === 'mod_hsuforum') {
                $postid = $file->get_itemid();
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                         WHERE cm = ? AND userid = ? AND submissionid = ? AND storedfileid = ? AND status != 'superseded'
                      ORDER BY timecreated DESC, id DESC";

                $record = $this->db->get_record_sql($sql, [$cmid, $userid, $postid, $file->get_id()], IGNORE_MULTIPLE);

                if ($record && ($isgrader || plagiarism_inspera_should_show_report($cmid, $userid, $plagiarismvalues, $record))) {
                    $output .= $this->formatter->get_originality_status($record, $displaytype);
                }
            }
            return $output;
        }

        // SCENARIO 2: INLINE POST TEXT (Moodle passes content, no file).
        if (!empty($linkarray['content']) && empty($linkarray['file'])) {
            // 1. Fetch all active online text records for this user in this forum.
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                     WHERE cm = ? AND userid = ? AND storedfileid IS NULL AND status != 'superseded'
                  ORDER BY timecreated DESC";

            $records = $this->db->get_records_sql($sql, [$cmid, $userid]);

            if (!empty($records)) {
                // 2. Determine if this is a standard forum or hsuforum to query the correct Moodle core table.
                $modname = $this->db->get_field_sql(
                    "SELECT m.name FROM {modules} m JOIN {course_modules} cm ON cm.module = m.id WHERE cm.id = ?",
                    [$cmid]
                );
                $posttable = ($modname === 'hsuforum') ? 'hsuforum_posts' : 'forum_posts';

                // 3. Connect the Moodle text payload back to our database record in-memory.
                $postids = [];
                foreach ($records as $record) {
                    if ($record->submissionid > 0) {
                        $postids[] = (int)$record->submissionid;
                    }
                }
                $postids = array_unique($postids);

                $postmessages = [];
                if (!empty($postids)) {
                    $fetchedposts = $this->db->get_records_list($posttable, 'id', $postids, '', 'id, message');
                    foreach ($fetchedposts as $fetchedpost) {
                        $postmessages[$fetchedpost->id] = $fetchedpost->message;
                    }
                }

                foreach ($records as $record) {
                    $postid = (int)$record->submissionid;

                    if ($postid > 0 && isset($postmessages[$postid])) {
                        $postmessage = $postmessages[$postid];

                        // If Moodle's core database text matches the text passed in the hook, we found the exact match!
                        if ($postmessage === $linkarray['content']) {
                            if (
                                $isgrader ||
                                plagiarism_inspera_should_show_report($cmid, $userid, $plagiarismvalues, $record)
                            ) {
                                $output .= $this->formatter->get_originality_status($record, $displaytype);
                            }
                            break; // We found the post, stop searching.
                        }
                    }
                }
            }
        }

        return $output;
    }
}
