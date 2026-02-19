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

use plagiarism_inspera\apiclient\api_client;

defined('MOODLE_INTERNAL') || die();

// Get global class.
global $CFG;
require_once($CFG->dirroot.'/plagiarism/lib.php');
require_once($CFG->dirroot . '/lib/filelib.php');


define('PLAGIARISM_INSPERA_SHOW_NEVER', 0);
define('PLAGIARISM_INSPERA_SHOW_ALWAYS', 1);
define('PLAGIARISM_INSPERA_SHOW_AFTER_GRADING', 2);
define('PLAGIARISM_INSPERA_SHOW_DUE_DATE', 3);

define('PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE', 0);
define('PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL', 1);

// Used by content type restriction form - inline-text vs file attachments.
define('PLAGIARISM_INSPERA_RESTRICTCONTENTNO', 0);
define('PLAGIARISM_INSPERA_RESTRICTCONTENTFILES', 1);
define('PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT', 2);

define('PLAGIARISM_INSPERA_MAXATTEMPTS', 28);

/**
 * The main plugin class for Inspera Originality.
 *
 * This class handles the core logic, event handling, and settings integration
 * for the originality plagiarism plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_plugin_inspera extends plagiarism_plugin {

    /**
     * Gets the global sitewide settings for this plugin.
     *
     * Statically caches the settings for performance. Returns false if the plugin
     * is disabled or not configured.
     *
     * @return array|false The array of config settings, or false if disabled/misconfigured.
     */
    public static function get_settings() {
        static $plagiarismsettings;
        if (!empty($plagiarismsettings) || $plagiarismsettings === false) {
            return $plagiarismsettings;
        }
        $plagiarismsettings = (array)get_config('plagiarism_inspera');
        // Check if enabled.
        if (isset($plagiarismsettings['enabled']) && $plagiarismsettings['enabled']) {
            // Check to make sure required settings are set!
            if (empty($plagiarismsettings['baseurl'])) {
                return false;
            }
            return $plagiarismsettings;
        } else {
            return false;
        }
    }

    /**
     * Gets the activity-specific settings for a given course module ID.
     *
     * @param int $cmid The course module ID.
     * @return array An array of settings (name => value) for that module.
     */
    public static function get_settings_by_module($cmid) {
        global $DB;
        $settings = [];

        // Load module config from plagiarism config table.
        $records = $DB->get_records('plagiarism_inspera_config', ['cm' => $cmid]);
        foreach ($records as $rec) {
            $settings[$rec->name] = $rec->value;
        }

        return $settings;
    }

    /**
     * Returns a list of all setting names managed by this plugin.
     *
     * Used for saving data in the module edit form and defaults page.
     *
     * @param bool $adminsettings Whether to include settings only available on the admin page.
     * @return string[] An array of setting names.
     */
    public static function config_options($adminsettings = false) {
        $options = array(
            'use_originality',
            'originality_allowallfile',
            'originality_archive',
            'originality_restrictcontent',
            'originality_selectfiletypes',
            'originality_metadata_analysis',
            'originality_enable_ai',
            'originality_enable_translations',
            'originality_translation_languages',
            'originality_enable_context_similarity',
            'originality_context_threshold',
            'originality_enable_include_urls',
            'originality_include_urls',
            'originality_enable_exclude_urls',
            'originality_exclude_urls',
            'originality_show_student_report',
            'originality_draft_submit'
        );
        if ($adminsettings) {
            $options[] = 'originality_advanceditems';
            $options[] = 'originality_hiddenitems';
            $options[] = 'originality_lockeditems';
        }
        return $options;
    }

    /**
     * Hook to allow plagiarism specific information to be displayed beside a submission.
     * @param array $linkarray - contains all relevant information for the plugin to generate a link.
     * @return string
     */
    public function get_links($linkarray) {
        global $DB, $CFG, $USER;

        static $plagiarismvalues = [];
        $fullquizlist = false;
        $output = '';

        // ==============================
        // 1. Early exit checks
        // ==============================
        if (!empty($linkarray['component']) && strpos($linkarray['component'], 'qtype_') === 0) {
            $qtype = str_replace('qtype_', '', $linkarray['component']);

            if (!in_array($qtype, plagiarism_inspera_supported_qtypes())) {
                return '';
            }

            if (empty(get_config('plagiarism_inspera', 'enable_mod_quiz'))) {
                return '';
            }

            // Determine course module id
            if (!empty($linkarray['cmid'])) {
                $fullquizlist = true;
            } else {
                if (!empty($linkarray['area'])) {
                    $quba = question_engine::load_questions_usage_by_activity($linkarray['area']);
                    $context = $quba->get_owning_context();
                    if ($context->contextlevel == CONTEXT_MODULE) {
                        $linkarray['cmid'] = get_coursemodule_from_id(false, $context->instanceid)->id;
                    }
                }
            }

            if (empty($linkarray['cmid'])) {
                return '';
            }

            // Determine userid if missing
            if (empty($linkarray['userid']) && !empty($linkarray['itemid'])) {
                if (empty($quba)) {
                    $quba = question_engine::load_questions_usage_by_activity($linkarray['area']);
                }
                $attempt = $quba->get_question_attempt($linkarray['itemid']);
                $linkarray['userid'] = $attempt->get_step(0)->get_user_id();
            }

            // Get content if missing
            if (empty($linkarray['content']) && empty($linkarray['file'])) {
                if (empty($attempt)) {
                    $quba = question_engine::load_questions_usage_by_activity($linkarray['area']);
                    $attempt = $quba->get_question_attempt($linkarray['itemid']);
                }
                $linkarray['content'] = $attempt->get_response_summary();
            }
        }

        // ==============================
        // 2. Load plugin config for this cmid
        // ==============================
        if (!isset($plagiarismvalues[$linkarray['cmid']])) {
            $plagiarismvalues[$linkarray['cmid']] = $DB->get_records_menu('plagiarism_inspera_config',
                ['cm' => $linkarray['cmid']], '', 'name,value');
        }

        // Helper to resolve Submission ID for Assignments
        $get_assign_submission_id = function($cmid, $userid) use ($CFG) {
            try {
                // We use the Assign API to find the correct submission (Group or Individual)
                $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
                if (!$cm) return 0;

                require_once($CFG->dirroot . '/mod/assign/locallib.php');
                $context = \context_module::instance($cm->id);
                $assign = new \assign($context, $cm, null);
                // 'false' means don't create one if missing.
                // This automatically returns the GROUP submission if the assignment is in group mode.
                $submission = $assign->get_user_submission($userid, false);
                return $submission ? $submission->id : 0;
            } catch (\Exception $e) {
                return 0;
            }
        };

        // ==============================
        // 5. Add "View Originality Report" if finished
        // ==============================
        if (!empty($linkarray['cmid']) && !empty($linkarray['userid']) && !empty($linkarray['file'])) {
            // $linkarray['file'] should be a stored_file object.
            $file = $linkarray['file'];

            // --- Resolve Submission ID ---
            $submissionid = 0;
            $comp = $file->get_component();

            // For standard assignment files, the itemid IS the submissionid
            if ($comp === 'assignsubmission_file' || $comp === 'assignsubmission_onlinetext') {
                $submissionid = $file->get_itemid();
            }

            $record = false;

            // Strategy A: Query by Submission ID (Preferred for Group Assignments)
            if (!empty($submissionid)) {
                // We search by submissionid + fileid. We ignore userid here because
                // in a group submission, User B (viewer) didn't upload the file (User A did).
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE submissionid = ? 
                      AND storedfileid = ?
                      AND status != 'superseded'
                    ORDER BY timecreated DESC, id DESC";

                $record = $DB->get_record_sql($sql, [$submissionid, $file->get_id()], IGNORE_MULTIPLE);
            }

            // Strategy B: Fallback to User ID (For non-assign modules or old data)
            if (!$record) {
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE cm = ? 
                      AND userid = ? 
                      AND storedfileid = ?
                      AND status != 'superseded'
                    ORDER BY timecreated DESC, id DESC";

                $record = $DB->get_record_sql($sql, [
                    $linkarray['cmid'],
                    $linkarray['userid'],
                    $file->get_id()
                ], IGNORE_MULTIPLE);
            }

            if ($record) {
                // Determine if viewer is allowed to see this status/link.
                $cmcontext = \context_module::instance($linkarray['cmid']);
                $isgrader = has_capability('mod/assign:grade', $cmcontext);
                if ($isgrader || plagiarism_inspera_should_show_report($linkarray['cmid'], $linkarray['userid'], $plagiarismvalues[$linkarray['cmid']], $record)) {
                    $output .= $this->get_originality_status($record, $plagiarismvalues[$linkarray['cmid']]);
                }
            }
        }

        // ==============================
        // 6. Add "View Originality Report" for ONLINE TEXT submissions (no file)
        // ==============================
        if (!empty($linkarray['content']) && !empty($linkarray['cmid']) && !empty($linkarray['userid'])) {

            // --- Resolve Submission ID for Text ---
            // Online text doesn't always come with an itemid in $linkarray, so we look it up.
            $submissionid = $get_assign_submission_id($linkarray['cmid'], $linkarray['userid']);

            $textrecord = false;

            // Strategy A: Query by Submission ID
            if (!empty($submissionid)) {
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE submissionid = ? 
                      AND storedfileid IS NULL
                      AND status != 'superseded'
                    ORDER BY timecreated DESC";
                $textrecord = $DB->get_record_sql($sql, [$submissionid], IGNORE_MULTIPLE);
            }

            // Strategy B: Fallback to User ID
            if (!$textrecord) {
                $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE cm = ? 
                      AND userid = ? 
                      AND storedfileid IS NULL
                      AND status != 'superseded'
                    ORDER BY timecreated DESC";
                $textrecord = $DB->get_record_sql($sql, [$linkarray['cmid'], $linkarray['userid']], IGNORE_MULTIPLE);
            }

            if ($textrecord) {
                $cmcontext = \context_module::instance($linkarray['cmid']);
                $isgrader = has_capability('mod/assign:grade', $cmcontext);
                if ($isgrader || plagiarism_inspera_should_show_report($linkarray['cmid'], $linkarray['userid'], $plagiarismvalues[$linkarray['cmid']], $textrecord)) {
                    $output .= $this->get_originality_status($textrecord, $plagiarismvalues[$linkarray['cmid']]);
                }
            }
        }

        return $output;
    }

    /**
     * Generic handler function for all events - queues files for sending.
     * @param stdClass $eventdata
     * @return boolean
     */
    public function event_handler($eventdata) {
        global $DB, $CFG;

        $plagiarismsettings = $this->get_settings();
        if (!$plagiarismsettings) {
            return true;
        }
        $cmid = $eventdata['contextinstanceid'];
        $plagiarismvalues = $DB->get_records_menu('plagiarism_inspera_config', array('cm' => $cmid), '', 'name, value');
        if (empty($plagiarismvalues['use_originality'])) {
            // originality not in use for this cm - return.
            return true;
        }

        // Check if the module associated with this event still exists.
        if (!$DB->record_exists('course_modules', array('id' => $cmid))) {
            return true;
        }

        $userid = $eventdata['userid'];
        $relateduserid = !empty($eventdata['relateduserid']) ? $eventdata['relateduserid'] : null;

        // For assignsubmission_* events and assessable_uploaded in Assignments,
        // the objectid IS the submissionid.
        $submissionid = isset($eventdata['objectid']) ? $eventdata['objectid'] : null;


        // Check to see if restrictcontent is in use.
        $showcontent = true;
        $showfiles = true;
        if (!empty($plagiarismvalues['originality_restrictcontent'])) {
            if ($plagiarismvalues['originality_restrictcontent'] == PLAGIARISM_INSPERA_RESTRICTCONTENTFILES) {
                $showcontent = false;
            } else if ($plagiarismvalues['originality_restrictcontent'] == PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT) {
                $showfiles = false;
            }
        }

        // === CHECK GROUP SUBMISSION ===
        // If "Students submit in groups" is enabled, we MUST disable Online Text checking.
        // We query the assignment table to check the 'teamsubmission' setting.
        $assignment_config = $DB->get_record_sql("
        SELECT a.teamsubmission
        FROM {assign} a
        JOIN {course_modules} cm ON a.id = cm.instance
        WHERE cm.id = ?",
            array($cmid)
        );

        if ($assignment_config && !empty($assignment_config->teamsubmission)) {
            // Group submission is ON -> Disable Online Text checking
            if ($showcontent) {
                mtrace("Originality: Group submission enabled for cmid={$cmid}. Disabling Online Text checking.");
                $showcontent = false;
            }
        }

        $charcount = plagiarism_inspera_charcount();

        // === CASE 1: Final Submission (Submit for Marking) ===
        if ($eventdata['eventtype'] == 'assignsubmission_submitted' && empty($eventdata['other']['submission_editable'])) {
            // Assignment-specific functionality:
            // This is a 'finalize' event. No files from this event itself,
            // but need to check if files from previous events need to be submitted for processing.
            $result = true;
            if (isset($plagiarismvalues['originality_draft_submit']) &&
                $plagiarismvalues['originality_draft_submit'] == PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL) {
                // Any files attached to previous events were not submitted.
                // These files are now finalized, and should be submitted for processing.
                mtrace("Originality: Final submission detected (cmid={$cmid}, userid={$userid}). Queuing finalized content due to FINAL mode.");
                require_once("$CFG->dirroot/mod/assign/locallib.php");
                require_once("$CFG->dirroot/mod/assign/submission/file/locallib.php");

                $modulecontext = context_module::instance($cmid);
                $queuedfiles = 0;
                $queuedtext = 0;

                if ($showfiles) { // If we should be handling files.
                    $fs = get_file_storage();
                    if ($files = $fs->get_area_files($modulecontext->id, 'assignsubmission_file',
                        ASSIGNSUBMISSION_FILE_FILEAREA, $eventdata['objectid'], "id", false)) {
                        foreach ($files as $file) {
                            plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, $submissionid);
                            $queuedfiles++;
                        }
                    }
                }

                // $showcontent will be FALSE here if groups are enabled, so this block is skipped safely.
                if ($showcontent) { // If we should be handling in-line text.
                    $submission = $DB->get_record('assignsubmission_onlinetext', array('submission' => $eventdata['objectid']));
                    if (!empty($submission) && strlen(utf8_decode(strip_tags($submission->onlinetext))) >= $charcount) {
                        $file = plagiarism_inspera_create_temp_file($cmid, $eventdata['courseid'], $userid, $submission->onlinetext, $submissionid);
                        plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, $submissionid);
                        $queuedtext++;
                    }
                }

                mtrace("Originality: Queued {$queuedfiles} file(s) and {$queuedtext} text item(s) on final submit (cmid={$cmid}, userid={$userid}).");
            }
            return $result;
        }

        if (isset($plagiarismvalues['originality_draft_submit']) &&
            $plagiarismvalues['originality_draft_submit'] == PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL) {
            // Assignment-specific functionality:
            // Files should only be sent for checking once "finalized".
            mtrace("Originality: Skipping draft event because FINAL mode is enabled (cmid={$cmid}, userid={$userid}). No rows created.");
            return true;
        }

        // === CASE 2: Upload/Save Event (Draft Mode) ===
        $result = true;
        if (!empty($eventdata['other']['content']) && $showcontent &&
            strlen(utf8_decode(strip_tags($eventdata['other']['content']))) >= $charcount) {

            $file = plagiarism_inspera_create_temp_file($cmid, $eventdata['courseid'], $userid, $eventdata['other']['content'], $submissionid);
            plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, $submissionid);
        }

        // Normal situation: 1 or more assessable files attached to event, ready to be checked.
        if (!empty($eventdata['other']['pathnamehashes']) && $showfiles) {
            foreach ($eventdata['other']['pathnamehashes'] as $hash) {
                $fs = get_file_storage();
                $efile = $fs->get_file_by_hash($hash);

                if (empty($efile) || $efile->get_filename() === '.') {
                    continue;
                }

                plagiarism_inspera_queue_file($cmid, $userid, $efile, $relateduserid, $submissionid);
            }
        }
        return $result;
    }

    /**
     * Generates HTML for a plagiarism report link/status.
     *
     * @param stdClass $record The plagiarism submission record
     * @param array $settings The module settings
     * @return string HTML output
     */
    private function get_originality_status($record, $settings) {
        global $OUTPUT;

        $linkclass = 'plagiarism-originality-status';
        $linkcontent = '';

        switch ($record->status) {
            case 'finished':
                $url = new moodle_url('/plagiarism/inspera/redirect.php', ['id' => $record->id]);
                $score = round($record->similarity);

                // Defaults to 'low' if the value is missing.
                $riskClass = strtolower(explode(' ', $record->originality ?? 'Low')[0]);

                $scoreclass = 'originality-score ' . $riskClass;

                $linkprefix = get_string('reportlinkprefix', 'plagiarism_inspera');
                $scoretext = get_string('reportlinkscore', 'plagiarism_inspera', $score);

                $iconhtml = $OUTPUT->pix_icon('logo', $linkprefix, 'plagiarism_inspera',
                    ['class' => 'originality-logo-icon']);
                $scorehtml = html_writer::tag('span', $scoretext, ['class' => $scoreclass]);

                $linkcontent = html_writer::link($url, $iconhtml . ' ' . $linkprefix . ' ' . $scorehtml,
                    ['target' => '_blank']);
                $linkclass = 'plagiarism-originality-reportlink';
                break;

            case 'report_requested':
                $linkcontent = get_string('statusrequested', 'plagiarism_inspera');
                break;

            case 'pending':
                $linkcontent = get_string('statuspending', 'plagiarism_inspera');
                break;

            case 'error':
            case 'external_error':
                $linkcontent = get_string('statuserror', 'plagiarism_inspera');
                $linkclass .= ' error';
                break;
        }

        return !empty($linkcontent) ? html_writer::div($linkcontent, $linkclass) : '';
    }

}


