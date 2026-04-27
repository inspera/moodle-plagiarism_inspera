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
 * Debug tool for Inspera Originality.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Initialize Moodle.
require_once(__DIR__ . '/../../config.php');

// Security Check (The Shield).
defined('MOODLE_INTERNAL') || die();

// Include necessary libraries.
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

global $OUTPUT, $CFG, $PAGE, $DB, $SITE;

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA); // Unified action param.
$resubmitselected = optional_param('resubmitselectedfiles', 0, PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$deleteselected = optional_param('deleteselectedfiles', 0, PARAM_TEXT);
$fileids = optional_param('fileids', '', PARAM_TEXT);

require_login();

$url = new moodle_url('/plagiarism/inspera/originality_debug.php');
$PAGE->set_url($url);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('originalitydebug', 'plagiarism_inspera'));

if (!empty($SITE)) {
    $PAGE->set_heading(format_string($SITE->fullname));
}
require_capability('moodle/site:config', $context);

$exportfilename = 'OriginalityDebugOutput.csv';

$limit = 50;
// ADDED: 'status' => 0 brings the filter back to the UI dropdown.
$filters = ['status' => 0, 'realname' => 0, 'timecreated' => 0, 'course' => 0, 'externalid' => 0, 'description' => 0];
$ufiltering = new \plagiarism_inspera\output\filtering($filters, $PAGE->url);
[$ufextrasql, $ufparams] = $ufiltering->get_sql_filter();

// SMART TOGGLE LOGIC.
$showall = optional_param('showall', 0, PARAM_INT);
if ($showall !== 0) {
    require_sesskey();
    // Save preference: 1 = Show All, any other non-zero value = Show Errors Only.
    set_user_preference('plagiarism_inspera_debug_showall', $showall == 1 ? 1 : 0);
    // 1. Create a fresh URL object based on the current page.
    $redirecturl = new moodle_url($PAGE->url);

    // 2. Remove parameters one by one as strings.
    $redirecturl->remove_params('showall');
    $redirecturl->remove_params('sesskey');

    // 3. Perform the redirect.
    redirect($redirecturl);
}
// Read the user's saved preference (Defaults to 0 / Errors Only).
$prefshowall = get_user_preferences('plagiarism_inspera_debug_showall', 0);

$defaultstatusapplied = false;
// Apply the error filter ONLY if no custom filters are set AND the user hasn't toggled "Show All".
if (empty($ufextrasql) && !$prefshowall) {
    $ufextrasql = "t.status IN (:defaultstatus1, :defaultstatus2)";
    $ufparams['defaultstatus1'] = 'error';
    $ufparams['defaultstatus2'] = 'external_error';
    $defaultstatusapplied = true;
}


$plagiarismsettings = plagiarism_plugin_inspera::get_settings();

// 1. HANDLE BULK DELETE (Selected via Checkboxes).
if (!empty($deleteselected)) {
    if (empty($fileids)) {
        $fileids = [];
        // Check for checkbox data.
        $post = data_submitted();
        foreach ($post as $k => $v) {
            if (preg_match('/^item(\d+)$/', $k, $m)) {
                $fileids[] = $m[1];
            }
        }

        if (empty($fileids)) {
            redirect($url, get_string('nofilesselected', 'plagiarism_inspera'));
        }

        // Display confirmation box.
        $params = ['deleteselectedfiles' => 1, 'confirm' => 1, 'fileids' => implode(',', $fileids)];
        $deleteurl = new moodle_url($PAGE->url, $params);
        $numfiles = count($fileids);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('areyousurebulk', 'plagiarism_inspera', $numfiles),
            $deleteurl,
            $CFG->wwwroot . '/plagiarism/inspera/originality_debug.php'
        );

        echo $OUTPUT->footer();
        exit;
    } else if ($confirm && confirm_sesskey()) {
        $count = 0;
        $fileids = explode(',', $fileids);
        foreach ($fileids as $fid) {
            $DB->delete_records('plagiarism_inspera_subs', ['id' => $fid]);
            $count++;
        }
        \core\notification::success(get_string('recordsdeleted', 'plagiarism_inspera', $count));
    }
} else if (!empty($resubmitselected)) {
    // 2. HANDLE BULK RESUBMIT (Selected via Checkboxes).
    if (empty($fileids)) {
        $fileids = [];
        // Check for checkbox data.
        $post = data_submitted();
        foreach ($post as $k => $v) {
            if (preg_match('/^item(\d+)$/', $k, $m)) {
                $fileids[] = $m[1];
            }
        }

        if (empty($fileids)) {
            redirect($url, get_string('nofilesselected', 'plagiarism_inspera'));
        }

        // Display confirmation box for resubmit selected.
        $params = ['resubmitselectedfiles' => 1, 'confirm' => 1, 'fileids' => implode(',', $fileids)];
        $resubmiturl = new moodle_url($PAGE->url, $params);
        $numfiles = count($fileids);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('areyousurebulkresubmit', 'plagiarism_inspera', $numfiles),
            $resubmiturl,
            $CFG->wwwroot . '/plagiarism/inspera/originality_debug.php'
        );

        echo $OUTPUT->footer();
        exit;
    } else if ($confirm && confirm_sesskey()) {
        $fileids = explode(',', $fileids);
        // Update selected records for resubmission.
        [$insql, $inparams] = $DB->get_in_or_equal($fileids, SQL_PARAMS_NAMED);
        $params = array_merge([
            'status' => 'report_requested',
            'modtime' => time(),
        ], $inparams);

        $sql = "UPDATE {plagiarism_inspera_subs}
                SET status = :status,
                    timemodified = :modtime,
                    similarity = NULL,
                    translation_similarity = NULL,
                    ai_index = NULL,
                    originality = NULL,
                    character_replacement = NULL,
                    hidden_text = NULL,
                    image_as_text = NULL,
                    externalid = NULL,
                    description = NULL
                WHERE id $insql";

        $DB->execute($sql, $params);
        \core\notification::success(get_string('filesresubmitted', 'plagiarism_inspera', count($fileids)));
    }
}

