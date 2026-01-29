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
global $OUTPUT, $CFG, $PAGE, $DB;

/**
 * Debug tool for Inspera Originality.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__DIR__)) . '/config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->dirroot.'/plagiarism/originality/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA); // Unified action param
$resubmitselected = optional_param('resubmitselectedfiles', 0, PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$deleteselected = optional_param('deleteselectedfiles', 0, PARAM_TEXT);
$fileids = optional_param('fileids', '', PARAM_TEXT);

require_login();

$url = new moodle_url('/plagiarism/originality/originality_debug.php');
$PAGE->set_url($url);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('originalitydebug', 'plagiarism_originality'));
global $SITE;
if (!empty($SITE)) {
    $PAGE->set_heading(format_string($SITE->fullname));
}
require_capability('moodle/site:config', $context);

$exportfilename = 'OriginalityDebugOutput.csv';

$limit = 50;
$filters = array('realname' => 0, 'timesubmitted' => 0, 'course' => 0, 'externalid' => 0, 'description' => 0);
$ufiltering = new \plagiarism_originality\output\filtering($filters, $PAGE->url);
list($ufextrasql, $ufparams) = $ufiltering->get_sql_filter();

$defaultstatusapplied = false;
if (empty($ufextrasql)) {
    $ufextrasql = "t.status IN (:defaultstatus1, :defaultstatus2)";
    $ufparams['defaultstatus1'] = 'error';
    $ufparams['defaultstatus2'] = 'external_error';
    $defaultstatusapplied = true;
}

$plagiarismsettings = plagiarism_plugin_originality::get_settings();

// -------------------------------------------------------------------------
// 1. HANDLE BULK DELETE (Selected via Checkboxes)
// -------------------------------------------------------------------------
if (!empty($deleteselected)) {
    if (empty($fileids)) {
        $fileids = array();
        // Check for checkbox data
        $post = data_submitted();
        foreach ($post as $k => $v) {
            if (preg_match('/^item(\d+)$/', $k, $m)) {
                $fileids[] = $m[1];
            }
        }

        if (empty($fileids)) {
            redirect($url, get_string('nofilesselected', 'plagiarism_originality'));
        }

        // Display confirmation box.
        $params = array('deleteselectedfiles' => 1, 'confirm' => 1, 'fileids' => implode(',', $fileids));
        $deleteurl = new moodle_url($PAGE->url, $params);
        $numfiles = count($fileids);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('areyousurebulk', 'plagiarism_originality', $numfiles),
            $deleteurl, $CFG->wwwroot . '/plagiarism/originality/originality_debug.php');

        echo $OUTPUT->footer();
        exit;
    } else if ($confirm && confirm_sesskey()) {
        $count = 0;
        $fileids = explode(',', $fileids);
        foreach ($fileids as $fid) {
            $DB->delete_records('plagiarism_originality_subs', array('id' => $fid));
            $count++;
        }
        \core\notification::success(get_string('recordsdeleted', 'plagiarism_originality', $count));
    }
}
// -------------------------------------------------------------------------
// 2. HANDLE BULK RESUBMIT (Selected via Checkboxes)
// -------------------------------------------------------------------------
else if (!empty($resubmitselected)) {
    if (empty($fileids)) {
        $fileids = array();
        // Check for checkbox data
        $post = data_submitted();
        foreach ($post as $k => $v) {
            if (preg_match('/^item(\d+)$/', $k, $m)) {
                $fileids[] = $m[1];
            }
        }

        if (empty($fileids)) {
            redirect($url, get_string('nofilesselected', 'plagiarism_originality'));
        }

        // Display confirmation box for resubmit selected
        $params = array('resubmitselectedfiles' => 1, 'confirm' => 1, 'fileids' => implode(',', $fileids));
        $resubmiturl = new moodle_url($PAGE->url, $params);
        $numfiles = count($fileids);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('areyousurebulkresubmit', 'plagiarism_originality', $numfiles),
            $resubmiturl, $CFG->wwwroot . '/plagiarism/originality/originality_debug.php');

        echo $OUTPUT->footer();
        exit;
    } else if ($confirm && confirm_sesskey()) {
        $fileids = explode(',', $fileids);
        // Update selected records for resubmission
        list($insql, $inparams) = $DB->get_in_or_equal($fileids, SQL_PARAMS_NAMED);
        $params = array_merge([
            'status' => 'report_requested',
            'modtime' => time()
        ], $inparams);

        $sql = "UPDATE {plagiarism_originality_subs}
                SET status = :status,
                    timemodified = :modtime,
                    similarity = NULL,
                    translation_similarity = NULL,
                    ai_index = NULL,
                    originality = NULL,
                    character_replacement = NULL,
                    hidden_text = NULL,
                    image_as_text = NULL,
                    description = NULL
                WHERE id $insql";

        $DB->execute($sql, $params);
        \core\notification::success(get_string('filesresubmitted', 'plagiarism_originality', count($fileids)));
    }
}

// -------------------------------------------------------------------------
// 3. HANDLE SINGLE ACTIONS (Row Links)
// -------------------------------------------------------------------------
// We use the unified 'action' parameter now: 'resubmit' or 'delete'

if ($id && confirm_sesskey()) {
    if ($action === 'resubmit') {
        // Reset single file
        $record = new stdClass();
        $record->id = $id;
        $record->status = 'report_requested';
        $record->timemodified = time();
        // Clear scores
        $record->similarity = null;
        $record->translation_similarity = null;
        $record->ai_index = null;
        $record->originality = null;
        $record->character_replacement = null;
        $record->hidden_text = null;
        $record->image_as_text = null;
        $record->externalid = null;
        $record->description = null;

        $DB->update_record('plagiarism_originality_subs', $record);
        \core\notification::success(get_string('fileresubmitted', 'plagiarism_originality'));

    } else if ($action === 'delete' || !empty($delete)) { // Support both legacy $delete param and new action
        $DB->delete_records('plagiarism_originality_subs', array('id' => $id));
        \core\notification::success(get_string('filedeleted', 'plagiarism_originality'));
    }
}

// -------------------------------------------------------------------------
// 4. DISPLAY TABLE
// -------------------------------------------------------------------------

// We removed 'checkcronhealth' unless you implemented it in lib.php

$table = new \plagiarism_originality\output\debug_table('debugtable');

// Fix: Use new core_user fields API
$userfieldsapi = \core_user\fields::for_name();
$userfields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

$sqlfields = "t.id, t.status, t.timecreated, t.externalid, t.similarity, t.description,
              u.id as userid, $userfields,
              c.id as courseid, c.fullname, c.shortname,
              cm.id as cm, m.name as moduletype";

$sqlfrom = "{plagiarism_originality_subs} t
            LEFT JOIN {user} u ON t.userid = u.id
            LEFT JOIN {course_modules} cm ON t.cm = cm.id
            LEFT JOIN {modules} m ON cm.module = m.id
            LEFT JOIN {course} c ON cm.course = c.id";

$sqlwhere = "1=1"; // Base where clause; default filter is applied via $ufextrasql when no user filter is set

if (!empty($ufextrasql)) {
    $sqlwhere .= " AND " . $ufextrasql;
}

// Only load submissions that are 6 months old (from now)
$sixmonthscutoff = strtotime('-6 months');
if ($sixmonthscutoff === false) {
    // Fallback in the unlikely event strtotime fails; approx 6 months as 182 days.
    $sixmonthscutoff = time() - (182 * 24 * 60 * 60);
}
$sqlwhere .= " AND t.timecreated >= :timesince";
$ufparams['timesince'] = $sixmonthscutoff;

$table->set_sql($sqlfields, $sqlfrom, $sqlwhere, $ufparams);

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    $currenttab = 'originalitydebug';

    require_once('originality_tabs.php');

    echo $OUTPUT->heading(get_string('originalityfiles', 'plagiarism_originality'));
    // Ensure this string exists or remove the box
    // echo $OUTPUT->box(get_string('explainerrors', 'plagiarism_originality'));

    $ufiltering->display_add();
    $ufiltering->display_active();

    echo '<form action="originality_debug.php" method="post" id="debugform">';
    echo html_writer::start_div();
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'returnto', 'value' => s($PAGE->url->out(false))));
}

$table->out($limit, false);

if (!$table->is_downloading()) {
    echo html_writer::tag('input', "", array('name' => 'deleteselectedfiles', 'type' => 'submit',
        'id' => 'deleteallselected', 'class' => 'btn btn-secondary',
        'value' => get_string('deleteselectedfiles', 'plagiarism_originality')));

    echo html_writer::span(' ');
    echo html_writer::tag('input', "", array('name' => 'resubmitselectedfiles', 'type' => 'submit',
        'id' => 'resubmitselected', 'class' => 'btn btn-secondary',
        'value' => get_string('resubmitselectedfiles', 'plagiarism_originality')));
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::empty_tag('hr');
    echo $OUTPUT->footer();
}