/**
 * Helper function to get allowed char count.
 * @return int - number of allowed chars.
 */
function plagiarism_inspera_charcount() {
    $charcount = get_config('plagiarism_inspera', 'charcount');
    if (empty($charcount)) {
        // Set a sensible default if we can't find one.
        $charcount = 450;
    }
    return $charcount;
}

/**
 * Checks if originality is enabled for a specific course module.
 *
 * Caches the result statically.
 *
 * @param int $cmid The course module ID to check.
 * @return array|false The settings array if enabled, false otherwise.
 */
function plagiarism_inspera_cm_use($cmid) {
    global $DB;
    static $useoriginality = array();
    if (!isset($useoriginality[$cmid])) {
        $pvalues = $DB->get_records_menu('plagiarism_inspera_config', array('cm' => $cmid), '', 'name,value');
        if (!empty($pvalues['use_originality'])) {
            $useoriginality[$cmid] = $pvalues;
        } else {
            $useoriginality[$cmid] = false;
        }
    }
    return $useoriginality[$cmid];
}

/**
 * Determines whether a student should be shown the originality report link.
 *
 * Conditions:
 * - Report must exist and be finished.
 * - Sharing option controls visibility: not shared (never), immediately (always),
 *   after grading (only once graded), due date (after due date passes).
 *
 * Teachers/graders bypass this check in get_links() and always see the status.
 *
 * @param int $cmid Course module id
 * @param int $userid User id owning the submission
 * @param array $settings Plagiarism settings for the CM (records_menu name=>value)
 * @param stdClass $record The plagiarism record from {plagiarism_inspera_subs}
 * @return bool
 */
