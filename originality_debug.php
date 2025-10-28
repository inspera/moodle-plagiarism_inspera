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
 * originality_defaults.php - Displays default values to use inside assignments for ORIGINALITY
 *
 * @package plagiarism_originality
 * @copyright 2025 Inspera AS
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->dirroot.'/plagiarism/originality/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$resetuser = optional_param('reset', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$resubmitallfiltered = optional_param('resubmitallfiltered', '', PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$deleteselected = optional_param('deleteselectedfiles', 0, PARAM_TEXT);
$deleteallfiltered = optional_param('deleteallfiltered', 0, PARAM_TEXT);
$fileids = optional_param('fileids', '', PARAM_TEXT);

require_login();

$url = new moodle_url('/plagiarism/originality/originality_debug.php');
admin_externalpage_setup('plagiarismoriginality', '', array(), $url);

$context = context_system::instance();

$exportfilename = 'UrkundDebugOutput.csv';

$limit = 50;
$filters = array('realname' => 0, 'timesubmitted' => 0, 'statuscode' => 0, 'errorcode' => 0, 'course' => 0);
$ufiltering = new \plagiarism_originality\output\filtering($filters, $PAGE->url);
list($ufextrasql, $ufparams) = $ufiltering->get_sql_filter();

$plagiarismsettings = plagiarism_plugin_originality::get_settings();
if (!empty($deleteselected)) {
    if (empty($fileids)) {
        $fileids = array();
        // First time form submit - get list of ids from checkboxes or from single delete action.
        if (!empty($delete)) {
            // This is a single delete action.
            $fileids[] = $delete;
        } else {
            // Get list of ids from checkboxes.
            $post = data_submitted();
            foreach ($post as $k => $v) {
                if (preg_match('/^item(\d+)$/', $k, $m)) {
                    $fileids[] = $m[1];
                }
            }
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
        foreach ($fileids as $id) {
            $DB->delete_records('plagiarism_originality_subs', array('id' => $id));
            $count++;
        }
        \core\notification::success(get_string('recordsdeleted', 'plagiarism_originality', $count));
    }
} else if (!empty($deleteallfiltered) || !empty($resubmitallfiltered)) {
    $sqlfrom = "FROM {plagiarism_originality_subs} t, {user} u, {modules} m, {course_modules} cm, {course} c
             WHERE m.id = cm.module AND cm.id = t.cm AND t.userid = u.id AND c.id = cm.course
                   AND t.statuscode <> 'Analyzed' AND $ufextrasql";
    $numfiles = $DB->count_records_sql("SELECT count(t.id) $sqlfrom", $ufparams);
    if (!$confirm) {
        $params = array('deleteallfiltered' => $deleteallfiltered,
            'resubmitallfiltered' => $resubmitallfiltered, 'confirm' => 1);
        $deleteurl = new moodle_url($PAGE->url, $params);
        $areyousure = !empty($deleteallfiltered) ? 'areyousurefiltereddelete' : 'areyousurefilteredresubmit';
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string($areyousure, 'plagiarism_originality', $numfiles),
            $deleteurl, $CFG->wwwroot . '/plagiarism/originality/originality_debug.php');

        echo $OUTPUT->footer();
        exit;
    } else if ($confirm && confirm_sesskey()) {
        if (!empty($deleteallfiltered)) {
            $sql = "DELETE FROM {plagiarism_originality_subs}
                    WHERE id IN (SELECT t.id $sqlfrom)";
            $DB->execute($sql, $ufparams);
            \core\notification::success(get_string('recordsdeleted', 'plagiarism_originality', $numfiles));
        } else {
            // Deal with any 202 files first.
            // Reset their attempt value.
            $pfiles = $DB->get_records_sql("SELECT t.* $sqlfrom AND t.statuscode = '202'", $ufparams);
            foreach ($pfiles as $plagiarismfile) {
                $file = originality_get_score($plagiarismsettings, $plagiarismfile, true);
                // Reset attempts as this was a manual check.
                $file->attempt = $file->attempt - 1;
                $DB->update_record('plagiarism_originality_subs', $file);
                if ($file->statuscode == ORIGINALITY_STATUSCODE_ACCEPTED) {
                    $response = get_string('scorenotavailableyet', 'plagiarism_originality');
                } else if ($file->statuscode == ORIGINALITY_STATUSCODE_PROCESSED ||
                    $file->statuscode == 'Analyzed') {
                    $response = get_string('scoreavailable', 'plagiarism_originality');
                } else {
                    $response = get_string('unknownwarninggetscore', 'plagiarism_originality');
                    if (debugging()) {
                        echo plagiarism_originality_pretty_print($file);
                    }
                }
            }
            // Now deal with the other files.
            $pfiles = $DB->get_records_sql("SELECT t.* $sqlfrom AND t.statuscode <> '202'", $ufparams);
            foreach ($pfiles as $plagiarismfile) {
                originality_reset_file($plagiarismfile, $plagiarismsettings);
            }
            \core\notification::success(get_string('filesresubmitted', 'plagiarism_originality', $numfiles));
        }
    }
}