// 3. HANDLE SINGLE ACTIONS (Row Links).
if ($id && confirm_sesskey()) {
    if ($action === 'resubmit') {
        // Reset single file.
        $record = new stdClass();
        $record->id = $id;
        $record->status = 'report_requested';
        $record->timemodified = time();
        // Clear scores.
        $record->similarity = null;
        $record->translation_similarity = null;
        $record->ai_index = null;
        $record->originality = null;
        $record->character_replacement = null;
        $record->hidden_text = null;
        $record->image_as_text = null;
        $record->externalid = null;
        $record->description = null;

        $DB->update_record('plagiarism_inspera_subs', $record);
        \core\notification::success(get_string('fileresubmitted', 'plagiarism_inspera'));
    } else if ($action === 'delete' || !empty($delete)) { // Support both legacy $delete param and new action.
        $DB->delete_records('plagiarism_inspera_subs', ['id' => $id]);
        \core\notification::success(get_string('filedeleted', 'plagiarism_inspera'));
    }
}

// 4. DISPLAY TABLE.
$table = new \plagiarism_inspera\output\debug_table('debugtable');

// Use new core_user fields API.
$userfieldsapi = \core_user\fields::for_name();
$userfields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

$sqlfields = "t.id, t.status, t.timecreated, t.externalid, t.similarity, t.description,
              u.id as userid, $userfields,
              c.id as courseid, c.fullname, c.shortname,
              t.cm as cm, m.name as moduletype";

$sqlfrom = "{plagiarism_inspera_subs} t
            LEFT JOIN {user} u ON t.userid = u.id
            JOIN {course_modules} cm ON t.cm = cm.id
            JOIN {modules} m ON cm.module = m.id
            JOIN {course} c ON cm.course = c.id";

$sqlwhere = "1=1";

if (!empty($ufextrasql)) {
    $sqlwhere .= " AND " . $ufextrasql;
}

// Only load submissions that are 2 months old (from now) to keep the list manageable.
$twomonthscutoff = strtotime('-2 months');
if ($twomonthscutoff === false) {
    // Fallback in the unlikely event strtotime fails; approx 2 months as 60 days.
    $twomonthscutoff = time() - (60 * 24 * 60 * 60);
}
$sqlwhere .= " AND t.timecreated >= :timesince";
$ufparams['timesince'] = $twomonthscutoff;

$table->set_sql($sqlfields, $sqlfrom, $sqlwhere, $ufparams);

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    $currenttab = 'originalitydebug';

    require_once('originality_tabs.php');

    echo $OUTPUT->heading(get_string('originalityfiles', 'plagiarism_inspera'));

    // RENDER THE SMART TOGGLE BUTTON.
    echo html_writer::start_div('mb-4');
    if ($prefshowall) {
        $toggleurl = new moodle_url($PAGE->url, ['showall' => -1, 'sesskey' => sesskey()]);
        echo html_writer::link(
            $toggleurl,
            get_string('toggleviewerrorsonly', 'plagiarism_inspera'),
            ['class' => 'btn btn-outline-danger']
        );
    } else {
        $toggleurl = new moodle_url($PAGE->url, ['showall' => 1, 'sesskey' => sesskey()]);
        echo html_writer::link(
            $toggleurl,
            get_string('toggleviewallsubmissions', 'plagiarism_inspera'),
            ['class' => 'btn btn-outline-primary']
        );
    }
    echo html_writer::end_div();

    $ufiltering->display_add();
    $ufiltering->display_active();

    echo '<form action="originality_debug.php" method="post" id="debugform">';
    echo html_writer::start_div();
    echo html_writer::tag('input', '', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('input', '', ['type' => 'hidden', 'name' => 'returnto', 'value' => s($PAGE->url->out(false))]);
}

$table->out($limit, false);

if (!$table->is_downloading()) {
    echo html_writer::tag('input', "", [
        'name' => 'deleteselectedfiles',
        'type' => 'submit',
        'id' => 'deleteallselected',
        'class' => 'btn btn-secondary',
        'value' => get_string('deleteselectedfiles', 'plagiarism_inspera')]);

    echo html_writer::span(' ');
    echo html_writer::tag('input', "", [
        'name' => 'resubmitselectedfiles',
        'type' => 'submit',
        'id' => 'resubmitselected',
        'class' => 'btn btn-secondary',
        'value' => get_string('resubmitselectedfiles', 'plagiarism_inspera')]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::empty_tag('hr');
    echo $OUTPUT->footer();
}
