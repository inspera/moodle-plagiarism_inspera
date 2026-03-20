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
