<?php
/**
 * Plagiarism Inspera filtering class.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\output;

defined('MOODLE_INTERNAL') || die();

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
        global $CFG;

        // Ensure necessary libraries are loaded.
        require_once($CFG->dirroot . '/user/filters/lib.php');
        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

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
