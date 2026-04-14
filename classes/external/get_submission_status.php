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
 * External function for getting submission status.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function for getting submission status.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submission_status extends external_api {
    /**
     * Parameters for the execute method.
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid'    => new external_value(PARAM_INT, 'The primary ID of the plagiarism record'),
            'displaytype'  => new external_value(PARAM_ALPHA, 'originality or similarity', VALUE_DEFAULT, 'similarity'),
        ]);
    }

    /**
     * Validate and normalise the display type.
     *
     * @param string $displaytype
     * @return string
     * @throws \invalid_parameter_exception
     */
    private static function normalise_displaytype(string $displaytype): string {
        $displaytype = strtolower($displaytype);
        if (!in_array($displaytype, ['similarity', 'originality'], true)) {
            throw new \invalid_parameter_exception('Invalid displaytype');
        }
        return $displaytype;
    }

    /**
     * Check whether the current user may view this submission status.
     *
     * @param \stdClass $record
     * @param \context_module $context
     * @param \stdClass $cm
     * @return bool
     */
    private static function can_view_submission_status(\stdClass $record, \context_module $context, \stdClass $cm): bool {
        global $USER, $DB, $CFG;
        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

        // 1. Graders have unconditional access to view reports for supported modules only.
        if (!empty($cm->modname)) {
            $gradecapabilities = [
                'assign' => 'mod/assign:grade',
                'quiz' => 'mod/quiz:grade',
                'workshop' => 'mod/workshop:viewallsubmissions',
            ];

            if (isset($gradecapabilities[$cm->modname]) && has_capability($gradecapabilities[$cm->modname], $context)) {
                return true;
            }
        }

        // 2. The submission Owner is subject to the plugin's "shareopt" visibility settings.
        if ((int)$record->userid === (int)$USER->id) {
            // Fetch the plugin settings for this specific course module to check 'shareopt'.
            $settings = $DB->get_records_menu(
                'plagiarism_inspera_config',
                ['cm' => (int)$record->cm],
                '',
                'name, value'
            );

            // Check the legacy report sharing configuration rules.
            return (bool)plagiarism_inspera_should_show_report(
                (int)$record->cm,
                (int)$USER->id,
                $settings ?: [],
                $record
            );
        }

        // 3. Anyone else (non-owner, non-grader) is denied.
        return false;
    }

    /**
     * The core logic: Check DB and return HTML.
     *
     * @param int $recordid
     * @param string $displaytype Defaults to 'similarity' when omitted.
     * @return array
     * @throws \moodle_exception
     */
    public static function execute(int $recordid, string $displaytype = 'similarity') {
        global $DB;

        // 1. Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'displaytype'  => $displaytype,
        ]);

        // Strictly normalise and validate the displaytype.
        $validateddisplaytype = self::normalise_displaytype($params['displaytype']);

        // 2. Get the record FIRST so we know which module context we belong to.
        $record = $DB->get_record('plagiarism_inspera_subs', ['id' => $params['recordid']], '*', MUST_EXIST);

        // 3. Set up the exact Module Context.
        $cm = get_coursemodule_from_id('', $record->cm, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // 4. Security: Validate context and check specific permissions.
        self::validate_context($context);

        if (!self::can_view_submission_status($record, $context, $cm)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        // 5. Format and return.
        $formatter = new \plagiarism_inspera\services\display\report_formatter();
        $html = $formatter->get_originality_status($record, $validateddisplaytype);

        return [
            'status' => $record->status,
            'html'   => $html,
        ];
    }

    /**
     * Define the return structure.
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'The current status (pending, finished, error)'),
            'html'   => new external_value(PARAM_RAW, 'The rendered HTML fragment'),
        ]);
    }
}
