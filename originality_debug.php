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
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->dirroot.'/plagiarism/originality/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA); // Unified action param
$resubmitallfiltered = optional_param('resubmitallfiltered', '', PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$deleteselected = optional_param('deleteselectedfiles', 0, PARAM_TEXT);
$deleteallfiltered = optional_param('deleteallfiltered', 0, PARAM_TEXT);
$fileids = optional_param('fileids', '', PARAM_TEXT);

require_login();

$url = new moodle_url('/plagiarism/originality/originality_debug.php');
admin_externalpage_setup('plagiarismoriginality', '', array(), $url);

$context = context_system::instance();

$exportfilename = 'OriginalityDebugOutput.csv';

$limit = 50;
// Note: We filter by 'status' now, not 'statuscode' or 'errorcode'
$filters = array('realname' => 0, 'timesubmitted' => 0, 'status' => 0, 'course' => 0, 'externalid' => 0);
$ufiltering = new \plagiarism_originality\output\filtering($filters, $PAGE->url);
list($ufextrasql, $ufparams) = $ufiltering->get_sql_filter();

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
// 2. HANDLE BULK ACTIONS (Filtered Results)
// -------------------------------------------------------------------------
else if (!empty($deleteallfiltered) || !empty($resubmitallfiltered)) {
    // SQL to find all filtered records.
    // Note: status <> 'finished' ensures we don't accidentally wipe valid finished records unless specifically filtered otherwise.
    // But typically debug tools show everything. Let's rely on the filter.
    $sqlfrom = "FROM {plagiarism_originality_subs} t
                JOIN {user} u ON t.userid = u.id
                JOIN {course_modules} cm ON t.cm = cm.id
                JOIN {modules} m ON cm.module = m.id
                JOIN {course} c ON cm.course = c.id
                WHERE 1=1";

    if (!empty($ufextrasql)) {
        $sqlfrom .= " AND $ufextrasql";
    }

    $numfiles = $DB->count_records_sql("SELECT count(t.id) $sqlfrom", $ufparams);

    if (!$confirm) {
        $params = array('deleteallfiltered' => $deleteallfiltered,
            'resubmitallfiltered' => $resubmitallfiltered, 'confirm' => 1);

        // Use filtering params to ensure the confirm/post-action targets the same set
        foreach ($ufparams as $key => $value) {
            // Filters usually pass params via session or url, but for confirm page we might need to persist them
            // Ideally filtering class handles this via session.
        }

        $deleteurl = new moodle_url($PAGE->url, $params);
        $areyousure = !empty($deleteallfiltered) ? 'areyousurefiltereddelete' : 'areyousurefilteredresubmit';

        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string($areyousure, 'plagiarism_originality', $numfiles),
            $deleteurl, $CFG->wwwroot . '/plagiarism/originality/originality_debug.php');

        echo $OUTPUT->footer();
        exit;
    } else if ($confirm && confirm_sesskey()) {

        $transaction = $DB->start_delegated_transaction();
        try {
            // Fetch the IDs
            $ids = $DB->get_fieldset_sql("SELECT t.id $sqlfrom", $ufparams);

            if (empty($ids)) {
                \core\notification::warning(get_string('nofilesselected', 'plagiarism_originality'));
            } else {

                // Prepare params safely
                list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

                if (!empty($deleteallfiltered)) {
                    // DELETE ALL
                    $DB->delete_records_select('plagiarism_originality_subs', "id $insql", $inparams);
                    \core\notification::success(get_string('recordsdeleted', 'plagiarism_originality', count($ids)));

                } else {
                    // RESUBMIT ALL
                    $status_resubmit = 'report_requested';
                    $time_now = time();

                    $params = array_merge(
                        ['status' => $status_resubmit, 'modtime' => $time_now],
                        $inparams
                    );

                    $sql = "UPDATE {plagiarism_originality_subs}
                            SET status = :status, 
                                timemodified = :modtime,
                                similarity = NULL,
                                translation_similarity = NULL,
                                ai_index = NULL,
                                originality = NULL,
                                character_replacement = NULL,
                                hidden_text = NULL,
                                image_as_text = NULL
                            WHERE id $insql";

                    $DB->execute($sql, $params);
                    \core\notification::success(get_string('filesresubmitted', 'plagiarism_originality', count($ids)));
                }
            }

            $transaction->allow_commit();

        } catch (\Exception $e) {
            $transaction->rollback($e);
            // Re-throw the error so Moodle displays the debugging info
            throw $e;
        }
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
        // Optionally clear externalid if you want a fresh submission, 
        // but keeping it allows the task to try re-uploading to the same doc first.

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

$sqlfields = "t.id, t.status, t.timecreated, t.externalid, t.similarity,
              u.id as userid, $userfields,
              c.id as courseid, c.fullname, c.shortname,
              cm.id as cm, m.name as moduletype";

$sqlfrom = "{plagiarism_originality_subs} t
            LEFT JOIN {user} u ON t.userid = u.id
            LEFT JOIN {course_modules} cm ON t.cm = cm.id
            LEFT JOIN {modules} m ON cm.module = m.id
            LEFT JOIN {course} c ON cm.course = c.id";

$sqlwhere = "1=1"; // Base where clause

// Note: Urkund filtered out 'Analyzed'. You might want to filter 'finished' by default 
// or just show everything. This code shows everything unless filtered.
// If you want to hide finished by default:
// $sqlwhere .= " AND t.status <> 'finished'";

if (!empty($ufextrasql)) {
    $sqlwhere .= " AND " . $ufextrasql;
}

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

    if (!empty($ufextrasql)) {
        // If a filter is in use, show bulk buttons
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
