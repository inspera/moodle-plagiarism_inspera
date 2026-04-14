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
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
global $CFG, $PAGE, $OUTPUT;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

use plagiarism_inspera\apiclient\api_client;

$id = required_param('id', PARAM_INT);
// Optional return URL so we can show inline notifications on the originating page.
$returnurlparam = optional_param('returnurl', '', PARAM_LOCALURL);
global $DB, $USER;

$record = $DB->get_record('plagiarism_inspera_subs', ['id' => $id], '*', MUST_EXIST);

// 2. Load CM and Determine Module Type.
// We pass false for the module name so Moodle loads it regardless of type (assign or quiz).
$cm = get_coursemodule_from_id('', $record->cm, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// 3. Security & Context.
$context = context_module::instance($cm->id);
require_login($course, true, $cm);

// 4. Determine Capability based on Module.
$modulename = $cm->modname;
$isgrader = false;

// We use the same secure capability map established in get_submission_status.
$gradecapabilities = [
    'assign'   => 'mod/assign:grade',
    'quiz'     => 'mod/quiz:grade',
    'workshop' => 'mod/workshop:viewallsubmissions',
];

if (isset($gradecapabilities[$modulename])) {
    $isgrader = has_capability($gradecapabilities[$modulename], $context);
} else {
    // SECURITY GUARD: Reject any unsupported module types immediately.
    throw new moodle_exception('error', 'error', '', null, 'Unsupported module type: ' . s($modulename));
}

// Access Control: Graders have unconditional access.
if (!$isgrader) {
    // 1. Non-graders MUST be the owner of the submission.
    if ((int)$record->userid !== (int)$USER->id) {
        throw new moodle_exception('nopermissions', 'error');
    }

    // 2. The plugin settings for this specific activity must permit the student to view it right now.
    $settings = $DB->get_records_menu(
        'plagiarism_inspera_config',
        ['cm' => (int)$record->cm],
        '',
        'name, value'
    );

    if (!plagiarism_inspera_should_show_report((int)$record->cm, (int)$USER->id, $settings ?: [], $record)) {
        throw new moodle_exception('nopermissions', 'error');
    }
}

// Prepare page (used for graceful error rendering below).
$PAGE->set_url(new moodle_url('/plagiarism/inspera/redirect.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'plagiarism_inspera'));
$PAGE->set_pagelayout('standard');

// 6. Determine Return URL (Fallback if not provided).
if (!empty($returnurlparam)) {
    $returnurl = new moodle_url($returnurlparam);
} else {
    // Generate intelligent fallbacks based on module type.
    if ($modulename === 'quiz') {
        if ($isgrader) {
            $returnurl = new moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'overview']);
        } else {
            $returnurl = new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
        }
    } else if ($modulename === 'assign') {
        if ($isgrader) {
            $returnurl = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);
        } else {
            $returnurl = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'editsubmission']);
        }
    } else if ($modulename === 'workshop') {
        $returnurl = new moodle_url('/mod/workshop/view.php', ['id' => $cm->id]);
    } else {
        $returnurl = new moodle_url('/course/view.php', ['id' => $cm->course]);
    }
}

// Helper to render a non-redirecting message page and exit.
$rendererrorandexit = function (string $message, moodle_url $continueurl) use ($OUTPUT) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
    // Provide a continue button back to the originating page (or fallback).
    echo $OUTPUT->continue_button($continueurl);
    echo $OUTPUT->footer();
    exit;
};

// Only works if finished + externalid. Show message page without redirecting.
if ($record->status !== 'finished' || empty($record->externalid)) {
    $rendererrorandexit(get_string('notavailableyet'), $returnurl);
}

$client = new api_client();

// Determine Mode: Graders get "edit", Students get "view".
$mode = $isgrader ? 'edit' : 'view';

try {
    $response = $client->get_report_url($record->externalid, $mode);

    if (!is_object($response) || empty($response->url)) {
        // Show a friendly error on a message page (no redirect loop).
        $rendererrorandexit(get_string('reportaccessdenied', 'plagiarism_inspera'), $returnurl);
    }

    // Redirect to the report URL.
    redirect($response->url);
} catch (\Exception $e) {
    // Display a friendly message page and offer a continue button back.
    // Do NOT append raw exception text to the user-facing message.
    $rendererrorandexit(get_string('reportaccessdenied', 'plagiarism_inspera'), $returnurl);
}
