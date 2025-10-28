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

require_once('../../config.php');
require_once($CFG->dirroot.'/plagiarism/originality/lib.php');

use plagiarism_originality\apiclient\api_client;

$id = required_param('id', PARAM_INT);
global $DB, $USER;

$record = $DB->get_record('plagiarism_originality_subs', ['id' => $id], '*', MUST_EXIST);

// Load cm + course
$cm = get_coursemodule_from_id('assign', $record->cm, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// Security
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
if (!has_capability('mod/assign:grade', $context) && $record->userid != $USER->id) {
    print_error('nopermission', 'plagiarism_originality');
}

// Only works if finished + externalid
if ($record->status !== 'finished' || empty($record->externalid)) {
    print_error('reportnotready', 'plagiarism_originality');
}

$client = new api_client();

try {
    $response = $client->get_report_url($record->externalid);

    if (!is_object($response) || empty($response->url)) {
        throw new moodle_exception('errornourl', 'plagiarism_originality', '', null,
            'Originality API did not return a valid URL');
    }

    // Redirect to the report URL
    redirect($response->url);

} catch (\Exception $e) {
    throw new moodle_exception('errornourl', 'plagiarism_originality', '', null,
        'Originality API error: ' . $e->getMessage());
}
