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
 * Debug table for Plagiarism Inspera.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\output;

use moodle_url;
use html_writer;

/**
 * Table to display list of submissions.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_table extends \table_sql {
    /**
     * @var array stores cached activity name.
     */
    public $activitynames = [];

    /**
     * Constructor.
     *
     * @param int $uniqueid All tables have to have a unique id.
     */
    public function __construct($uniqueid) {
        global $OUTPUT, $PAGE;
        parent::__construct($uniqueid);

        $url = $PAGE->url;
        $this->define_baseurl($url);

        // Set Download flag.
        $this->is_downloading(optional_param('download', '', PARAM_ALPHA), 'OriginalityDebugOutput');

        // Define the list of columns to show.
        $columns = [];
        $headers = [];

        // Add selector column if not downloading report.
        if (!$this->is_downloading()) {
            $columns[] = 'selector';
            $options = [
                'id' => 'check-items',
                'name' => 'check-items',
                'value' => 1,
            ];
            $mastercheckbox = new \core\output\checkbox_toggleall('items', true, $options);
            $headers[] = $OUTPUT->render($mastercheckbox);
        }

        // Standard columns.
        $columns = array_merge($columns, [
            'id', 'fullname', 'course', 'activity', 'externalid', 'status', 'description', 'timecreated',
        ]);

        $headers = array_merge($headers, [
            get_string('id', 'plagiarism_inspera'),
            get_string('user'),
            get_string('course'),
            get_string('activity'),
            get_string('identifier', 'plagiarism_inspera'), // Maps to externalid.
            get_string('status', 'plagiarism_inspera'),
            get_string('description', 'plagiarism_inspera'),
            get_string('timecreated', 'plagiarism_inspera'),
        ]);

        // Add actions column if not downloading.
        if (!$this->is_downloading()) {
            $columns[] = 'action';
            $headers[] = get_string('action');
        }

        $this->define_columns($columns);
        $this->define_headers($headers);

        $this->no_sorting('action');
        $this->no_sorting('activity');
        $this->no_sorting('selector');
        $this->no_sorting('description');

        // Default sorting: show most recent submissions first by timecreated.
        $this->sortable(true, 'timecreated', SORT_DESC);

        $this->initialbars(false);
    }

    /**
     * Function to display the checkbox for bulk actions.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_selector($row) {
        global $OUTPUT;
        if ($this->is_downloading()) {
            return '';
        }
        $options = [
            'id' => 'item' . $row->id,
            'name' => 'item' . $row->id,
            'value' => $row->id,
        ];
        $itemcheckbox = new \core\output\checkbox_toggleall('items', false, $options);
        return $OUTPUT->render($itemcheckbox);
    }

    /**
     * Action buttons. Adapted for Originality status flow.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_action($row) {
        $output = '';

        // 1. Reset / Resubmit Button.
        // If status is Error, or Finished, or stuck in Pending/Request for too long.
        if ($row->status == 'error' || $row->status == 'external_error') {
            $url = new moodle_url(
                '/plagiarism/inspera/originality_debug.php',
                ['id' => $row->id, 'action' => 'resubmit', 'sesskey' => sesskey()]
            );
            $tooltip = get_string('resubmit_tooltip', 'plagiarism_inspera');
            $output .= html_writer::link($url, get_string('resubmit', 'plagiarism_inspera'), ['title' => $tooltip]);
            $output .= ' | ';
        }

        // 2. Delete Button.
        $url = new moodle_url(
            '/plagiarism/inspera/originality_debug.php',
            ['id' => $row->id, 'action' => 'delete', 'sesskey' => sesskey()]
        );
        $output .= html_writer::link($url, get_string('delete'));

        return $output;
    }

    /**
     * Display Activity Name.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_activity($row) {
        // 1. Check local cache (Avoids re-querying the same assignment).
        if (isset($this->activitynames[$row->cm])) {
            $coursemodulename = $this->activitynames[$row->cm];
        } else {
            // 2. Fetch from DB.
            $coursemodule = false;

            // Check if we even have valid IDs.
            if (!empty($row->cm) && !empty($row->moduletype)) {
                // We use get_coursemodule_from_id which is relatively efficient.
                $coursemodule = get_coursemodule_from_id($row->moduletype, $row->cm);
            }

            if ($coursemodule) {
                $coursemodulename = $coursemodule->name;
            } else {
                // Fallback for deleted activities.
                $coursemodulename = '-';
            }

            // Save to cache.
            $this->activitynames[$row->cm] = $coursemodulename;
        }

        // 3. Render.
        if ($coursemodulename === '-') {
            return html_writer::tag('span', 'Deleted Activity', ['class' => 'badge badge-warning']);
        }

        $cmurl = new moodle_url('/mod/' . $row->moduletype . '/view.php', ['id' => $row->cm]);
        return html_writer::link($cmurl, shorten_text($coursemodulename, 40, true), ['title' => $coursemodulename]);
    }

    /**
     * Format the timecreated column.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_timecreated($row) {
        // Always display as dd/mm/yyyy hh:mm ss in the viewer's timezone.
        return userdate($row->timecreated, '%d/%m/%Y %H:%M', 99);
    }

    /**
     * Format the status column.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_status($row) {
        // Display nice status string if it exists.
        $statuskey = 'status_' . $row->status;
        if (get_string_manager()->string_exists($statuskey, 'plagiarism_inspera')) {
            return get_string($statuskey, 'plagiarism_inspera');
        }
        return $row->status;
    }

    /**
     * Show error description parsed from IO or Moodle messages.
     * Displays a shortened preview with full text as title tooltip.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_description($row) {
        $desc = isset($row->description) ? trim((string)$row->description) : '';
        if ($desc === '') {
            return '';
        }
        // Limit display length for table view.
        $short = shorten_text($desc, 120);
        if ($this->is_downloading()) {
            return $desc;
        }
        return html_writer::tag('span', s($short), ['title' => $desc]);
    }

    /**
     * Format the course column.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_course($row) {
        if (empty($row->courseid)) {
            return '';
        }
        if ($this->is_downloading()) {
            return $row->shortname;
        }
        return \html_writer::link(new \moodle_url('/course/view.php', ['id' => $row->courseid]), $row->shortname);
    }
}
