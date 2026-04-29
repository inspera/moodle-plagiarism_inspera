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
            global $PAGE;

            // 1. Force Moodle to load the Select All javascript module.
            $PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');

            $columns[] = 'selector';

            // 2. Build the master checkbox.
            $masterinput = \html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'check-items',
                'id' => 'check-items',
                'value' => 1,
                'data-action' => 'toggle',
                'data-toggle' => 'master',
                'data-togglegroup' => 'items',
                'class' => 'form-check-input',
                'form' => 'debugform',
            ]);

            // 3. Build the label text.
            $masterlabel = \html_writer::tag('label', get_string('selectall'), [
                'for' => 'check-items',
                'class' => 'form-check-label ms-1',
            ]);

            // 4. Combine them for the header.
            $headers[] = $masterinput . $masterlabel;
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
        // Use Moodle's html_writer to safely generate the tag with custom attributes.
        return \html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'item' . $row->id,
            'id' => 'item' . $row->id,
            'value' => $row->id,
            'data-action' => 'toggle',
            'data-toggle' => 'slave',
            'data-togglegroup' => 'items',
            'class' => 'form-check-input',
            'form' => 'debugform',
        ]);
    }

    /**
     * Action buttons. Adapted for Originality status flow to use POST forms.
     *
     * @param \stdClass $row The row data.
     * @return string
     */
    public function col_action($row) {
        if ($this->is_downloading()) {
            return '';
        }

        $output = html_writer::start_div('d-flex justify-content-start align-items-center');

        // 1. Reset / Resubmit Button.
        if ($row->status == 'error' || $row->status == 'external_error') {
            $output .= $this->render_post_action(
                $row->id,
                'resubmit',
                't/reload', // Moodle reload icon.
                'resubmit',
                'resubmitcheck'
            );
            $output .= html_writer::span('|', 'mx-2 text-muted');
        }

        // 2. Delete Button.
        $output .= $this->render_post_action(
            $row->id,
            'delete',
            't/delete', // Moodle delete icon.
            'delete',
            'deletecheck'
        );

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Helper to render a small POST form for row-level actions.
     *
     * @param int $id The record ID.
     * @param string $action The action to perform (delete/resubmit).
     * @param string $icon The Moodle pix icon key.
     * @param string $labelstr The lang string key for the label/alt text.
     * @param string $confirmstr The lang string key for the JS confirmation message.
     * @return string HTML form.
     */
    protected function render_post_action($id, $action, $icon, $labelstr, $confirmstr = '') {
        global $OUTPUT;

        $formhtml = html_writer::start_tag('form', [
            'action' => new moodle_url('/plagiarism/inspera/originality_debug.php'),
            'method' => 'post',
            'class' => 'm-0 p-0',
        ]);

        // Hidden fields for POST data.
        $formhtml .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
        $formhtml .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
        $formhtml .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        $label = get_string($labelstr, 'plagiarism_inspera');
        $attrs = [
            'type' => 'submit',
            'class' => 'btn btn-link p-0 m-0',
            'title' => $label,
        ];

        // Add JS confirmation if a string key was provided.
        if ($confirmstr) {
            $confirmlabel = get_string($confirmstr, 'plagiarism_inspera');
            $attrs['onclick'] = "return confirm('" . addslashes_js($confirmlabel) . "');";
        }

        $formhtml .= html_writer::tag('button', $OUTPUT->pix_icon($icon, $label), $attrs);
        $formhtml .= html_writer::end_tag('form');

        return $formhtml;
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