function plagiarism_inspera_should_show_report(int $cmid, int $userid, array $settings, \stdClass $record): bool {
    global $DB;

    // Must have a finished report to show to students.
    if (empty($record) || $record->status !== 'finished') {
        return false;
    }

    $shareopt = isset($settings['originality_show_student_report']) ? (int)$settings['originality_show_student_report'] : 0;
    switch ($shareopt) {
        case 0: // Not shared
            return false;
        case 1: // Immediately after it is available
            return true;
        case 2: // After grading
            // Determine if there is a grade for this assignment instance for this user.
            // Resolve course module and instance.
            $cm = get_coursemodule_from_id(false, $cmid, 0, false, MUST_EXIST);
            if ($cm->modname === 'assign') {
                // Use the grade API to see if a grade exists and is not null.
                require_once($GLOBALS['CFG']->libdir . '/gradelib.php');
                $grades = grade_get_grades($cm->course, 'mod', 'assign', $cm->instance, $userid);
                if (!empty($grades->items[0]->grades)) {
                    $g = reset($grades->items[0]->grades);
                    // Show if there is a grade and it is not null (or overridden).
                    if ($g && ($g->str_grade !== '-' && $g->grade !== null)) {
                        return true;
                    }
                }
            }
            return false;
        case 3: // Due date
            $cm = get_coursemodule_from_id(false, $cmid, 0, false, MUST_EXIST);
            if ($cm->modname === 'assign') {
                $now = time();

                // 1. CHECK EXTENSIONS (Highest Priority)
                // If a teacher granted an extension, this is the only date that matters for this user.
                $flags = $DB->get_record('assign_user_flags',
                    ['assignment' => $cm->instance, 'userid' => $userid],
                    'id, extensionduedate', IGNORE_MISSING);

                if (!empty($flags) && !empty($flags->extensionduedate)) {
                    // If extension is set, show ONLY if extension date has passed.
                    return $now >= (int)$flags->extensionduedate;
                }

                // 2. CHECK USER OVERRIDES (Medium Priority)
                // If the user has a specific override (e.g. for accessibility), use that.
                $uoverride = $DB->get_record('assign_overrides',
                    ['assignid' => $cm->instance, 'userid' => $userid],
                    'id, duedate', IGNORE_MISSING);

                if (!empty($uoverride) && !empty($uoverride->duedate)) {
                    return $now >= (int)$uoverride->duedate;
                }

                // 3. CHECK GLOBAL ASSIGNMENT DUE DATE (Lowest Priority)
                // Fallback to the standard date set in settings.
                $assign = $DB->get_record('assign',
                    ['id' => $cm->instance],
                    'id, duedate', IGNORE_MISSING);

                if (!empty($assign) && !empty($assign->duedate)) {
                    return $now >= (int)$assign->duedate;
                }
            }
            return false;
        default:
            return false;
    }
}

/**
 * Function to list question types that originality supports.
 * @return array
 *
 */
function plagiarism_inspera_supported_qtypes() {
    return array('essay');
}

/**
 * Returns a list of Moodle modules supported by this plugin.
 *
 * @return string[] An array of module names (e.g., 'assign', 'quiz').
 */
function plagiarism_inspera_supported_modules() {
    global $CFG;
    $supportedmodules = array('assign');
    return $supportedmodules;
}

/**
 * Hook to save plagiarism specific settings on a module settings page.
 *
 * @param stdClass $data
 * @param stdClass $course
 */
function plagiarism_inspera_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    $plugin = new plagiarism_plugin_inspera();
    // do nothing if plagiarism is not enabled.
    if (!$plugin->get_settings()) {
        return $data;
    }

    if (isset($data->use_originality)) {
        if (empty($data->submissiondrafts)) {
            // Make sure draft_submit is not set if submissiondrafts not used.
            $data->originality_draft_submit = 0;
        }
        // Array of possible plagiarism config options.
        $plagiarismelements = $plugin->config_options();

        // First get existing values.
        if (empty($data->coursemodule)) {
            debugging("originality settings failure - no coursemodule set in form data, originality could not be enabled.");
            return $data;
        }
        $existingelements = $DB->get_records_menu('plagiarism_inspera_config', array('cm' => $data->coursemodule),
            '', 'name, id');

        // 1. Save Standard Settings (Teacher choices)
        foreach ($plagiarismelements as $element) {
            $newelement = new stdClass();
            $newelement->cm = $data->coursemodule;
            $newelement->name = $element;
            if (isset($data->$element) && is_array($data->$element)) {
                $newelement->value = implode(',', $data->$element);
            } else {
                $newelement->value = (isset($data->$element) ? $data->$element : 0);
            }
            if (isset($existingelements[$element])) {
                $newelement->id = $existingelements[$element];
                $DB->update_record('plagiarism_inspera_config', $newelement);
            } else {
                $DB->insert_record('plagiarism_inspera_config', $newelement);
            }

        }

        // 2. SNAPSHOT LOGIC: Freeze Admin Rules for this Assignment
        //    This ensures that future Admin changes do not break existing assignments.

        // Determine the module suffix (e.g., '_assign')
        $modulename = $data->modulename ?? 'assign';
        $suffix = '_' . $modulename;

        // The 3 lists that control visibility/locking
        $config_lists = [
            'originality_lockeditems' . $suffix,
            'originality_hiddenitems' . $suffix,
            'originality_advanceditems' . $suffix
        ];

        // Get Global Defaults (Admin Settings)
        // We use cm=0 to fetch the global configuration.
        $admin_defaults = $DB->get_records_menu('plagiarism_inspera_config', ['cm' => 0], '', 'name, value');

        foreach ($config_lists as $configname) {
            // Check if this assignment ALREADY has this list defined locally
            // We check the DB directly to be safe, or check our pre-fetched $existingelements array
            $already_exists = isset($existingelements[$configname]);

            if (!$already_exists) {
                // If Missing (New Assignment): Copy the current Admin Default into this assignment.
                $newrecord = new stdClass();
                $newrecord->cm = $data->coursemodule;
                $newrecord->name = $configname;

                // Use the admin value, or empty string if not set globally
                $newrecord->value = isset($admin_defaults[$configname]) ? $admin_defaults[$configname] : '';

                $DB->insert_record('plagiarism_inspera_config', $newrecord);
            }
            // If it DOES exist (Existing Assignment): Do nothing.
            // We want to keep the old snapshot, not overwrite it with new Admin rules.
        }

    }
    return $data;
}

/**
 * Hook to validate plagiarism settings on a module settings page before save.
 * Return element-keyed errors to block submission and display inline messages.
 *
 * Primary call signature in recent Moodle versions: ($formwrapper, $data, $files).
 * - $formwrapper: The form wrapper object (e.g., mod_assign_mod_form).
 * - $data: Cleaned submitted values (array).
 * - $files: Submitted files array.
 *
 * Backward-compatibility: Some environments invoke this hook with only two params
 * using the legacy pattern ($data, $files). This implementation supports both
 * patterns by accepting optional parameters and remapping when detected.
 *
 * @param moodleform|null $formwrapper The module form wrapper instance (or null in legacy calls)
 * @param array|null $data  Submitted form values (cleaned) or null
 * @param array|null $files Submitted files or null
 * @return array An array of validation errors keyed by element name
 */
function plagiarism_inspera_coursemodule_validation($formwrapper = null, $data = null, $files = null) {
    $errors = [];

    // Backward-compatibility: Some Moodle versions/invocations call this hook with only
    // two arguments: ($data, $files). If we detect that pattern (first param is the data array
    // and second is files, third missing), remap them to the new signature.
    if ($data === null && $files === null && is_array($formwrapper)) {
        $files = $data; // remains null in this legacy call
        $data = $formwrapper;
        $formwrapper = null;
    }

    // Defensive: Ensure $data is an array (older/misrouted calls could pass the form by mistake).
    if (!is_array($data)) {
        return $errors;
    }

    // If originality isn’t being configured on this form, skip.
    if (!isset($data['use_originality'])) {
        return $errors;
    }

    // Read core flags with sane defaults.
    $useoriginality = !empty($data['use_originality']);
    $allowall = isset($data['originality_allowallfile']) ? (int)$data['originality_allowallfile'] : 1;

    if ($useoriginality && $allowall === 0) {
        // Normalise the selection for file types.
        $selected = $data['originality_selectfiletypes'] ?? null;
        $isempty = false;

        if (is_array($selected)) {
            // Remove empties (can be [''] when nothing is selected)
            $filtered = array_values(array_filter($selected, function($v) { return $v !== '' && $v !== null; }));
            $isempty = count($filtered) === 0;
        } else if (is_string($selected)) {
            $trimmed = trim($selected);
            if ($trimmed === '') {
                $isempty = true;
            } else if (strpos($trimmed, ',') !== false) {
                $parts = array_map('trim', explode(',', $trimmed));
                $isempty = count(array_filter($parts, function($v) { return $v !== ''; })) === 0;
            } else {
                $isempty = false; // single non-empty value
            }
        } else {
            $isempty = true;
        }

        if ($isempty) {
            // Returning an error keyed to the element shows the message below the field and blocks save.
            $errors['originality_selectfiletypes'] = get_string('errorselectfiletypesrequired', 'plagiarism_inspera');
        }
    }

    return $errors;
}

