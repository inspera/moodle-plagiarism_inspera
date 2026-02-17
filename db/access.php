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
 * Capability definitions for the Inspera Originality plagiarism plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'plagiarism/inspera:enable' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/inspera:viewreport' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/inspera:resetfile' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/inspera:manage_locked_settings' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'legacy' => array(
            // We leave this EMPTY.
            // By default, Site Admins have all capabilities.
            // Teachers/Managers will NOT have this, so they cannot edit these settings.
        )
    ),
    'plagiarism/inspera:requestallreports' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'legacy' => array(
            // EMPTY
        )
    ),
    'plagiarism/inspera:resubmitonclose' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
);
