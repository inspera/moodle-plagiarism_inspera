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
 * Hook callbacks for the Inspera plagiarism plugin.
 *
 * @package     plagiarism_inspera
 * @copyright   2025 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\output;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use core\hook\output\before_standard_top_of_body_html_generation;
use core\hook\output\before_standard_footer_html_generation;

/**
 * Class hooks
 *
 * Handles output injections for the Inspera plagiarism plugin using the Moodle Hook API.
 */
class hooks {

    /**
     * Callback for before_standard_top_of_body_html_generation hook.
     *
     * Injects the "Resubmit All" button in the assignment grading interface.
     *
     * @param before_standard_top_of_body_html_generation $hook The hook instance.
     * @return void
     */
    public static function add_top_button(before_standard_top_of_body_html_generation $hook): void {
        global $PAGE, $OUTPUT, $DB;

        // 1. Context & Capability Checks.
        if (!$PAGE->context instanceof \context_module) {
            return;
        }
        $cm = $PAGE->cm;
        if (!$cm || $cm->modname !== 'assign') {
            return;
        }

        if (!has_capability('plagiarism/inspera:requestallreports', $PAGE->context)) {
            return;
        }

        // 2. Page Action Check.
        $action = optional_param('action', '', PARAM_ALPHA);
        if ($action !== 'grading' && $action !== 'grader') {
            return;
        }

        // 3. Config Check.
        $use_originality = $DB->get_field('plagiarism_inspera_config', 'value', [
            'cm' => $cm->id,
            'name' => 'use_originality_assign'
        ], IGNORE_MISSING) ?: $DB->get_field('plagiarism_inspera_config', 'value', [
            'cm' => $cm->id,
            'name' => 'use_originality'
        ], IGNORE_MISSING);

        if (empty($use_originality)) {
            return;
        }

        // 4. Determine Info Text.
        $infotext = '';
        $taskqueued = $DB->record_exists_select('task_adhoc',
            "classname = :class AND customdata LIKE :cmid",
            [
                'class' => '\plagiarism_inspera\task\resubmit_all_reports',
                'cmid' => '%"cmid":' . $cm->id . '%'
            ]
        );

        if ($taskqueued) {
            $infotext = html_writer::tag('div',
                get_string('resubmit_pending', 'plagiarism_inspera'),
                ['class' => 'badge badge-info', 'style' => 'display: block; margin-top: 5px; text-align: right;']
            );
        } else {
            $lastrun = $DB->get_field('plagiarism_inspera_config', 'value', ['cm' => $cm->id, 'name' => 'last_resubmit_run']);
            if ($lastrun) {
                $lastrundate = userdate($lastrun, get_string('strftimedatetimeshort', 'langconfig'));
                $infotext = html_writer::tag('div',
                    get_string('last_resubmit_run', 'plagiarism_inspera', $lastrundate),
                    ['style' => 'font-size: 0.8em; color: #666; margin-top: 5px; text-align: right;']
                );
            }
        }

        // 5. Generate Button.
        $url = new moodle_url('/plagiarism/inspera/resubmit_all.php', ['cmid' => $cm->id, 'sesskey' => sesskey()]);
        $attributes = [
            'title' => get_string('resubmit_all_tool_desc', 'plagiarism_inspera'),
            'onclick' => "return confirm('" . get_string('resubmit_confirm', 'plagiarism_inspera') . "');"
        ];
        $button = $OUTPUT->single_button($url, get_string('resubmit_all_tool', 'plagiarism_inspera'), 'post', $attributes);

        // 6. Build Layout.
        // Margin-top is used to clear the fixed Moodle navbar when injected at top of body.
        $combinedhtml = html_writer::start_tag('div', [
            'class' => 'container-fluid d-flex flex-column align-items-end',
            'style' => 'margin-left: -100px; margin-top: 80px; position: relative; pointer-events: auto;'
        ]);
        $combinedhtml .= $button;
        $combinedhtml .= $infotext;
        $combinedhtml .= html_writer::end_tag('div');

        $hook->add_html($combinedhtml);
    }

    /**
     * Callback for before_standard_footer_html_generation hook.
     *
     * Injects JS behavior warnings for group online-text assignments.
     *
     * @param before_standard_footer_html_generation $hook The hook instance.
     * @return void
     */
    public static function add_footer_logic(before_standard_footer_html_generation $hook): void {
        global $PAGE, $DB;

        // 1. Basic Context Check.
        if (!$PAGE->context instanceof \context_module) {
            return;
        }
        $cm = $PAGE->cm;
        if (!$cm || $cm->modname !== 'assign') {
            return;
        }

        // 2. Permission Check.
        if (!has_capability('moodle/course:manageactivities', $PAGE->context)) {
            return;
        }

        // 3. Logic: Check for Group Online Text mismatch.
        $mode = '';
        if (strpos($PAGE->url->get_path(), 'modedit.php') !== false) {
            $mode = 'edit';
        } elseif ($PAGE->pagetype === 'mod-assign-view') {
            // Check if it's a team submission with online text enabled.
            $assignment = $DB->get_record('assign', ['id' => $cm->instance], 'teamsubmission', IGNORE_MISSING);
            if ($assignment && !empty($assignment->teamsubmission)) {
                $select = "assignment = :assignment AND plugin = :plugin AND subtype = :subtype AND name = :name AND " .
                    $DB->sql_compare_text('value') . " = :value";

                $params = [
                    'assignment' => $cm->instance,
                    'plugin'     => 'onlinetext',
                    'subtype'    => 'assignsubmission',
                    'name'       => 'enabled',
                    'value'      => '1'
                ];

                if ($DB->record_exists_select('assign_plugin_config', $select, $params)) {
                    $mode = 'view';
                }
            }
        }

        // 4. Inject JS/HTML if conditions are met.
        if ($mode) {
            // Inject the helper JS.
            $PAGE->requires->js(new moodle_url('/plagiarism/inspera/originality_form_behaviour.js'));

            // Pass data to JS via a hidden div.
            $html = html_writer::tag('div', '', [
                'id' => 'inspera-warning-config',
                'data-mode' => $mode,
                'data-message' => get_string('warning_group_onlinetext', 'plagiarism_inspera'),
                'style' => 'display:none;'
            ]);

            $hook->add_html($html);
        }
    }
}
