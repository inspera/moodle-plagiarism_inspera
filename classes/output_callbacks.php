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
 * Hook callbacks for the Inspera Originality plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use function plagiarism_inspera\get_string;
use function plagiarism_inspera\has_capability;
use function plagiarism_inspera\optional_param;
use function plagiarism_inspera\sesskey;
use function plagiarism_inspera\userdate;
use const plagiarism_inspera\CONTEXT_MODULE;
use const plagiarism_inspera\IGNORE_MISSING;

/**
 * Output callbacks class to handle Moodle hooks.
 */
class output_callbacks {

    /**
     * Replaces plagiarism_inspera_before_standard_top_of_body_html.
     * Injects the "Resubmit All" button into the page header.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function before_top_of_body(\core\hook\output\before_standard_top_of_body_html_generation $hook): void {
        debugging('INSIDE THE HOOK', DEBUG_DEVELOPER);
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

        // 4. Determine Info Text (Pending vs Last Run).
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
        $combinedhtml = html_writer::start_tag('div', ['class' => 'd-flex flex-column align-items-end']);
        $combinedhtml .= $button;
        $combinedhtml .= $infotext;
        $combinedhtml .= html_writer::end_tag('div');

        $hook->add_html($combinedhtml);
    }

    /**
     * Replaces plagiarism_inspera_standard_footer_html.
     * Injects warning configuration for Online Text group submissions.
     *
     * @param \core\hook\output\before_standard_footer_html_generation $hook
     */
    public static function before_footer(\core\hook\output\before_standard_footer_html_generation $hook): void {
        global $PAGE, $DB;

        if ($PAGE->context->contextlevel != CONTEXT_MODULE) return;
        $cm = $PAGE->cm;
        if (!$cm || $cm->modname !== 'assign') return;
        if (!has_capability('moodle/course:manageactivities', $PAGE->context)) return;

        // Check if enabled.
        $use_originality = $DB->get_field('plagiarism_inspera_config', 'value', ['cm' => $cm->id, 'name' => 'use_originality_assign'], IGNORE_MISSING)
            ?: $DB->get_field('plagiarism_inspera_config', 'value', ['cm' => $cm->id, 'name' => 'use_originality'], IGNORE_MISSING);

        if (!$use_originality) return;

        $mode = '';
        if (strpos($PAGE->url->get_path(), 'modedit.php') !== false) {
            $mode = 'edit';
        } elseif ($PAGE->pagetype === 'mod-assign-view') {
            $assignment = $DB->get_record('assign', ['id' => $cm->instance], 'teamsubmission', IGNORE_MISSING);
            if ($assignment && !empty($assignment->teamsubmission)) {
                $compare_val = $DB->sql_compare_text('value', 2);
                $sql = "SELECT 1 FROM {assign_plugin_config} WHERE assignment = ? AND plugin = ? AND subtype = ? AND name = ? AND $compare_val = ?";
                if ($DB->record_exists_sql($sql, [$cm->instance, 'onlinetext', 'assignsubmission', 'enabled', '1'])) {
                    $mode = 'view';
                }
            }
        }

        if ($mode) {
            $PAGE->requires->js(new moodle_url('/plagiarism/inspera/originality_form_behaviour.js'));
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
