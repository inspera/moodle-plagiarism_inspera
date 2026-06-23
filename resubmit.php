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
 * Handles inline single resubmit action from grading tables.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

global $CFG, $DB;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \moodle_exception('invalidrequest', 'error');
}

$id = required_param('id', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);
$returnurl = required_param('returnurl', PARAM_LOCALURL);

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// 1. Authenticate the user first (allows graceful login redirect if timed out).
require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// 2. Validate structural capabilities.
$gradecapabilities = plagiarism_inspera_get_grade_capabilities();
if (!isset($gradecapabilities[$cm->modname])) {
    throw new moodle_exception('nopermissions', 'error');
}
require_capability($gradecapabilities[$cm->modname], $context);
require_capability('plagiarism/inspera:requestallreports', $context);

// 3. Protect against CSRF right before processing state-changing actions.
require_sesskey();

// Ensure the record being resubmitted belongs to this course module.
// We fetch the full record ('*') once to avoid redundant database reads in the recovery service.
$record = $DB->get_record('plagiarism_inspera_subs', ['id' => $id], '*', IGNORE_MISSING);
if (!$record || (int)$record->cm !== $cmid) {
    \core\notification::error(get_string('resubmit_single_not_found', 'plagiarism_inspera'));
    redirect(new \moodle_url($returnurl));
}

if (!in_array($record->status, ['error', 'external_error'], true)) {
    \core\notification::error(get_string('resubmitnoteligible', 'plagiarism_inspera'));
    redirect(new \moodle_url($returnurl));
}

$client = new \plagiarism_inspera\apiclient\api_client();
$recoveryservice = new \plagiarism_inspera\services\resubmission_recovery_service($DB);
$outcome = $recoveryservice->resubmit_record($record, $client);

switch ($outcome) {
    case 'recovered':
        \core\notification::success(get_string('resubmit_single_recovered', 'plagiarism_inspera'));
        break;
    case 'queued':
        \core\notification::success(get_string('resubmit_single_queued', 'plagiarism_inspera'));
        break;
    case 'api_error':
        \core\notification::error(get_string('resubmit_single_api_error', 'plagiarism_inspera'));
        break;
    case 'skipped':
        \core\notification::warning(get_string('resubmit_single_skipped', 'plagiarism_inspera'));
        break;
    case 'not_eligible':
    default:
        \core\notification::error(get_string('resubmitnoteligible', 'plagiarism_inspera'));
        break;
}

redirect(new moodle_url($returnurl));
