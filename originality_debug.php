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

$errorsonly = (bool)get_config('plagiarism_inspera', 'errorsonlymanagement');
if (
    $errorsonly &&
    !$deleteselected &&
    !$resubmitselected &&
    !$id &&
    $action === '' &&
    !empty($SESSION->user_filtering) &&
    is_array($SESSION->user_filtering)
) {
    $allowedstatuses = plagiarism_inspera_errors_only_status_map();
    $sessionchanged = false;

    $sanitizefilters = function (array &$filters) use ($allowedstatuses, &$sessionchanged): void {
        if (empty($filters['status']) || !is_array($filters['status'])) {
            return;
        }

        $filteredstatusrules = [];
        foreach ($filters['status'] as $rule) {
            $value = plagiarism_inspera_extract_status_rule_value($rule);

            if ($value !== null && isset($allowedstatuses[$value])) {
                $filteredstatusrules[] = $rule;
            }
        }

        if (count($filteredstatusrules) !== count($filters['status'])) {
            $sessionchanged = true;
        }

        if (empty($filteredstatusrules)) {
            unset($filters['status']);
        } else {
            $filters['status'] = $filteredstatusrules;
        }
    };

    if (array_key_exists('status', $SESSION->user_filtering)) {
        $sanitizefilters($SESSION->user_filtering);
    } else {
        foreach ($SESSION->user_filtering as &$filtersession) {
            if (is_array($filtersession) && array_key_exists('status', $filtersession)) {
                $sanitizefilters($filtersession);
            }
        }
        unset($filtersession);
    }

    if ($sessionchanged) {
        redirect($url);
    }
}

$exportfilename = 'OriginalityDebugOutput.csv';

$limit = 50;
$filters = ['status' => 0, 'realname' => 0, 'timecreated' => 0, 'course' => 0, 'externalid' => 0, 'description' => 0];
$ufiltering = new \plagiarism_inspera\output\filtering($filters, $PAGE->url);
[$ufextrasql, $ufparams] = $ufiltering->get_sql_filter();

// Enforce error-only scope for all queries when globally enabled.
if ($errorsonly) {
    $erroronlystatuses = plagiarism_inspera_errors_only_statuses();
    $statusplaceholders = [];
    foreach ($erroronlystatuses as $idx => $statusvalue) {
        $paramname = "defaultstatus{$idx}";
        $statusplaceholders[] = ':' . $paramname;
        $ufparams[$paramname] = $statusvalue;
    }
    $erroronlysql = 't.status IN (' . implode(', ', $statusplaceholders) . ')';
    if (!empty($ufextrasql)) {
        $ufextrasql = "({$ufextrasql}) AND {$erroronlysql}";
    } else {
        $ufextrasql = $erroronlysql;
    }
}


$plagiarismsettings = plagiarism_plugin_inspera::get_settings();