/**
 * Hook to add plagiarism specific settings to a module settings page.
 *
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 */
function plagiarism_inspera_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB, $CFG;

    // === 1. Define Type Map (Single Source of Truth) ===
    // We define this early so it can be reused in all logic blocks.
    $types_map = [
        'use_originality' => PARAM_INT,
        'originality_allowallfile' => PARAM_INT,
        'originality_selectfiletypes' => PARAM_TAGLIST,
        'originality_enable_ai' => PARAM_INT,
        'originality_archive' => PARAM_INT,
        'originality_enable_context_similarity' => PARAM_INT,
        'originality_context_threshold' => PARAM_INT,
        'originality_enable_exclude_urls' => PARAM_INT,
        'originality_exclude_urls' => PARAM_TEXT,
        'originality_enable_include_urls' => PARAM_INT,
        'originality_include_urls' => PARAM_TEXT,
        'originality_metadata_analysis' => PARAM_INT,
        'originality_show_student_report' => PARAM_INT,
        'originality_draft_submit' => PARAM_INT,
        'originality_enable_translations' => PARAM_INT,
        'originality_translation_languages' => PARAM_TAGLIST,
        'originality_restrictcontent' => PARAM_INT,
    ];

    // === 2. Guard Clauses (Early Exit) ===
    $plugin = new plagiarism_plugin_inspera();
    // Check if plugin is enabled globally.
    $plagiarismsettings = $plugin->get_settings();
    if (!$plagiarismsettings) {
        return;
    }

    // Identify which module it's on (e.g. mod_assign_mod_form).
    $matches = array();
    if (!preg_match('/^mod_([^_]+)_mod_form$/', get_class($formwrapper), $matches)) {
        return;
    }
    $modulename = "mod_" . $matches[1];
    $modname = 'enable_' . $modulename;

    // Check if plagiarism is enabled for this module in the admin settings.
    if (empty($plagiarismsettings[$modname])) {
        return;
    }

    // === 3. Load Settings Data ===
    $cmid = null;
    if ($cm = $formwrapper->get_coursemodule()) {
        $cmid = $cm->id;
    }
    $context = context_course::instance($formwrapper->get_course()->id);
    $plagiarismelements = $plugin->config_options();

    // Load settings specific to this activity (if editing).
    $plagiarismvalues = [];
    if (!empty($cmid)) {
        $plagiarismvalues = $DB->get_records_menu('plagiarism_inspera_config', array('cm' => $cmid), '', 'name, value');
    }

    // Get Admin Defaults - cmid(0) is the default list.
    $plagiarismdefaults = $DB->get_records_menu('plagiarism_inspera_config', array('cm' => 0), '', 'name, value');

    // === 4. Add Form Elements (Based on Capability) ===
    // Check user's permissions.
    if (has_capability('plagiarism/inspera:enable', $context)) {
        // User HAS permission: Build and display all the visible form fields.
        // This helper function is responsible for both addElement() and setType().
        plagiarism_inspera_get_form_elements($mform);

        // Add conditional display logic for the visible form.
        // Show draft submit selector in all cases; value will be enforced in save if drafts are disabled.

        if (!has_capability('plagiarism/inspera:resubmitonclose', $context) &&
            $mform->elementExists('originality_resubmit_on_close')) {
            $mform->removeElement('originality_resubmit_on_close');
        }

        // Disable all sub-elements if the main 'use_originality' is set to 'No'.
        foreach ($plagiarismelements as $element) {
            if ($element != 'use_originality') { // Ignore the main switch itself.
                $mform->hideif($element, 'use_originality', 'eq', 0);
            }
        }

        $mform->hideif('originality_selectfiletypes', 'originality_allowallfile', 'eq', 1);

    } else {
        // User does NOT have permission: Add all settings as hidden fields.
        foreach ($plagiarismelements as $element) {
            $mform->addElement('hidden', $element);

            // We MUST set the PARAM types for these hidden fields to ensure
            // data is cleaned securely when the form is saved by this user.
            // Use the map to set types automatically
            if (isset($types_map[$element])) {
                $mform->setType($element, $types_map[$element]);
            } else {
                $mform->setType($element, PARAM_RAW); // Fallback
            }
        }
    }

    // === 5. Set Default Values ===
    // Now that all elements exist (either visible or hidden), set their default values.
    // Priority: 1) Specific activity values, 2) Admin defaults.
    foreach ($plagiarismelements as $element) {
        $defaultelement = $element . '_' . str_replace('mod_', '', $modulename);
        if (isset($plagiarismvalues[$element])) {
            // Priority 1: Use value saved for this specific activity.
            $mform->setDefault($element, $plagiarismvalues[$element]);
        } else if (isset($plagiarismdefaults[$defaultelement])) {
            // Priority 2: Use the admin-defined default for this module type.
            $mform->setDefault($element, $plagiarismdefaults[$defaultelement]);
        }
    }

    // === 6. Handle Hidden, Locked, and Advanced Settings ===
    $suffix = '_' . str_replace('mod_', '', $modulename);

    $get_list_values = function($base_name) use ($suffix, $plagiarismvalues, $plagiarismdefaults) {
        $fullname = $base_name . $suffix;

        // 1. Try Local Assignment Setting (Snapshot)
        if (isset($plagiarismvalues[$fullname])) {
            return !empty($plagiarismvalues[$fullname]) ? explode(',', $plagiarismvalues[$fullname]) : [];
        }

        // 2. Fallback to Admin Default
        if (isset($plagiarismdefaults[$fullname])) {
            return !empty($plagiarismdefaults[$fullname]) ? explode(',', $plagiarismdefaults[$fullname]) : [];
        }

        return [];
    };
    $hidden_list = $get_list_values('originality_hiddenitems');
    $locked_list = $get_list_values('originality_lockeditems');
    $advanced_list = $get_list_values('originality_advanceditems');

    // Check if user is an Admin (can bypass restrictions)
    $is_admin = has_capability('plagiarism/inspera:manage_locked_settings', $context);

    // Flag to track if we actually have any content for the "Show More" section
    $has_advanced_items = false;

    // Iterate over all possible plugin settings
    foreach ($plagiarismelements as $name) {
        if (!$mform->elementExists($name)) {
            continue;
        }

        // HIDDEN ITEMS
        // Priority 1: If it is in the Hidden list AND user is NOT Admin -> Hide it.
        $hidden_map = array_flip($hidden_list);
        if (isset($hidden_map[$name]) && !$is_admin) {
            if ($element = $mform->getElement($name)) {
                $value = $element->getValue() ?? '';
                // Fallback to default if value is not set
                if ($value === null && isset($plagiarismvalues[$name])) {
                    $value = $plagiarismvalues[$name];
                }

                // Remove visible element
                $mform->removeElement($name);

                // Add hidden element
                $mform->addElement('hidden', $name, $value);

                // Use the same map to ensure type safety here too!
                if (isset($types_map[$name])) {
                    $mform->setType($name, $types_map[$name]);
                } else {
                    $mform->setType($name, PARAM_RAW); // fallback
                }
            }
            continue;
        }

        // LOCKED ITEMS
        // Priority 2: If it is in the Locked list AND user is NOT Admin -> Freeze it.
        $locked_map = array_flip($locked_list);
        if (isset($locked_map[$name])) {
            // Always move Locked items to "Show More" (For BOTH Admins and Teachers)
            $mform->setAdvanced($name, true);
            $has_advanced_items = true;

            // Only Freeze if the user is NOT an Admin
            if (!$is_admin) {

                // If the item is locked, check if it's irrelevant and should be hidden instead.
                // A. Cleanup File Types
                if ($name === 'originality_selectfiletypes') {
                    $allowval = $plagiarismvalues['originality_allowallfile'] ?? null;
                    if ($allowval === null) {
                        $defaultkey = 'originality_allowallfile' . $suffix;
                        $allowval = $plagiarismdefaults[$defaultkey] ?? 0;
                    }
                    // If Allow All is YES, hide the empty list completely
                    if ($allowval == 1) {
                        if ($element = $mform->getElement($name)) {
                            $value = $element->getValue();
                            $mform->removeElement($name);
                            $mform->addElement('hidden', $name, $value);
                            if (isset($types_map[$name])) {
                                $mform->setType($name, $types_map[$name]);
                            }
                        }
                        continue;
                    }
                }

                // B. Cleanup Translations
                if ($name === 'originality_translation_languages') {
                    $transval = $plagiarismvalues['originality_enable_translations'] ?? null;
                    if ($transval === null) {
                        $defaultkey = 'originality_enable_translations' . $suffix;
                        $transval = $plagiarismdefaults[$defaultkey] ?? 0;
                    }
                    // If Translations are NO, hide the list completely
                    if ($transval == 0) {
                        if ($element = $mform->getElement($name)) {
                            $value = $element->getValue();
                            $mform->removeElement($name);
                            $mform->addElement('hidden', $name, $value);
                            if (isset($types_map[$name])) {
                                $mform->setType($name, $types_map[$name]);
                            }
                        }
                        continue;
                    }
                }

                $mform->freeze($name);
                if ($element = $mform->getElement($name)) {
                    $mform->setConstant($name, $element->getValue());
                }
            }
        }

        // --- RULE 3: ADVANCED ITEMS ---
        // If it is in the Advanced list -> Move to "Show More".
        // (This runs for Admins too, which is correct behavior)
        $advanced_map = array_flip($advanced_list);
        if (isset($advanced_map[$name])) {
            $mform->setAdvanced($name, true);
            $has_advanced_items = true;
        }
    }

    // === FORCE SHOW MORE TO TOP (CONDITIONAL) ===
    // Only move the anchor to Advanced if we actually have other advanced items to show.
    if ($has_advanced_items && $mform->elementExists('originality_advanced_anchor')) {
        $mform->setAdvanced('originality_advanced_anchor', true);
    }

    // === 7. Handle Module-Specific Logic ===

    // Now handle content restriction settings.
    // For Assign: only show when BOTH file and online text submissions are enabled.
    if ($modulename == 'mod_assign') {
        if ($mform->elementExists('originality_restrictcontent')) {
            $mform->hideIf('originality_restrictcontent', 'assignsubmission_file_enabled', 'notchecked');
            $mform->hideIf('originality_restrictcontent', 'assignsubmission_onlinetext_enabled', 'notchecked');
        }
    } else if ($modulename != 'mod_forum' && $modulename != 'mod_hsuforum') {
        // Freeze setting for modules that don't support content type restriction.
        $mform->setDefault('originality_restrictcontent', 0);
        $mform->hardFreeze('originality_restrictcontent');
    }

    global $PAGE;
    $PAGE->requires->js(new moodle_url('/plagiarism/inspera/originality_form_behaviour.js'));
}

