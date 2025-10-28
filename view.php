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
 * Displays the status and metadata of a single plagiarism submission.
 *
 * This page acts as a gateway to the external similarity report.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/plagiarism/originality/lib.php');

$id = required_param('id', PARAM_INT); // ID of the record in plagiarism_originality_subs
global $DB, $USER;

$record = $DB->get_record('plagiarism_originality_subs', ['id' => $id], '*', MUST_EXIST);

// Load course module and course
$cm = get_coursemodule_from_id('assign', $record->cm, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// Context of the module
$context = context_module::instance($cm->id);
require_login($course, true, $cm);

// Permission check:
// Teachers (grade capability) can view all, students only their own
if (!has_capability('mod/assign:grade', $context) && $record->userid != $USER->id) {
    print_error('nopermission', 'plagiarism_originality');
}

// Page setup
$PAGE->set_url('/plagiarism/originality/view.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('originality:viewreport', 'plagiarism_originality'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
//echo $OUTPUT->heading(get_string('originality:viewreport', 'plagiarism_originality'));

// Display submission info
echo html_writer::tag('p', 'Submitted file ID: '.$record->id);
echo html_writer::tag('p', 'Status: '.$record->status);

// Optionally show report data
if ($record->status === 'finished') {
    echo html_writer::tag('ul',
        html_writer::tag('li', get_string('similarity', 'plagiarism_originality') . ': ' . $record->similarity) .
        html_writer::tag('li', get_string('translation_similarity', 'plagiarism_originality') . ': ' . $record->translation_similarity) .
        html_writer::tag('li', get_string('ai_index', 'plagiarism_originality') . ': ' . $record->ai_index) .
        html_writer::tag('li', get_string('originality', 'plagiarism_originality') . ': ' . $record->originality) .
        html_writer::tag('li', get_string('character_replacement', 'plagiarism_originality') . ': ' . $record->character_replacement) .
        html_writer::tag('li', get_string('hidden_text', 'plagiarism_originality') . ': ' . $record->hidden_text) .
        html_writer::tag('li', get_string('image_as_text', 'plagiarism_originality') . ': ' . $record->image_as_text)
    );
}

// Only show report link if finished
if ($record->status === 'finished' && !empty($record->externalid)) {
    // Instead of hardcoding external URL, call our redirect handler
    $redirecturl = new moodle_url('/plagiarism/originality/redirect.php', [
        'id' => $record->id
    ]);

    echo html_writer::link($redirecturl, get_string('viewreport', 'plagiarism_originality'), [
        'class' => 'btn btn-primary',
        'target' => '_blank'
    ]);
}

echo $OUTPUT->footer();