// 2. HANDLE BULK ACTIONS.
if (($deleteselected || $resubmitselected) && confirm_sesskey()) {
    // Step A: Collect IDs from the initial POSTed checkboxes or the hidden fileids field in the confirmation form.
    if (empty($fileidsparam)) {
        $selectedids = [];
        $submitteddata = data_submitted();
        if (!empty($submitteddata)) {
            foreach ($submitteddata as $key => $value) {
                if (preg_match('/^item(\d+)$/', $key, $matches)) {
                    $selectedids[] = $matches[1];
                }
            }
        }
    } else {
        $selectedids = explode(',', $fileidsparam);
    }
    // Normalise IDs: Ensure they are unique, cleaned to integers, and re-indexed.
    $selectedids = array_values(array_unique(array_map('intval', $selectedids)));

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

        // 1. Determine message and action parameter.
        if ($deleteselected) {
            $actionparam = 'deleteselectedfiles';
            $message = get_string('areyousurebulk', 'plagiarism_inspera', count($selectedids));
        } else {
            $actionparam = 'resubmitselectedfiles';
            $message = get_string('areyousurebulkresubmit', 'plagiarism_inspera', count($selectedids));
        }

        // 2. Prepare context for the Mustache template.
        $context = [
            'message' => $message,
            'posturl' => (new moodle_url('/plagiarism/inspera/originality_debug.php'))->out(false),
            'sesskey' => sesskey(),
            'fileids' => implode(',', $selectedids),
            'actionparam' => $actionparam,
            'cancelurl' => (new moodle_url('/plagiarism/inspera/originality_debug.php'))->out(false),
        ];

        // 3. Render the template.
        echo $OUTPUT->render_from_template('plagiarism_inspera/bulk_confirm', $context);

        echo $OUTPUT->footer();
        die();
    }

    // Step C: Execution Stage (After "Yes" is clicked).
    if ($deleteselected) {
        $DB->delete_records_list('plagiarism_inspera_subs', 'id', $selectedids);
        $deletedcount = count($selectedids);
        \core\notification::success(get_string('recordsdeleted', 'plagiarism_inspera', $deletedcount));
    } else if ($resubmitselected) {
        $client = new \plagiarism_inspera\apiclient\api_client();
        $recoveryservice = new \plagiarism_inspera\services\resubmission_recovery_service($DB);
        $result = $recoveryservice->resubmit_bulk($selectedids, $client);

        if (($result->recovered + $result->queued) > 0) {
            $a = new \stdClass();
            $a->selected = $result->selected;
            $a->recovered = $result->recovered;
            $a->queued = $result->queued;
            $a->skipped = $result->skipped;

            if ($result->skipped > 0) {
                $message = get_string('resubmit_bulk_success_skipped', 'plagiarism_inspera', $a);
            } else {
                $message = get_string('resubmit_bulk_success', 'plagiarism_inspera', $a);
            }
            \core\notification::success($message);
        } else {
            \core\notification::error(get_string('resubmit_bulk_error', 'plagiarism_inspera'));
        }
    }

    // Final Redirect (Clean URL).
    redirect(new moodle_url('/plagiarism/inspera/originality_debug.php'));
}

// 3. HANDLE SINGLE ACTIONS (Row Links).
if ($id && ($action === 'resubmit' || $action === 'delete')) {
    require_sesskey();
    $executed = false;
    if ($action === 'resubmit') {
        $client = new \plagiarism_inspera\apiclient\api_client();
        $recoveryservice = new \plagiarism_inspera\services\resubmission_recovery_service($DB);
        $outcome = $recoveryservice->resubmit_single($id, $client);

        if ($outcome === 'recovered') {
            \core\notification::success(get_string('resubmit_single_recovered', 'plagiarism_inspera'));
        } else if ($outcome === 'queued') {
            \core\notification::success(get_string('resubmit_single_queued', 'plagiarism_inspera'));
        } else if ($outcome === 'api_error') {
            // Handle the API failure case safely.
            \core\notification::error(get_string('resubmit_single_api_error', 'plagiarism_inspera'));
        } else if ($outcome === 'not_found') {
            // Handle the specific 'not_found' case.
            \core\notification::error(get_string('resubmit_single_not_found', 'plagiarism_inspera'));
        } else if ($outcome === 'skipped') {
            // The API returned a fatal status (e.g., password protected).
            // We updated the DB but aborted the retry.
            \core\notification::warning(get_string('resubmit_single_skipped', 'plagiarism_inspera'));
        } else {
            \core\notification::error(get_string('resubmit_single_not_eligible', 'plagiarism_inspera'));
        }
        // Unconditionally trigger the PRG redirect so the URL is cleaned.
        $executed = true;
    } else if ($action === 'delete') {
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
    $renderable = new \plagiarism_inspera\output\debug_page($table, $ufiltering, $limit);

    // Standard Page Render.
    echo $renderer->render_debug_page($renderable);
} else {
    // CSV Download bypasses rendering.
    $table->out($limit, false);
}