/**
 * Adds the list of plagiarism settings to a form.
 *
 * @param object $mform - Moodle form object.
 */
function plagiarism_inspera_get_form_elements($mform) {
    $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

    // Supported languages for Translations
    $languages = [
        'en' => 'English', 'sq' => 'Albanian', 'bg' => 'Bulgarian', 'hr' => 'Croatian', 'cs' => 'Czech',
        'da' => 'Danish', 'nl' => 'Dutch', 'et' => 'Estonian', 'fi' => 'Finnish', 'fr' => 'French',
        'de' => 'German', 'el' => 'Greek', 'hu' => 'Hungarian', 'it' => 'Italian', 'lv' => 'Latvian',
        'lt' => 'Lithuanian', 'mk' => 'Macedonian', 'no' => 'Norwegian', 'pl' => 'Polish', 'pt' => 'Portuguese',
        'ro' => 'Romanian', 'ru' => 'Russian', 'sr' => 'Serbian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
        'es' => 'Spanish', 'sv' => 'Swedish', 'tr' => 'Turkish', 'bs' => 'Bosnian'
    ];
    ksort($languages); // Alphabetical

    $draftoptions = array(
        PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_inspera"),
        PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL => get_string("submitonfinal", "plagiarism_inspera")
    );

    $mform->addElement('header', 'plagiarismdesc', get_string('originality', 'plagiarism_inspera'));

    // create a static empty div on top. The "Show more" link will be always on top.
    $mform->addElement('static', 'originality_advanced_anchor', '', '');


    // Enable Originality Check
    $mform->addElement('select', 'use_originality', get_string("use_originality", "plagiarism_inspera"), $ynoptions);
    $mform->addHelpButton('use_originality', 'use_originality_teachers', 'plagiarism_inspera');
    $mform->setType('use_originality', PARAM_INT);

    // Allow all supported File Types
    $filetypes = plagiarism_inspera_default_allowed_file_types(true);
    $supportedfiles = array();
    foreach ($filetypes as $ext => $mime) {
        $supportedfiles[$ext] = $ext;
    }
    $mform->addElement('select', 'originality_allowallfile', get_string('originality_allowallfile', 'plagiarism_inspera'), $ynoptions);
    $mform->addHelpButton('originality_allowallfile', 'originality_allowallfile', 'plagiarism_inspera');
    $mform->setType('originality_allowallfile', PARAM_INT);

    $mform->addElement('select', 'originality_selectfiletypes', get_string('originality_selectfiletypes', 'plagiarism_inspera'), $supportedfiles, array('multiple' => true));
    $mform->addHelpButton('originality_selectfiletypes', 'originality_selectfiletypes', 'plagiarism_inspera');
    $mform->setType('originality_selectfiletypes', PARAM_TAGLIST);

    // When originality is enabled AND allow-all is set to No, require at least one file type to be selected.
    $mform->addRule('originality_selectfiletypes', get_string('errorselectfiletypesrequired', 'plagiarism_inspera'),
        'callback', function($value) use ($mform) {
            // Helper to safely get single select values as ints.
            $getint = function(string $name, int $default = 0) use ($mform): int {
                $raw = $mform->getSubmitValue($name);
                if ($raw === null) {
                    $raw = $mform->getElementValue($name);
                }
                if (is_array($raw)) {
                    $first = reset($raw);
                    return (int)$first;
                }
                return (int)$raw;
            };

            $useoriginality = $getint('use_originality', 0);
            $allowall = $getint('originality_allowallfile', 1);

            // Only enforce when originality is ON and allow-all is OFF.
            if ($useoriginality === 1 && $allowall === 0) {
                // Normalise current field's value to an array of non-empty strings.
                $vals = [];
                if (is_array($value)) {
                    $vals = array_values(array_filter($value, function($v) { return $v !== '' && $v !== null; }));
                } else if (is_string($value)) {
                    $trimmed = trim($value);
                    if ($trimmed !== '') {
                        if (strpos($trimmed, ',') !== false) {
                            $parts = array_map('trim', explode(',', $trimmed));
                            $vals = array_values(array_filter($parts, function($v) { return $v !== ''; }));
                        } else {
                            $vals = [$trimmed];
                        }
                    }
                }
                return count($vals) > 0;
            }
            return true; // No requirement in other cases.
        }
    );

    // Hide file type selection when "Allow all" is YES (value 1)
    $mform->hideIf('originality_selectfiletypes', 'originality_allowallfile', 'eq', 1);

    // AI Authorship
    $mform->addElement('select', 'originality_enable_ai', get_string('originality_enable_ai', 'plagiarism_inspera'), $ynoptions);
    $mform->addHelpButton('originality_enable_ai', 'originality_enable_ai', 'plagiarism_inspera');
    $mform->setType('originality_enable_ai', PARAM_INT);

    // Archive Documents
    $mform->addElement('select', 'originality_archive', get_string('originality_archive', 'plagiarism_inspera'), $ynoptions);
    $mform->addHelpButton('originality_archive', 'originality_archive', 'plagiarism_inspera');
    $mform->setType('originality_archive', PARAM_INT);

    // Contextual Similarity
    $mform->addElement('select', 'originality_enable_context_similarity', get_string('originality_enable_context_similarity', 'plagiarism_inspera'), $ynoptions);
    $mform->setType('originality_enable_context_similarity', PARAM_INT);
    $mform->setDefault('originality_enable_context_similarity', 0);
    $mform->addHelpButton('originality_enable_context_similarity', 'originality_enable_context_similarity', 'plagiarism_inspera');

    // Threshold input (always optional in the form)
    $mform->addElement('text', 'originality_context_threshold', get_string('originality_context_threshold', 'plagiarism_inspera'));
    $mform->setType('originality_context_threshold', PARAM_INT);
    $mform->setDefault('originality_context_threshold', 50);
    $mform->addHelpButton('originality_context_threshold', 'originality_context_threshold', 'plagiarism_inspera');
    $mform->addRule('originality_context_threshold', get_string('contextthresholdmin', 'plagiarism_inspera'),
        'callback',
        function($value) {
            return $value >= 50 && $value <= 100;
        });
    // Hide threshold unless select is set to yes
    $mform->hideIf('originality_context_threshold', 'originality_enable_context_similarity', 'neq', 1);

    // Exclude URLs
    $mform->addElement('select', 'originality_enable_exclude_urls', get_string('originality_enable_exclude_urls', 'plagiarism_inspera'), $ynoptions);
    $mform->setType('originality_enable_exclude_urls', PARAM_INT);
    $mform->setDefault('originality_enable_exclude_urls', 0);
    $mform->addHelpButton('originality_enable_exclude_urls', 'originality_enable_exclude_urls', 'plagiarism_inspera');

    $mform->addElement('text', 'originality_exclude_urls', get_string('originality_exclude_urls', 'plagiarism_inspera'));
    $mform->setType('originality_exclude_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_exclude_urls', 'originality_exclude_urls', 'plagiarism_inspera');
    $mform->hideIf('originality_exclude_urls', 'originality_enable_exclude_urls', 'neq', 1);

    // Include URLs
    $mform->addElement('select', 'originality_enable_include_urls', get_string('originality_enable_include_urls', 'plagiarism_inspera'), $ynoptions);
    $mform->setType('originality_enable_include_urls', PARAM_INT);
    $mform->setDefault('originality_enable_include_urls', 0);
    $mform->addHelpButton('originality_enable_include_urls', 'originality_enable_include_urls', 'plagiarism_inspera');

    $mform->addElement('text', 'originality_include_urls', get_string('originality_include_urls', 'plagiarism_inspera'));
    $mform->setType('originality_include_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_include_urls', 'originality_include_urls', 'plagiarism_inspera');
    // Hide input unless enabled (set to yes/1)
    $mform->hideIf('originality_include_urls', 'originality_enable_include_urls', 'neq', 1);

    // Metadata Analysis
    $mform->addElement('select', 'originality_metadata_analysis', get_string('originality_metadata_analysis', 'plagiarism_inspera'), $ynoptions);
    $mform->addHelpButton('originality_metadata_analysis', 'originality_metadata_analysis', 'plagiarism_inspera');
    $mform->setType('originality_metadata_analysis', PARAM_INT);

    // Show student report
    $share_report_options = [
        0 => get_string("showstudentreport_not_shared", "plagiarism_inspera"),
        1 => get_string("showstudentreport_immediately", "plagiarism_inspera"),
        2 => get_string("showstudentreport_after_grading", "plagiarism_inspera"),
        3 => get_string("showstudentreport_due_date", "plagiarism_inspera")
    ];
    $mform->addElement('select', 'originality_show_student_report', get_string('originality_show_student_report', 'plagiarism_inspera'), $share_report_options);
    $mform->addHelpButton('originality_show_student_report', 'originality_show_student_report', 'plagiarism_inspera');
    $mform->setType('originality_show_student_report', PARAM_INT);

    // originality_draft_submit options depend on whether submission drafts are supported.
    // If submissiondrafts exists and is enabled, show both options; otherwise, show only Immediate.
    $draftoptions_final = [
        PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_inspera"),
        PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL => get_string("submitonfinal", "plagiarism_inspera")
    ];
    $draftoptions_immediate = [
        PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_inspera")
    ];
    if ($mform->elementExists('submissiondrafts')) {
        // We cannot reliably read the runtime value here, so present both, but enforce on save.
        // However, when the module does not support drafts at all, the element won't exist.
        $mform->addElement('select', 'originality_draft_submit', get_string("originality_draft_submit", "plagiarism_inspera"), $draftoptions_final);
    } else {
        $mform->addElement('select', 'originality_draft_submit', get_string("originality_draft_submit", "plagiarism_inspera"), $draftoptions_immediate);
        $mform->setDefault('originality_draft_submit', PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE);
    }
    $mform->addHelpButton('originality_draft_submit', 'originality_draft_submit', 'plagiarism_inspera');
    $mform->setType('originality_draft_submit', PARAM_INT);

    // Translations
    $mform->addElement('select', 'originality_enable_translations', get_string('originality_enable_translations', 'plagiarism_inspera'), $ynoptions);
    $mform->addHelpButton('originality_enable_translations', 'originality_enable_translations', 'plagiarism_inspera');
    $mform->setType('originality_enable_translations', PARAM_INT);

    $mform->addElement('select', 'originality_translation_languages', get_string('originality_translation_languages', 'plagiarism_inspera'), $languages, ['multiple' => true]);
    $mform->setType('originality_translation_languages', PARAM_TAGLIST);
    $mform->addHelpButton('originality_translation_languages', 'originality_translation_languages', 'plagiarism_inspera');
    $mform->hideIf('originality_translation_languages', 'originality_enable_translations', 'eq', 0);

    $contentoptions = array(PLAGIARISM_INSPERA_RESTRICTCONTENTNO => get_string('restrictcontentno', 'plagiarism_inspera'), PLAGIARISM_INSPERA_RESTRICTCONTENTFILES => get_string('restrictcontentfiles', 'plagiarism_inspera'), PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT => get_string('restrictcontenttext', 'plagiarism_inspera'));

    $mform->addElement('select', 'originality_restrictcontent', get_string('originality_restrictcontent', 'plagiarism_inspera'), $contentoptions);
    $mform->addHelpButton('originality_restrictcontent', 'originality_restrictcontent_teachers', 'plagiarism_inspera');
    $mform->setType('originality_restrictcontent', PARAM_INT);
}

