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
use plagiarism_inspera\services\display\report_formatter;

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
     * * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'The ID of the plagiarism record'),
            'displaytype'  => new external_value(PARAM_TEXT, 'originality or similarity', VALUE_DEFAULT, 'similarity'),
        ]);
    }

    /**
     * The core logic: Check DB and return HTML.
     * * @param int $submissionid
     * @param string $displaytype
     * @return array
     */
    public static function execute(int $submissionid, string $displaytype) {
        global $DB;

        // 1. Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'submissionid' => $submissionid,
            'displaytype'  => $displaytype,
        ]);

        // 2. Security: Ensure the user is logged in.
        $context = \context_system::instance();
        self::validate_context($context);

        // 3. Get the record.
        $record = $DB->get_record('plagiarism_inspera_subs', ['id' => $params['submissionid']], '*', MUST_EXIST);

        // 4. Format.
        $formatter = new report_formatter();
        $html = $formatter->get_originality_status($record, $params['displaytype']);

        return [
            'status' => $record->status,
            'html'   => $html,
        ];
    }

    /**
     * Define the return structure.
     * * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'The current status (pending, finished, error)'),
            'html'   => new external_value(PARAM_RAW, 'The rendered HTML fragment'),
        ]);
    }
}
