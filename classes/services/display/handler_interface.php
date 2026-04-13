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
 * Handles the display of Inspera originality reports.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services\display;

defined('MOODLE_INTERNAL') || die();

/**
 * Interface for plagiarism display handlers.
 *
 * Defines the contract for module-specific handlers responsible for
 * generating the HTML status and report links for Inspera.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface handler_interface {
    /**
     * Generates the HTML report links for a specific module type.
     *
     * @param array $linkarray The Moodle link data.
     * @param array $plagiarismvalues The plugin configuration for this activity.
     * @param bool $isgrader Whether the current user is a grader for this activity.
     * @return string HTML output to display.
     */
    public function get_links(array $linkarray, array $plagiarismvalues, bool $isgrader): string;
}
