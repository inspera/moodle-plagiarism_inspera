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
 * resubmit_all.php - Queues a background task to request originality reports for all submissions in this assignment.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

defined('MOODLE_INTERNAL') || die();

$cmid = required_param('cmid', PARAM_INT); // Course Module ID.

// 1. Basic Setup & Security.
require_login();
$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$context = \context_module::instance($cm->id);

// Check the specific capability we defined.
require_capability('plagiarism/inspera:requestallreports', $context);
require_sesskey(); // Protect against CSRF.

// 2. Queue the Ad-hoc Task.
$task = new \plagiarism_inspera\task\resubmit_all_reports();
$task->set_custom_data(['cmid' => $cmid, 'userid' => $USER->id]);
// Use true to ensure we don't queue multiple identical tasks for the same assignment.
\core\task\manager::queue_adhoc_task($task, true);

// 3. Log the event.
// (Optional: You can trigger a standard Moodle event here if you want audit logs).

// 4. Redirect back to Grading Table with a message.
$redirecturl = new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);
redirect($redirecturl, get_string('resubmit_scheduled', 'plagiarism_inspera'));
