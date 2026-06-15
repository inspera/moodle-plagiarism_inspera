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
 * Plagiarism Inspera filtering class.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\output;
defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->dirroot . '/user/filters/lib.php');
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * Filtering class for managing plagiarism report filters.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filtering extends \user_filtering {
    /**
     * Builds SQL where conditions for active filters using faceted logic.
     *
     * Repeated values for the same filter field are combined with OR, while
     * different fields are combined with AND.
     *
     * @param string $extra Extra SQL condition to include.
     * @param array|null $params Named parameters associated with $extra.
     * @return array A tuple of [sql, params].
     */
    public function get_sql_filter($extra = '', ?array $params = null) {
        global $SESSION;

        $params = (array)$params;
        $sqlparts = [];

        if ($extra !== '') {
            $sqlparts[] = $extra;
        }

        if (!empty($SESSION->user_filtering[$this->_uniqueid])) {
            $fieldsqlgroups = [];

            foreach ($SESSION->user_filtering[$this->_uniqueid] as $fname => $datas) {
                if (!array_key_exists($fname, $this->_fields)) {
                    continue;
                }

                $field = $this->_fields[$fname];
                foreach ($datas as $data) {
                    [$sql, $sqlparams] = $field->get_sql_filter($data);
                    if ($sql === '') {
                        continue;
                    }

                    $fieldsqlgroups[$fname][] = "($sql)";
                    // Use array_merge to safely combine parameter arrays.
                    $params = array_merge($params, $sqlparams);
                }
            }

            foreach ($fieldsqlgroups as $fieldsqlgroup) {
                if (!empty($fieldsqlgroup)) {
                    $sqlparts[] = '(' . implode(' OR ', $fieldsqlgroup) . ')';
                }
            }
        }

        if (empty($sqlparts)) {
            return ['', []];
        }

        return [implode(' AND ', $sqlparts), $params];
    }

    /**
     * Adds handling for custom fieldnames.
     *
     * @param string $fieldname The name of the field.
     * @param boolean $advanced Whether this is an advanced filter.
     * @return object filter
     */
    public function get_field($fieldname, $advanced) {
        if ($fieldname == 'externalid') {
            return new \user_filter_text(
                'externalid',
                get_string('identifier', 'plagiarism_inspera'),
                $advanced,
                't.externalid'
            );
        }
        if ($fieldname == 'timecreated') {
            return new \user_filter_date('timecreated', get_string('date'), $advanced, 't.timecreated');
        }
        if ($fieldname == 'status') {
            // Fetch statuses from the library.
            $statuses = plagiarism_inspera_statuscodes();
            $errorsonly = (bool)get_config('plagiarism_inspera', 'errorsonlymanagement');
            if ($errorsonly) {
                $allowedstatuses = [
                    'error' => true,
                    'external_error' => true,
                    'fatal_error' => true,
                ];
                $statuses = array_intersect_key($statuses, $allowedstatuses);
            }
            return new \user_filter_simpleselect(
                'status',
                get_string('status', 'plagiarism_inspera'),
                $advanced,
                't.status',
                $statuses
            );
        }
        if ($fieldname == 'course') {
            return new \user_filter_text(
                'course',
                get_string('courseshortname', 'plagiarism_inspera'),
                $advanced,
                'c.shortname'
            );
        }
        if ($fieldname == 'description') {
            return new \user_filter_text(
                'description',
                get_string('description', 'plagiarism_inspera'),
                $advanced,
                't.description'
            );
        }
        return parent::get_field($fieldname, $advanced);
    }
}