plagiarism_originality_checkcronhealth();
if ($resetuser == 1 && $id && confirm_sesskey()) {
    if (originality_reset_file($id, $plagiarismsettings)) {
        \core\notification::success(get_string('fileresubmitted', 'plagiarism_originality'));
    }
} else if ($resetuser == 2 && $id && confirm_sesskey()) {
    $plagiarismfile = $DB->get_record('plagiarism_originality_subs', array('id' => $id), '*', MUST_EXIST);
    $file = originality_get_score(plagiarism_plugin_originality::get_settings(), $plagiarismfile, true);
    // Reset attempts as this was a manual check.
    $file->attempt = $file->attempt - 1;
    $DB->update_record('plagiarism_originality_subs', $file);
    if ($file->statuscode == ORIGINALITY_STATUSCODE_ACCEPTED) {
        \core\notification::warning(get_string('scorenotavailableyet', 'plagiarism_originality'));
    } else if ($file->statuscode == ORIGINALITY_STATUSCODE_PROCESSED || $file->statuscode == 'Analyzed') {
        \core\notification::success(get_string('scoreavailable', 'plagiarism_originality'));
    } else {
        \core\notification::error(get_string('unknownwarninggetscore', 'plagiarism_originality'));
        echo plagiarism_originality_pretty_print($file);
    }
}

if (!empty($delete) && confirm_sesskey()) {
    $DB->delete_records('plagiarism_originality_subs', array('id' => $id));
    \core\notification::success(get_string('filedeleted', 'plagiarism_originality'));
}

$table = new \plagiarism_originality\output\debug_table('debugtable');

$userfields = get_all_user_name_fields(true, 'u');
$sqlfields = "t.*, ".$userfields.", m.name as moduletype, ".
    "cm.course as courseid, cm.instance as cminstance, c.fullname, c.shortname";
$sqlfrom = "{plagiarism_originality_subs} t, {user} u, {modules} m, {course_modules} cm, {course} c ";
$sqlwhere = "m.id = cm.module AND cm.id = t.cm AND t.userid = u.id AND c.id = cm.course AND t.statuscode <> 'Analyzed'";

if (!empty($ufextrasql)) {
    $sqlwhere .= " and ".$ufextrasql;
}
$table->set_sql($sqlfields, $sqlfrom, $sqlwhere, $ufparams);


if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    $currenttab = 'originalitydebug';

    require_once('originality_tabs.php');

    echo $OUTPUT->heading(get_string('originalityfiles', 'plagiarism_originality'));
    echo $OUTPUT->box(get_string('explainerrors', 'plagiarism_originality'));

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
    if (!empty($ufextrasql)) {
        // If a filter is in use, show a button to delete all that use this filter.
        echo html_writer::span(' ');
        echo html_writer::tag('input', "", array('name' => 'deleteallfiltered', 'type' => 'submit',
            'id' => 'deleteallfiltered', 'class' => 'btn btn-secondary',
            'value' => get_string('deleteallfiltered', 'plagiarism_originality')));
        echo html_writer::span(' ');
        echo html_writer::tag('input', "", array('name' => 'resubmitallfiltered', 'type' => 'submit',
            'id' => 'resubmitallfiltered', 'class' => 'btn btn-secondary',
            'value' => get_string('resubmitallfiltered', 'plagiarism_originality')));
    }
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::empty_tag('hr');
    echo $OUTPUT->footer();
}
