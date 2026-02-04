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
 * Fetches a secure, temporary report URL from the Originality API
 * and redirects the user to it.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Your Name (Your Company)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
global $CFG, $PAGE, $OUTPUT;
require_once($CFG->dirroot.'/plagiarism/inspera/lib.php');

use plagiarism_inspera\apiclient\api_client;

$id = required_param('id', PARAM_INT);
// Optional return URL so we can show inline notifications on the originating page.
$returnurlparam = optional_param('returnurl', '', PARAM_LOCALURL);
global $DB, $USER;

$record = $DB->get_record('plagiarism_inspera_subs', ['id' => $id], '*', MUST_EXIST);

// Load cm + course
$cm = get_coursemodule_from_id('assign', $record->cm, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// Security
$context = context_module::instance($cm->id);
require_login($course, true, $cm);

$is_grader = has_capability('mod/assign:grade', $context);

// Access Control: You must be a grader OR the owner of the submission
if (!$is_grader && $record->userid != $USER->id) {
    print_error('nopermission', 'plagiarism_inspera');
}

// Prepare page (used for graceful error rendering below).
$PAGE->set_url(new moodle_url('/plagiarism/inspera/redirect.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'plagiarism_inspera'));
$PAGE->set_pagelayout('standard');

// Work out where to return on error. Prefer explicitly provided returnurl.
// Otherwise, send teachers to the grading (Submissions) tab and students to their submission page.
$returnurl = !empty($returnurlparam)
    ? new moodle_url($returnurlparam)
    : (has_capability('mod/assign:grade', $context)
        ? new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading'])
        : new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'editsubmission']));

// Helper to render a non-redirecting message page and exit.
$render_error_and_exit = function(string $message, moodle_url $continueurl) use ($OUTPUT) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
    // Provide a continue button back to the originating page (or fallback).
    echo $OUTPUT->continue_button($continueurl);
    echo $OUTPUT->footer();
    exit;
};

// Only works if finished + externalid. Show message page without redirecting.
if ($record->status !== 'finished' || empty($record->externalid)) {
    $render_error_and_exit(get_string('notavailableyet'), $returnurl);
}

$client = new api_client();

// Determine Mode: Graders get "edit", Students get "view"
$mode = $is_grader ? 'edit' : 'view';

try {
    $response = $client->get_report_url($record->externalid, $mode);

    if (!is_object($response) || empty($response->url)) {
        // Show a friendly error on a message page (no redirect loop).
        $render_error_and_exit(get_string('reportaccessdenied', 'plagiarism_inspera'), $returnurl);
    }

    // Redirect to the report URL
    redirect($response->url);

} catch (\Exception $e) {
    // Display a friendly message page and offer a continue button back.
    // Do NOT append raw exception text to the user-facing message.
    $render_error_and_exit(get_string('reportaccessdenied', 'plagiarism_inspera'), $returnurl);
}