/**
 * Used to obtain allowed file types
 *
 * @param boolean $checkdb
 * @return array()
 */
function plagiarism_inspera_default_allowed_file_types($checkdb = false) {
    global $DB;
    $filetypes = array('doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'sxw'  => 'application/vnd.sun.xml.writer',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'wps'  => 'application/vnd.ms-works',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'pages' => 'application/x-iwork-pages-sffpages',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ps' => 'application/postscript',
        'hwp' => 'application/x-hwp');

    if ($checkdb) {
        // Get all filetypes from db as well.
        $sql = 'SELECT name, value FROM {config_plugins} WHERE plugin = :plugin AND ' . $DB->sql_like('name', ':name');
        $types = $DB->get_records_sql($sql, array('name' => 'ext_%', 'plugin' => 'plagiarism_inspera'));
        foreach ($types as $type) {
            $ext = strtolower(str_replace('ext_', '', $type->name));
            $filetypes[$ext] = $type->value;
        }
    }

    return $filetypes;

}

/**
 * Updates or inserts a plagiarism submission record for a file.
 *
 * Finds a submission based on cmid, userid, and file hash. If it
 * doesn't exist, a new one is created.
 *
 * @param int $cmid course module id
 * @param int $userid user id
 * @param stored_file|string $file A stored_file object or a local file path (for temp files).
 * @param int|null $relateduserid relateduserid if passed.
 * @return stdClass The plagiarism submission record from {plagiarism_inspera_subs}.
 */
function plagiarism_inspera_get_plagiarism_file($cmid, $userid, $file, $relateduserid = null) {
    global $DB;

    if (is_string($file)) { // This is a local file path.
        $filehash = $file;
        $filename = basename($file);
    } else {
        $filehash = (!empty($file->externalid)) ? $file->externalid : $file->get_contenthash();
        $filename = (!empty($file->filename)) ? $file->filename : $file->get_filename();
    }

    // Now update or insert record into originality_files.
    $plagiarismfile = $DB->get_record_sql(
        "SELECT * FROM {plagiarism_inspera_subs}
                                 WHERE cm = ? AND userid = ? AND " .
        "externalid = ?",
        array($cmid, $userid, $filehash));
    if (!empty($plagiarismfile)) {
        return $plagiarismfile;
    } else {
        // Check if record exists for this and we just need to update identifier with path for resubmission.
        if (strpos($filename, 'content-') === 0 && is_string($file) && file_exists($file)) {
            // Get file hash.
            $externalid = sha1(file_get_contents($file));
            $plagiarismfile = $DB->get_record_sql(
                "SELECT * FROM {plagiarism_inspera_subs}
                                 WHERE cm = ? AND userid = ? AND " .
                "externalid = ?",
                array($cmid, $userid, $externalid));
            if (!empty($plagiarismfile)) {
                $plagiarismfile->externalid = $filehash;
                $plagiarismfile->filename = $filename;
                $plagiarismfile->status = 'pending';
                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                return $plagiarismfile;
            }
        }
        $plagiarismfile = new stdClass();
        $plagiarismfile->cm = $cmid;
        $plagiarismfile->userid = $userid;
        $plagiarismfile->relateduserid = $relateduserid;
        $plagiarismfile->externalid = $filehash;
        $plagiarismfile->status = 'pending';
        $plagiarismfile->timesubmitted = time();
        $plagiarismfile->revision = 0;
        if (!$pid = $DB->insert_record('plagiarism_inspera_subs', $plagiarismfile)) {
            debugging("insert into originality_files failed");
        }
        $plagiarismfile->id = $pid;
        return $plagiarismfile;
    }
}

/**
 * Queues a specific file for processing by the plagiarism API.
 *
 * This creates or updates a record in the 'plagiarism_inspera_subs' table
 * for the scheduled task to pick up. If a record already exists for this file,
 * it will be updated instead of creating a duplicate.
 *
 * @param int $cmid The course module ID.
 * @param int $userid The ID of the user who submitted.
 * @param \stored_file|stdClass $file The Moodle stored_file object or temp file object to process.
 * @param int|null $relateduserid The user ID of the person submitting on behalf of (optional).
 * @param int|null $submissionid The ID of the assign_submission record.
 * @return void
 */
function plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid = null, ?int $submissionid = null) {
    global $DB, $CFG;

    // === RESOLVE SUBMISSION ID ===
    if (empty($submissionid)) {
        if ($file instanceof \stored_file) {
            $comp = $file->get_component();
            if ($comp === 'assignsubmission_file' || $comp === 'assignsubmission_onlinetext') {
                $submissionid = $file->get_itemid();
            }
        }

        if (empty($submissionid)) {
            $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                require_once($CFG->dirroot . '/mod/assign/locallib.php');
                $context = \context_module::instance($cm->id);
                $assign = new \assign($context, $cm, null);
                $submission = $assign->get_user_submission($userid, false);
                if ($submission) {
                    $submissionid = $submission->id;
                }
            }
        }
    }
    $submissionid = (int)$submissionid;

    // Get plagiarism settings.
    $plagiarismvalues = $DB->get_records_menu('plagiarism_inspera_config',
        array('cm' => $cmid), '', 'name, value');

    // Determine identifiers.
    if ($file instanceof \stored_file) {
        $filename = $file->get_filename();
        $storedfileid = $file->get_id();
        $identifier = null;
    } else if (is_object($file) && isset($file->filepath)) {
        $filename = basename($file->filepath);
        $storedfileid = null;
        $identifier = $file->filepath;
    } else {
        return;
    }

    // Extension check.
    $pathinfo = pathinfo($filename);
    if (empty($pathinfo['extension'])) {
        return;
    }
    $ext = strtolower($pathinfo['extension']);

    // Allowed file types check.
    if (isset($plagiarismvalues['originality_allowallfile']) && empty($plagiarismvalues['originality_allowallfile'])) {
        $allowedtypes = !empty($plagiarismvalues['originality_selectfiletypes'])
            ? explode(',', $plagiarismvalues['originality_selectfiletypes'])
            : array();
        $allowedtypes[] = 'html';
        $allowedtypes[] = 'htm';
        if (!in_array($ext, $allowedtypes)) {
            return;
        }
    }

    // === FIND EXISTING RECORD ===
    $existingrecord = null;
    if ($storedfileid) {
        if ($submissionid > 0) {
            $existingrecord = $DB->get_record('plagiarism_inspera_subs', [
                'submissionid' => $submissionid,
                'storedfileid' => $storedfileid
            ]);
        } else {
            $existingrecord = $DB->get_record('plagiarism_inspera_subs', [
                'cm' => $cmid,
                'userid' => $userid,
                'storedfileid' => $storedfileid
            ]);
        }
    } else if ($identifier) {
        // For online text, we identify by submissionid and the absence of a storedfileid.
        if ($submissionid > 0) {
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE submissionid = ? AND storedfileid IS NULL
                    ORDER BY timecreated DESC";
            $existingrecord = $DB->get_record_sql($sql, [$submissionid], IGNORE_MULTIPLE);
        } else {
            $sql = "SELECT * FROM {plagiarism_inspera_subs}
                    WHERE cm = ? AND userid = ? AND storedfileid IS NULL
                    ORDER BY timecreated DESC";
            $existingrecord = $DB->get_record_sql($sql, [$cmid, $userid], IGNORE_MULTIPLE);
        }
    }

    $currenttime = time();

    // === INSERT OR UPDATE LOGIC ===
    if ($existingrecord) {
        $status = $existingrecord->status ?? '';

        // 1. If currently processing, don't double-queue.
        if (in_array($status, ['pending', 'report_requested', 'processing'])) {
            return;
        }

        // 2. Handle records that have already been attempted via API.
        if (!empty($existingrecord->externalid)) {

            // IF ERROR: Reset the existing row.
            if ($status === 'error' || $status === 'external_error') {
                $existingrecord->status = 'report_requested';
                $existingrecord->description = ''; // Clear the error message.
                $existingrecord->externalid = '';
                $existingrecord->identifier = $identifier;
                $existingrecord->timemodified = $currenttime;
                $DB->update_record('plagiarism_inspera_subs', $existingrecord);
                return;
            }

            // IF SUCCESSFUL FILE: Do nothing (files are immutable).
            if ($storedfileid) {
                return;
            }

            // IF SUCCESSFUL ONLINE TEXT: Mark as superseded so a new one can be created.
            $existingrecord->status = 'superseded';
            $existingrecord->timemodified = $currenttime;
            $DB->update_record('plagiarism_inspera_subs', $existingrecord);

            // Fall through to the NEW record creation at the bottom.
        } else {
            // No External ID yet: Just update the existing row and reset to queue.
            $existingrecord->status = 'report_requested';
            $existingrecord->description = '';
            $existingrecord->identifier = $identifier;
            $existingrecord->timemodified = $currenttime;
            $DB->update_record('plagiarism_inspera_subs', $existingrecord);
            return;
        }
    }

    // 3. Create NEW record (for brand new files or superseded text).
    $record = new \stdClass();
    $record->cm = $cmid;
    $record->userid = $userid;
    $record->relateduserid = $relateduserid;
    $record->submissionid = $submissionid;
    $record->storedfileid = $storedfileid;
    $record->identifier = $identifier;
    $record->status = 'report_requested';
    $record->timecreated = $currenttime;
    $record->timemodified = $currenttime;
    $record->description = '';

    $DB->insert_record('plagiarism_inspera_subs', $record);
}

/**
 * Cleans up orphaned plagiarism records where the associated file has been deleted.
 *
 * This should be called periodically (e.g., from a scheduled task) to remove
 * records for files that no longer exist in Moodle's file storage.
 *
 * @return int Number of records cleaned up
 */
function plagiarism_inspera_cleanup_orphaned_records() {
    global $DB;

    $fs = get_file_storage();
    $cleaned = 0;

    // Get all records with storedfileid that haven't been sent to API yet
    $records = $DB->get_recordset_select('plagiarism_inspera_subs',
        'storedfileid IS NOT NULL AND (status = ? OR status = ?)',
        ['report_requested', 'pending']);

    foreach ($records as $record) {
        // Check if file still exists
        $file = $fs->get_file_by_id($record->storedfileid);
        if (!$file) {
            // File was deleted - remove the record if it hasn't been sent to API
            if (empty($record->externalid)) {
                $DB->delete_records('plagiarism_inspera_subs', ['id' => $record->id]);
                $cleaned++;
            } else {
                // Mark as error since file is gone but was already submitted
                $record->status = 'error';
                $record->description = 'Stored file deleted after submission';
                $DB->update_record('plagiarism_inspera_subs', $record);
            }
        }
    }
    $records->close();

    // Clean up temporary files for online text that are too old (> 7 days)
    $oldtime = time() - (7 * 24 * 60 * 60);
    $oldrecords = $DB->get_recordset_select('plagiarism_inspera_subs',
        'identifier IS NOT NULL AND timecreated < ? AND (status = ? OR status = ? OR status = ?)',
        [$oldtime, 'report_requested', 'error', 'superseded']);

    foreach ($oldrecords as $record) {
        if (!empty($record->identifier) && file_exists($record->identifier)) {
            unlink($record->identifier);
        }
        if (empty($record->externalid)) {
            $DB->delete_records('plagiarism_inspera_subs', ['id' => $record->id]);
            $cleaned++;
        }
    }
    $oldrecords->close();

    return $cleaned;
}

/**
 * Creates a temporary file from a string of text content.
 *
 * Used for processing online text submissions.
 *
 * @param int $cmid The course module ID.
 * @param int $courseid The course ID.
 * @param int $userid The user ID.
 * @param string $content The text content to write to the file.
 * @return stdClass An object with ->filepath and ->filename properties.
 */
function plagiarism_inspera_create_temp_file($cmid, $courseid, $userid, $content, $submissionid) {
    global $CFG;
    $filename = "onlinetext_{$cmid}_{$userid}_{$submissionid}.html";
    $filepath = $CFG->tempdir . "/plagiarism_inspera/" . $filename;

    if (!is_dir(dirname($filepath))) {
        mkdir(dirname($filepath), $CFG->directorypermissions, true);
    }

    // Sanitize content before wrapping in HTML
    // format_text() applies Moodle's content filters and security measures
    $cleanedcontent = format_text($content, FORMAT_HTML, [
        'context' => context_system::instance(),
        'filter' => false, // Don't apply filters, just clean
        'noclean' => false  // DO apply cleaning
    ]);

    // Wrap content in basic HTML structure if not already HTML
    $htmlcontent = $cleanedcontent;
    // Check if content starts with a DOCTYPE or <html> tag (ignoring whitespace)
    if (!preg_match('/^\s*(<!DOCTYPE\s+html.*?>|<html[\s>])/i', $content)) {
        $htmlcontent = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Online Text Submission</title></head><body>' . $content . '</body></html>';
    }

    file_put_contents($filepath, $htmlcontent);

    $file = new \stdClass();
    $file->filepath = $filepath;
    $file->filename = $filename;
    return $file;
}

/**
 * Sends a file to the Originality API.
 *
 * This function handles the two-step process:
 * 1. Create a submission (metadata-only) to get a documentId and presigned URL.
 * 2. Upload the file content to the presigned URL.
 *
 * @param stdClass $plagiarismfile The submission record from {plagiarism_inspera_subs}.
 * @param api_client $client An instance of the API client.
 * @return bool|void False on failure.
 */
