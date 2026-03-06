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
 * Version details for the Inspera Originality plagiarism plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin = new stdClass();
$plugin->component = 'plagiarism_inspera'; // Full name of the plugin in frankenstyle.
$plugin->version   = 2026030100;           // Plugin version (YYYYMMDDXX).
$plugin->requires  = 2024100700;           // Minimum Moodle version (Moodle 4.5 stable).
$plugin->maturity  = MATURITY_ALPHA;       // MATURITY_ALPHA for public directory preview.
$plugin->release   = '2.0.0 Alpha';        // Human-readable version.
