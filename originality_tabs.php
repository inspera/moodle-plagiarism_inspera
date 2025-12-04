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
 * This script prints the navigation tabs for the plugin's admin settings pages.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$strsettings = get_string('tab_settings', 'plagiarism_originality');
$strdefaults = get_string('tab_defaults', 'plagiarism_originality');
$strmanagement = get_string('tab_management', 'plagiarism_originality');

$tabs = array();
$tabs[] = new tabobject('originalitysettings', 'settings.php', $strsettings, $strsettings, false);
$tabs[] = new tabobject('originalitydefaults', 'originality_defaults.php', $strdefaults, $strdefaults, false);
$tabs[] = new tabobject('originalitydebug', 'originality_debug.php', $strmanagement, $strmanagement, false);

print_tabs(array($tabs), $currenttab);