function plagiarism_inspera_send_file($plagiarismfile, api_client $client) {
    global $DB;

    // Step 1: Create submission if not already done
    if (empty($plagiarismfile->externalid)) {
        $user = $DB->get_record('user', ['id' => $plagiarismfile->userid], '*', MUST_EXIST);

        // --- BLIND MARKING CHECK ---
        // 1. Default Author Name
        $authorname = $user->firstname . ' ' . $user->lastname;
        $isblind = false;
        $isteamsubmission = false;

        // 2. Check if this is an Assignment with Blind Marking enabled
        try {
            $cm = get_coursemodule_from_id('', $plagiarismfile->cm);

            if ($cm && $cm->modname === 'assign') {
                // Fetch both 'blindmarking' and 'teamsubmission'
                $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, blindmarking, teamsubmission');

                if ($assign) {
                    if (!empty($assign->blindmarking)) {
                        $authorname = (string) $user->id; // Anonymize author
                        $isblind = true;
                    }
                    if (!empty($assign->teamsubmission)) {
                        $isteamsubmission = true; // Assignment is configured for groups
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback: If cm lookup fails, stick to the default name or log it.
            // keeping $authorname as fullname
        }

        $filename = 'submission.html';
        $mimetype = 'text/html';

        // If we have a Moodle stored file, use its filename/mimetype
        if (!empty($plagiarismfile->storedfileid)) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($plagiarismfile->storedfileid);
            if ($file) {
                $filename = $file->get_filename();
                $mimetype = $file->get_mimetype();
            }
        }
        // If we have a temporary file path (online text), keep default HTML values
        else if (!empty($plagiarismfile->identifier) && file_exists($plagiarismfile->identifier)) {
            // filename and mimetype already set to HTML defaults
        }

        // Get originality settings for the course module.
        $settings = plagiarism_plugin_inspera::get_settings_by_module($plagiarismfile->cm);

        // Add the blind marking flag to the settings array to pass it to the API client
        if ($isblind) {
            $settings['anonymous_submissions'] = true;
        }

        // 1. Build educators list (teachers for this assignment)
        $educators = [];
        try {
            $cm = get_coursemodule_from_id(null, $plagiarismfile->cm, 0, false, MUST_EXIST);
            $context = \context_module::instance($plagiarismfile->cm);

            $users = [];
            // Prefer assignment grading capability when module is assign
            if (!empty($cm->modname) && $cm->modname === 'assign') {
                $users = get_enrolled_users($context, 'mod/assign:grade', 0);
            }
            // Fallback to course editing capability if none found or module is different
            if (empty($users)) {
                $coursecontext = \context_course::instance($cm->course);
                $users = get_enrolled_users($coursecontext, 'moodle/course:update', 0);
            }

            if (!empty($users)) {
                foreach ($users as $u) {
                    // Ensure we have required fields
                    if (empty($u->id) || empty($u->email)) { continue; }
                    $educators[] = [
                        'id' => (string)$u->id,
                        'name' => fullname($u),
                        'email' => $u->email,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal if educator fetching fails; proceed without educators
        }

        // --- 2. BUILD STUDENTS LIST  ---
        $students = [];
        // Only run this logic if the Assignment is actually configured for Groups
        if ($isteamsubmission && !empty($plagiarismfile->submissionid)) {
            try {
                $submission = $DB->get_record('assign_submission', ['id' => $plagiarismfile->submissionid]);

                // Check if the submission actually belongs to a valid group (ID > 0)
                // This filters out "Default Group" / "No Group" (which are 0)
                if ($submission && !empty($submission->groupid)) {

                    $groupmembers = groups_get_members($submission->groupid);

                    foreach ($groupmembers as $gm) {
                        if (empty($gm->id) || empty($gm->email)) { continue; }

                        if ($isblind) {
                            $s_name = $gm->id;
                            $s_email = $gm->id . '@blind.marking';
                        } else {
                            $s_name = fullname($gm);
                            $s_email = $gm->email;
                        }

                        $students[] = [
                            'id' => (string)$gm->id,
                            'name' => $s_name,
                            'email' => $s_email
                        ];
                    }
                }
            } catch (\Throwable $e) {
                mtrace("Error fetching group members: " . $e->getMessage());
            }
        }

        // -------------------------------------------------------
        // PREPARE DTO for Metadata
        // -------------------------------------------------------
        $metadata = new \stdClass();
        $metadata->title        = $filename;
        $metadata->author       = $authorname;
        $metadata->email        = $user->email;
        $metadata->doctype      = $mimetype;
        $metadata->assignmentid = $plagiarismfile->cm;

        // Create submission
        try {
            $submission = $client->create_submission(
                $metadata,    // 1. DTO
                $settings,    // 2. Settings
                $educators,   // 3. Educators
                $students     // 4. Students
            );
        } catch (\Throwable $e) {
            // If there is any API error while creating submission
            mtrace("Error creating submission for fileid: {$plagiarismfile->id}: " . $e->getMessage());
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = $e->getMessage();
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return false;
        }

        // Store external document ID and presigned URL
        // IMPORTANT: Don't overwrite identifier field - it contains the temp file path
        $plagiarismfile->externalid   = $submission->documentId;
        $plagiarismfile->presignedurl = $submission->presignedS3Url;
        $plagiarismfile->status       = 'pending';

        $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);

        mtrace("Created submission for fileid: {$plagiarismfile->id}, documentId: {$submission->documentId}");
    }

    // Step 2: Upload file content
    $content = null;
    $mimetype = 'text/html';
    $tempfilepath = null;

    if (!empty($plagiarismfile->storedfileid)) {
        // Regular file upload
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($plagiarismfile->storedfileid);

        if (!$file) {
            mtrace("File not found for storedfileid: {$plagiarismfile->storedfileid}");
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = 'Stored file not found';
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return false;
        }

        $content = $file->get_content();
        $mimetype = $file->get_mimetype();
    }
    // Check identifier field for temporary file path (online text)
    else if (!empty($plagiarismfile->identifier)) {
        $tempfilepath = $plagiarismfile->identifier;
        $content = @file_get_contents($tempfilepath);
        if ($content === false) {
            mtrace("Failed to read temp file: {$tempfilepath}");
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = 'Failed to read temporary file';
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return false;
        }
        $mimetype = 'text/html';
        mtrace("Loading online text from temp file: {$tempfilepath}");
    }

    if (empty($content)) {
        mtrace("No content found for fileid: {$plagiarismfile->id}");
        $plagiarismfile->status = 'error';
        $plagiarismfile->description = 'No content found to upload';
        $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
        return false;
    }

    try {
        $success = $client->upload_to_presigned_url($plagiarismfile->presignedurl, $content, $mimetype);
        if ($success) {
            mtrace("Uploaded file content for documentId: {$plagiarismfile->externalid}");

            // Clean up temporary file if it exists
            if (!empty($tempfilepath) && file_exists($tempfilepath)) {
                if (unlink($tempfilepath)) {
                    mtrace("Deleted temporary file: {$tempfilepath}");
                } else {
                    mtrace("Warning: Failed to delete temporary file: {$tempfilepath}");
                }
            }
        } else {
            mtrace("Failed to upload file content for documentId: {$plagiarismfile->externalid}");
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = 'Upload to presigned URL returned failure';
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return false;
        }
    } catch (\Exception $e) {
        mtrace("Error uploading file content for documentId: {$plagiarismfile->externalid}: " . $e->getMessage());
        $plagiarismfile->status = 'external_error';
        $plagiarismfile->description = $e->getMessage();
        $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);

        // Clean up temporary file on error
        if (!empty($tempfilepath) && file_exists($tempfilepath)) {
            if (!unlink($tempfilepath)) {
                mtrace("Failed to delete temporary file during error handling: {$tempfilepath}");
            }
        }
        return false;
    }
}


/**
 * Polls the Originality API for the status of a pending submission.
 *
 * If the report is ready (status 1), it updates the database record
 * with the similarity scores and other metadata.
 *
 * @param stdClass $plagiarismfile The submission record from {plagiarism_inspera_subs}.
 * @param api_client $client An instance of the API client.
 * @return void
 */
function plagiarism_inspera_poll_file_status($plagiarismfile, api_client $client) {
    global $DB;

    if (empty($plagiarismfile->externalid) || $plagiarismfile->status !== 'pending') {
        return;
    }

    try {
        $status = $client->check_document_status($plagiarismfile->externalid);

        switch ($status->status) {
            case -1:
                // still processing
                break;
            case 0:
                // queued, do nothing
                break;
            case 1:
                // processed successfully → update record with returned data
                $plagiarismfile->status = 'finished';

                // Accept multiple possible key names/cases from API response
                $similarity = null;
                if (isset($status->originality_percentage)) {
                    $similarity = $status->originality_percentage;
                } else if (isset($status->similarity)) {
                    $similarity = $status->similarity;
                }

                $translation = null;
                if (isset($status->translation_similarity)) {
                    $translation = $status->translation_similarity;
                } else if (isset($status->translationSimilarity)) {
                    $translation = $status->translationSimilarity;
                }

                $aiindex = null;
                if (isset($status->ai_index)) {
                    $aiindex = $status->ai_index;
                } else if (isset($status->Ai_index)) {
                    $aiindex = $status->Ai_index;
                }

                $charrepl = null;
                if (isset($status->characterReplacement)) {
                    $charrepl = $status->characterReplacement;
                } else if (isset($status->characterReplacements)) {
                    $charrepl = $status->characterReplacements;
                }

                // Assign back to record (DB columns tolerate strings or numbers)
                $plagiarismfile->similarity = $similarity;
                $plagiarismfile->translation_similarity = $translation;
                $plagiarismfile->ai_index = $aiindex;
                $plagiarismfile->originality = $status->originality ?? null;
                $plagiarismfile->character_replacement = $charrepl;
                $plagiarismfile->hidden_text = $status->hiddenText ?? null;
                $plagiarismfile->image_as_text = $status->imageAsText ?? null;
                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                break;
            case 2:
            default:
                $plagiarismfile->status = 'external_error';
                $plagiarismfile->description = isset($status->message) ? (string)$status->message : json_encode($status);
                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                mtrace("Originality API returned error status for fileid {$plagiarismfile->id}. Response: " . json_encode($status));
                break;
        }

    } catch (\Exception $e) {
        $plagiarismfile->status = 'external_error';
        $plagiarismfile->description = $e->getMessage();
        $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
        mtrace("Originality API poll error for fileid {$plagiarismfile->id}: " . $e->getMessage());
    }
}

/**
 * Returns list of available statuses for filtering.
 * @return array
 */
function plagiarism_inspera_statuscodes() {
    return array(
        'pending' => get_string('status_pending', 'plagiarism_inspera'),
        'report_requested' => get_string('status_report_requested', 'plagiarism_inspera'),
        'finished' => get_string('status_finished', 'plagiarism_inspera'),
        'error' => get_string('status_error', 'plagiarism_inspera'),
        'external_error' => get_string('status_external_error', 'plagiarism_inspera'),
    );
}

/**
 * Helper function to warn admin if Cron not running correctly.
 *
 * @throws coding_exception
 * @throws dml_exception
 *
 */
function plagiarism_inspera_checkcronhealth() {
    global $DB;

    $send_files = $DB->get_record('task_scheduled', array('component' => 'plagiarism_inspera',
        'classname' => '\plagiarism_inspera\task\send_files'));
    if (empty($send_files) || $send_files->lastruntime < time() - 3600 * 0.5) { // Check if run in last 30min.
        \core\notification::add(get_string('cronwarningsendfiles', 'plagiarism_inspera'), \core\notification::ERROR);
    }
}
