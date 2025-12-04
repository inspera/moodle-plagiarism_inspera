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
 * Debug table
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\output;

use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Table to display list of submissions.
 */
class debug_table extends \table_sql {

    /**
     * @var array stores cached activity name.
     */
    public $activitynames = array();

    /**
     * Constructor
     * @param int $uniqueid all tables have to have a unique id.
     */
    public function __construct($uniqueid) {
        global $OUTPUT, $PAGE;
        parent::__construct($uniqueid);

        $url = $PAGE->url;
        $this->define_baseurl($url);

        // Set Download flag.
        $this->is_downloading(optional_param('download', '', PARAM_ALPHA), 'OriginalityDebugOutput');

        // Define the list of columns to show.
        $columns = array();
        $headers = array();

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

        // Standard columns
        $columns = array_merge($columns, array('id', 'fullname', 'course', 'activity', 'externalid', 'status', 'timecreated'));

        $headers = array_merge($headers, array(
            get_string('id', 'plagiarism_originality'),
            get_string('user'),
            get_string('course'),
            get_string('activity'),
            get_string('identifier', 'plagiarism_originality'), // Maps to externalid
            get_string('status', 'plagiarism_originality'),
            get_string('timecreated', 'plagiarism_originality')
        ));

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

        $this->initialbars(false);
    }

    /**
     * Function to display the checkbox for bulk actions.
     */
    public function col_selector($row) {
        global $OUTPUT;
        if ($this->is_downloading()) {
            return '';
        }
        $options = [
            'id' => 'item'.$row->id,
            'name' => 'item'.$row->id,
            'value' => $row->id,
        ];
        $itemcheckbox = new \core\output\checkbox_toggleall('items', false, $options);
        return $OUTPUT->render($itemcheckbox);
    }

    /**
     * Action buttons. Adapted for Originality status flow.
     */
    public function col_action($row) {
        $output = '';

        // 1. Reset / Resubmit Button
        // If status is Error, or Finished, or stuck in Pending/Request for too long
        if ($row->status == 'error' || $row->status == 'external_error') {
            $url = new moodle_url('/plagiarism/originality/originality_debug.php',
                array('id' => $row->id, 'action' => 'resubmit', 'sesskey' => sesskey()));
            $output .= html_writer::link($url, get_string('resubmit', 'plagiarism_originality')). ' | ';
        }

        // 2. Delete Button
        $url = new moodle_url('/plagiarism/originality/originality_debug.php',
            array('id' => $row->id, 'action' => 'delete', 'sesskey' => sesskey()));
        $output .= html_writer::link($url, get_string('delete'));

        return $output;
    }

    /**
     * Display Activity Name.
     */
    public function col_activity($row) {
        // NOTE: The SQL query must select 'cm.id as cm' and 'm.name as moduletype'
        if ($this->is_downloading()) {
            return $row->moduletype . ' ' . $row->cm;
        }

        // Fix: Use $this->activitynames
        if (!empty($this->activitynames[$row->cm])) {
            $coursemodulename = $this->activitynames[$row->cm];
        } else {
            // Note: This relies on the SQL joining course_modules
            $coursemodule = get_coursemodule_from_id($row->moduletype, $row->cm);
            if ($coursemodule) {
                $coursemodulename = $coursemodule->name;
                $this->activitynames[$row->cm] = $coursemodule->name;
            } else {
                return '-';
            }
        }

        $cmurl = new moodle_url('/mod/'.$row->moduletype.'/view.php', array('id' => $row->cm));
        return html_writer::link($cmurl, shorten_text($coursemodulename, 40, true), array('title' => $coursemodulename));
    }

    public function col_timesubmitted($row) {
        return userdate($row->timecreated); // Changed from timesubmitted to timecreated based on install.xml
    }

    public function col_status($row) {
        // Display nice status string if it exists
        $statuskey = 'status_' . $row->status;
        if (get_string_manager()->string_exists($statuskey, 'plagiarism_originality')) {
            return get_string($statuskey, 'plagiarism_originality');
        }
        return $row->status;
    }

    public function col_course($row) {
        if (empty($row->courseid)) {
            return '';
        }
        if ($this->is_downloading()) {
            return $row->shortname;
        }
        return \html_writer::link(new \moodle_url('/course/view.php', array('id' => $row->courseid)), $row->shortname);
    }

    /**
     * Finish output - add extra debug info to export.
     */
    public function finish_output($closeexportclassdoc = true) {
        global $DB;
        if ($this->is_downloading()) {
            $this->add_data(array());
            $this->add_data(array());

            // Dump the configuration table (Fixed table name)
            $configrecords = $DB->get_records('plagiarism_originality_conf');
            $this->add_data(array('id', 'cm', 'name', 'value'));
            foreach ($configrecords as $cf) {
                $this->add_data(array($cf->id, $cf->cm, $cf->name, $cf->value));
            }
        }
        parent::finish_output($closeexportclassdoc);
    }
}
