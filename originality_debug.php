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

// 1. PREPARE PARAMETERS.
$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Use PARAM_BOOL for buttons. If clicked, they return true.
$deleteselected   = optional_param('deleteselectedfiles', false, PARAM_BOOL);
$resubmitselected = optional_param('resubmitselectedfiles', false, PARAM_BOOL);
$confirm          = optional_param('confirm', 0, PARAM_INT);
$fileidsparam     = optional_param('fileids', '', PARAM_SEQUENCE);

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

// Apply the error filter ONLY if no custom filters are set AND the user hasn't toggled "Show All".
if (empty($ufextrasql) && !$prefshowall) {
    $ufextrasql = "t.status IN (:defaultstatus1, :defaultstatus2)";
    $ufparams['defaultstatus1'] = 'error';
    $ufparams['defaultstatus2'] = 'external_error';
}


$plagiarismsettings = plagiarism_plugin_inspera::get_settings();

// 1. PREPARE PARAMETERS.
$deleteselected   = optional_param('deleteselectedfiles', false, PARAM_BOOL);
$resubmitselected = optional_param('resubmitselectedfiles', false, PARAM_BOOL);
$confirm          = optional_param('confirm', 0, PARAM_INT);
$fileidsparam    = optional_param('fileids', '', PARAM_SEQUENCE); // Handles comma-separated IDs.

// 2. HANDLE BULK ACTIONS.
if (($deleteselected || $resubmitselected) && confirm_sesskey()) {
    // Step A: Collect IDs if they aren't already in the URL (from the first button click).
    if (empty($fileidsparam)) {
        $selectedids = [];
        foreach ($_POST as $key => $value) {
            if (preg_match('/^item(\d+)$/', $key, $matches)) {
                $selectedids[] = $matches[1];
            }
        }
    } else {
        $selectedids = explode(',', $fileidsparam);
    }

    // Error if nothing selected.
    if (empty($selectedids)) {
        redirect(
            new moodle_url('/plagiarism/inspera/originality_debug.php'),
            get_string('nofilesselected', 'plagiarism_inspera'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Step B: Confirmation Stage.
    if (!$confirm) {
        echo $OUTPUT->header();

        $params = [
            'confirm' => 1,
            'fileids' => implode(',', $selectedids),
            'sesskey' => sesskey(),
        ];

        if ($deleteselected) {
            $params['deleteselectedfiles'] = 1;
            $message = get_string('areyousurebulk', 'plagiarism_inspera', count($selectedids));
        } else {
            $params['resubmitselectedfiles'] = 1;
            $message = get_string('areyousurebulkresubmit', 'plagiarism_inspera', count($selectedids));
        }

        $continueurl = new moodle_url('/plagiarism/inspera/originality_debug.php', $params);
        $cancelurl = new moodle_url('/plagiarism/inspera/originality_debug.php');

        echo $OUTPUT->confirm($message, $continueurl, $cancelurl);
        echo $OUTPUT->footer();
        exit;
    }

    // Step C: Execution Stage (After "Yes" is clicked).
    if ($deleteselected) {
        $DB->delete_records_list('plagiarism_inspera_subs', 'id', $selectedids);
        \core\notification::success(get_string('recordsdeleted', 'plagiarism_inspera', count($selectedids)));
    } else if ($resubmitselected) {
        // Use short array destructuring instead of list().
        [$insql, $inparams] = $DB->get_in_or_equal($selectedids, SQL_PARAMS_NAMED);
        $sqlparams = array_merge(['status' => 'report_requested', 'modtime' => time()], $inparams);

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

        $DB->execute($sql, $sqlparams);
        \core\notification::success(get_string('filesresubmitted', 'plagiarism_inspera', count($selectedids)));
    }

    // Final Redirect (Clean URL).
    redirect(new moodle_url('/plagiarism/inspera/originality_debug.php'));
}

// 3. HANDLE SINGLE ACTIONS (Row Links).
if ($id && confirm_sesskey()) {
    $executed = false;
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
        $executed = true;
    } else if ($action === 'delete' || !empty($delete)) { // Support both legacy $delete param and new action.
        $DB->delete_records('plagiarism_inspera_subs', ['id' => $id]);
        \core\notification::success(get_string('filedeleted', 'plagiarism_inspera'));
        $executed = true;
    }
    // REDIRECT (Post-Redirect-Get Pattern).
    // Only redirect if we actually performed an action to avoid infinite loops.
    if ($executed) {
        // Redirect to a clean URL (stripping action, id, and sesskey).
        redirect(new \moodle_url('/plagiarism/inspera/originality_debug.php'));
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

// Only load submissions from the last 2 months to keep the list manageable.
$twomonthscutoff = strtotime('-2 months');
if ($twomonthscutoff === false) {
    // Fallback in the unlikely event strtotime fails; approx 2 months as 60 days.
    $twomonthscutoff = time() - (60 * 24 * 60 * 60);
}
$sqlwhere .= " AND t.timecreated >= :timesince";
$ufparams['timesince'] = $twomonthscutoff;

$table->set_sql($sqlfields, $sqlfrom, $sqlwhere, $ufparams);

// 4. PREPARE OUTPUT.
$renderer = $PAGE->get_renderer('plagiarism_inspera');

if (!$table->is_downloading()) {
    $renderable = new \plagiarism_inspera\output\debug_page($table, $ufiltering, $prefshowall);

    // If we are in the middle of a bulk action that needs confirmation.
    if (($deleteselected || $resubmitselected) && !$confirm && !empty($selectedids)) {
        $params = [
            'confirm' => 1,
            'fileids' => implode(',', $selectedids),
            'sesskey' => sesskey(),
        ];

        if ($deleteselected) {
            $params['deleteselectedfiles'] = 1;
            $msg = get_string('areyousurebulk', 'plagiarism_inspera', count($selectedids));
        } else {
            $params['resubmitselectedfiles'] = 1;
            $msg = get_string('areyousurebulkresubmit', 'plagiarism_inspera', count($selectedids));
        }

        $continueurl = new moodle_url('/plagiarism/inspera/originality_debug.php', $params);
        $cancelurl = new moodle_url('/plagiarism/inspera/originality_debug.php');

        echo $renderer->render_bulk_confirmation($msg, $continueurl, $cancelurl);
        exit;
    }

    // Standard Page Render.
    echo $renderer->render_debug_page($renderable);
} else {
    // CSV Download bypasses rendering.
    $table->out($limit, false);
}
