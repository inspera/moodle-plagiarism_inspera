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

use plagiarism_originality\apiclient\api_client;

defined('MOODLE_INTERNAL') || die();

// Get global class.
global $CFG;
require_once($CFG->dirroot.'/plagiarism/lib.php');
require_once($CFG->dirroot . '/lib/filelib.php');


define('PLAGIARISM_ORIGINALITY_SHOW_NEVER', 0);
define('PLAGIARISM_ORIGINALITY_SHOW_ALWAYS', 1);
define('PLAGIARISM_ORIGINALITY_SHOW_AFTER_GRADING', 2);
define('PLAGIARISM_ORIGINALITY_SHOW_DUE_DATE', 3);

define('PLAGIARISM_ORIGINALITY_DRAFTSUBMIT_IMMEDIATE', 0);
define('PLAGIARISM_ORIGINALITY_DRAFTSUBMIT_FINAL', 1);

// Used by content type restriction form - inline-text vs file attachments.
define('PLAGIARISM_ORIGINALITY_RESTRICTCONTENTNO', 0);
define('PLAGIARISM_ORIGINALITY_RESTRICTCONTENTFILES', 1);
define('PLAGIARISM_ORIGINALITY_RESTRICTCONTENTTEXT', 2);

define('PLAGIARISM_ORIGINALITY_MAXATTEMPTS', 28);

/**
 * The main plugin class for Inspera Originality.
 *
 * This class handles the core logic, event handling, and settings integration
 * for the originality plagiarism plugin.
 *
 * @package    plagiarism_originality
 * @copyright  1999 onwards Martin Dougiamas (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_plugin_originality extends plagiarism_plugin {

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
        $plagiarismsettings = (array)get_config('plagiarism_originality');
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
        $records = $DB->get_records('plagiarism_originality_conf', ['cm' => $cmid]);
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
        global $DB;

        static $plagiarismvalues = [];
        $fullquizlist = false;
        $output = '';

        // ==============================
        // 1. Early exit checks
        // ==============================
        if (!empty($linkarray['component']) && strpos($linkarray['component'], 'qtype_') === 0) {
            $qtype = str_replace('qtype_', '', $linkarray['component']);

            if (!in_array($qtype, plagiarism_originality_supported_qtypes())) {
                return '';
            }

            if (empty(get_config('plagiarism_originality', 'enable_mod_quiz'))) {
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
            $plagiarismvalues[$linkarray['cmid']] = $DB->get_records_menu('plagiarism_originality_conf',
                ['cm' => $linkarray['cmid']], '', 'name,value');
        }

        // ==============================
        // 5. Add "View Originality Report" if finished
        // ==============================
        if (!empty($linkarray['cmid']) && !empty($linkarray['userid']) && !empty($linkarray['file'])) {
            // $linkarray['file'] should be a stored_file object.
            $file = $linkarray['file'];

            // Get the plagiarism record for this specific file, regardless of status.
            $record = $DB->get_record('plagiarism_originality_subs', [
                'cm' => $linkarray['cmid'],
                'userid' => $linkarray['userid'],
                'storedfileid' => $file->get_id()
            ]);

            if ($record) {
                $output .= $this->get_originality_status($record, $plagiarismvalues[$linkarray['cmid']]);
            }
        }

        // ==============================
        // 6. Add "View Originality Report" for ONLINE TEXT submissions (no file)
        // ==============================
        if (!empty($linkarray['content']) && !empty($linkarray['cmid']) && !empty($linkarray['userid'])) {
            // Fetch the latest plagiarism record for this user's online text in this CM
            $sql = "SELECT *
                    FROM {plagiarism_originality_subs}
                    WHERE cm = ? AND userid = ? AND storedfileid IS NULL
                    ORDER BY timecreated DESC";
            $textrecord = $DB->get_record_sql($sql, [$linkarray['cmid'], $linkarray['userid']], IGNORE_MULTIPLE);

            if ($textrecord) {
                $output .= $this->get_originality_status($textrecord, $plagiarismvalues[$linkarray['cmid']]);
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
        $plagiarismvalues = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => $cmid), '', 'name, value');
        if (empty($plagiarismvalues['use_originality'])) {
            // originality not in use for this cm - return.
            return true;
        }

        // Check if the module associated with this event still exists.
        if (!$DB->record_exists('course_modules', array('id' => $cmid))) {
            return true;
        }

        $userid = $eventdata['userid'];
        $relateduserid = null;

        // Check if this is a submission on-behalf.
        if (!empty($eventdata['relateduserid'])) {
            $relateduserid = $eventdata['relateduserid'];
        }

        // Check to see if restrictcontent is in use.
        $showcontent = true;
        $showfiles = true;
        if (!empty($plagiarismvalues['originality_restrictcontent'])) {
            if ($plagiarismvalues['originality_restrictcontent'] == PLAGIARISM_ORIGINALITY_RESTRICTCONTENTFILES) {
                $showcontent = false;
            } else if ($plagiarismvalues['originality_restrictcontent'] == PLAGIARISM_ORIGINALITY_RESTRICTCONTENTTEXT) {
                $showfiles = false;
            }
        }

        $charcount = plagiarism_originality_charcount();

        if ($eventdata['eventtype'] == 'assignsubmission_submitted' && empty($eventdata['other']['submission_editable'])) {
            // Assignment-specific functionality:
            // This is a 'finalize' event. No files from this event itself,
            // but need to check if files from previous events need to be submitted for processing.
            $result = true;
            if (isset($plagiarismvalues['originality_draft_submit']) &&
                $plagiarismvalues['originality_draft_submit'] == PLAGIARISM_ORIGINALITY_DRAFTSUBMIT_FINAL) {
                // Any files attached to previous events were not submitted.
                // These files are now finalized, and should be submitted for processing.
                require_once("$CFG->dirroot/mod/assign/locallib.php");
                require_once("$CFG->dirroot/mod/assign/submission/file/locallib.php");

                $modulecontext = context_module::instance($cmid);

                if ($showfiles) { // If we should be handling files.
                    $fs = get_file_storage();
                    if ($files = $fs->get_area_files($modulecontext->id, 'assignsubmission_file',
                        ASSIGNSUBMISSION_FILE_FILEAREA, $eventdata['objectid'], "id", false)) {
                        foreach ($files as $file) {
                            plagiarism_originality_queue_file($cmid, $userid, $file, $relateduserid);
                        }
                    }
                }

                if ($showcontent) { // If we should be handling in-line text.
                    $submission = $DB->get_record('assignsubmission_onlinetext', array('submission' => $eventdata['objectid']));
                    if (!empty($submission) && strlen(utf8_decode(strip_tags($submission->onlinetext))) >= $charcount) {
                        $file = plagiarism_originality_create_temp_file($cmid, $eventdata['courseid'], $userid, $submission->onlinetext);
                        plagiarism_originality_queue_file($cmid, $userid, $file, $relateduserid);
                    }
                }
            }
            return $result;
        }

        if (isset($plagiarismvalues['originality_draft_submit']) &&
            $plagiarismvalues['originality_draft_submit'] == PLAGIARISM_ORIGINALITY_DRAFTSUBMIT_FINAL) {
            // Assignment-specific functionality:
            // Files should only be sent for checking once "finalized".
            return true;
        }

        // Text is attached.
        $result = true;
        if (!empty($eventdata['other']['content']) && $showcontent &&
            strlen(utf8_decode(strip_tags($eventdata['other']['content']))) >= $charcount) {

            $file = plagiarism_originality_create_temp_file($cmid, $eventdata['courseid'], $userid, $eventdata['other']['content']);
            plagiarism_originality_queue_file($cmid, $userid, $file, $relateduserid);
        }

        // Normal situation: 1 or more assessable files attached to event, ready to be checked.
        if (!empty($eventdata['other']['pathnamehashes']) && $showfiles) {
            foreach ($eventdata['other']['pathnamehashes'] as $hash) {
                $fs = get_file_storage();
                $efile = $fs->get_file_by_hash($hash);

                if (empty($efile)) {
                    continue;
                } else if ($efile->get_filename() === '.') {
                    // This 'file' is actually a directory - nothing to submit.
                    continue;
                }

                plagiarism_originality_queue_file($cmid, $userid, $efile, $relateduserid);
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
                $url = new moodle_url('/plagiarism/originality/redirect.php', ['id' => $record->id]);
                $score = round($record->similarity);
                $threshold = (int)($settings['originality_context_threshold'] ?? 50);

                $scoreclass = 'originality-score ' . ($score > $threshold ? 'high' : 'low');
                $linkprefix = get_string('reportlinkprefix', 'plagiarism_originality');
                $scoretext = get_string('reportlinkscore', 'plagiarism_originality', $score);

                $iconhtml = $OUTPUT->pix_icon('logo', $linkprefix, 'plagiarism_originality',
                    ['class' => 'originality-logo-icon']);
                $scorehtml = html_writer::tag('span', $scoretext, ['class' => $scoreclass]);

                $linkcontent = html_writer::link($url, $iconhtml . ' ' . $linkprefix . ' ' . $scorehtml,
                    ['target' => '_blank']);
                $linkclass = 'plagiarism-originality-reportlink';
                break;

            case 'pending':
            case 'report_requested':
                $linkcontent = get_string('statuspending', 'plagiarism_originality');
                break;

            case 'error':
            case 'external_error':
                $linkcontent = get_string('statuserror', 'plagiarism_originality');
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
function plagiarism_originality_charcount() {
    $charcount = get_config('plagiarism_originality', 'charcount');
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
function plagiarism_originality_cm_use($cmid) {
    global $DB;
    static $useoriginality = array();
    if (!isset($useoriginality[$cmid])) {
        $pvalues = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => $cmid), '', 'name,value');
        if (!empty($pvalues['use_originality'])) {
            $useoriginality[$cmid] = $pvalues;
        } else {
            $useoriginality[$cmid] = false;
        }
    }
    return $useoriginality[$cmid];
}

/**
 * Function to list question types that originality supports.
 * @return array
 *
 */
function plagiarism_originality_supported_qtypes() {
    return array('essay');
}

/**
 * Returns a list of Moodle modules supported by this plugin.
 *
 * @return string[] An array of module names (e.g., 'assign', 'quiz').
 */
function plagiarism_originality_supported_modules() {
    global $CFG;
    $supportedmodules = array('assign', 'forum', 'workshop', 'quiz');
    return $supportedmodules;
}

/**
 * Hook to save plagiarism specific settings on a module settings page.
 *
 * @param stdClass $data
 * @param stdClass $course
 */
function plagiarism_originality_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    $plugin = new plagiarism_plugin_originality();
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
        //$contextmodule = context_module::instance($data->coursemodule);

        // First get existing values.
        if (empty($data->coursemodule)) {
            debugging("originality settings failure - no coursemodule set in form data, originality could not be enabled.");
            return $data;
        }
        $existingelements = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => $data->coursemodule),
            '', 'name, id');
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
                $DB->update_record('plagiarism_originality_conf', $newelement);
            } else {
                $DB->insert_record('plagiarism_originality_conf', $newelement);
            }

        }

    }
    return $data;
}

/**
 * Hook to add plagiarism specific settings to a module settings page.
 *
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 */
function plagiarism_originality_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB, $CFG;

    // === 1. Guard Clauses (Early Exit) ===

    $plugin = new plagiarism_plugin_originality();
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

    // === 2. Load Settings Data ===

    $cmid = null;
    if ($cm = $formwrapper->get_coursemodule()) {
        $cmid = $cm->id;
    }
    $context = context_course::instance($formwrapper->get_course()->id);
    $plagiarismelements = $plugin->config_options();

    // Load settings specific to this activity (if editing).
    $plagiarismvalues = [];
    if (!empty($cmid)) {
        $plagiarismvalues = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => $cmid), '', 'name, value');
    }

    // Get Admin Defaults - cmid(0) is the default list.
    $plagiarismdefaults = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => 0), '', 'name, value');

    // === 3. Add Form Elements (Based on Capability) ===

    // Check user's permissions.
    if (has_capability('plagiarism/originality:enable', $context)) {
        // User HAS permission: Build and display all the visible form fields.
        // This helper function is responsible for both addElement() and setType().
        plagiarism_originality_get_form_elements($mform);

        // Add conditional display logic for the visible form.
        if ($mform->elementExists('originality_draft_submit') && $mform->elementExists('submissiondrafts')) {
            $mform->hideif('originality_draft_submit', 'submissiondrafts', 'eq', 0);
        }

        if (!has_capability('plagiarism/originality:resubmitonclose', $context) &&
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
        }

        // We MUST set the PARAM types for these hidden fields to ensure
        // data is cleaned securely when the form is saved by this user.
        $mform->setType('use_originality', PARAM_INT);
        $mform->setType('originality_archive', PARAM_INT);
        $mform->setType('originality_restrictcontent', PARAM_INT);
        $mform->setType('originality_selectfiletypes', PARAM_TAGLIST);
        $mform->setType('originality_allowallfile', PARAM_INT);
        $mform->setType('originality_enable_ai', PARAM_INT);
        $mform->setType('originality_draft_submit', PARAM_INT);
        $mform->setType('originality_show_student_report', PARAM_INT);
        $mform->setType('originality_enable_translations', PARAM_INT);
        $mform->setType('originality_translation_languages', PARAM_TAGLIST);
        $mform->setType('originality_enable_context_similarity', PARAM_INT);
        $mform->setType('originality_context_threshold', PARAM_INT);
        $mform->setType('originality_enable_include_urls', PARAM_INT);
        $mform->setType('originality_include_urls', PARAM_TEXT);
        $mform->setType('originality_enable_exclude_urls', PARAM_INT);
        $mform->setType('originality_exclude_urls', PARAM_TEXT);
    }

    // === 4. Set Default Values ===

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

    // === 5. Handle Advanced AND Locked Settings (3-Tier Logic) ===

    $defaultelementadvanced = 'originality_advanceditems_' . str_replace('mod_', '', $modulename);

    if (!empty($plagiarismdefaults[$defaultelementadvanced])) {
        $targetsettings = explode(',', $plagiarismdefaults[$defaultelementadvanced]);

        // Check permissions
        $can_see_advanced = has_capability('plagiarism/originality:advancedsettings', $context);
        $can_edit_locked  = has_capability('plagiarism/originality:manage_locked_settings', $context);

        foreach ($targetsettings as $name) {
            if ($mform->elementExists($name)) {

                // --- CLEANUP LOGIC (Only runs if user CANNOT edit) ---
                if (!$can_edit_locked) {

                    // 1. CLEANUP FILE TYPES
                    if ($name === 'originality_selectfiletypes') {
                        // Check the EFFECTIVE value of 'allowallfile'
                        $allowval = $plagiarismvalues['originality_allowallfile'] ?? null;
                        if ($allowval === null) {
                            $defaultkey = 'originality_allowallfile_' . str_replace('mod_', '', $modulename);
                            $allowval = $plagiarismdefaults[$defaultkey] ?? 0;
                        }

                        // If Allow All is YES, hide the empty list completely
                        if ($allowval == 1) {
                            if ($element = $mform->getElement($name)) {
                                $value = $element->getValue();
                                $mform->removeElement($name);
                                $mform->addElement('hidden', $name, $value);
                            }
                            continue; // Done with this item
                        }
                    }

                    // 2. CLEANUP TRANSLATIONS (Optional: Makes it consistent with file types)
                    if ($name === 'originality_translation_languages') {
                        $transval = $plagiarismvalues['originality_enable_translations'] ?? null;
                        if ($transval === null) {
                            $defaultkey = 'originality_enable_translations_' . str_replace('mod_', '', $modulename);
                            $transval = $plagiarismdefaults[$defaultkey] ?? 0;
                        }

                        // If Translations are NO, hide the list completely
                        if ($transval == 0) {
                            if ($element = $mform->getElement($name)) {
                                $value = $element->getValue();
                                $mform->removeElement($name);
                                $mform->addElement('hidden', $name, $value);
                            }
                            continue; // Done with this item
                        }
                    }
                }
                // --- END CLEANUP LOGIC ---


                // TIER 3: NO ACCESS (User lacks 'advancedsettings' cap)
                if (!$can_see_advanced) {
                    if ($element = $mform->getElement($name)) {
                        $value = $element->getValue();
                        // If value is null, check default
                        if ($value === null && isset($plagiarismvalues[$name])) {
                            $value = $plagiarismvalues[$name];
                        }
                        $mform->removeElement($name);
                        $mform->addElement('hidden', $name, $value);
                    }
                    continue;
                }

                // If we are here, the user CAN see them.
                $mform->setAdvanced($name, true);

                // TIER 2: READ ONLY (User has 'advancedsettings' but NOT 'manage_locked_settings')
                if (!$can_edit_locked) {
                    $mform->freeze($name);
                    if ($element = $mform->getElement($name)) {
                        $mform->setConstant($name, $element->getValue());
                    }
                }

                // TIER 1: FULL EDIT (User has 'manage_locked_settings')
                // Do nothing.
            }
        }
    }

    // === 6. Handle Module-Specific Logic ===

    // Now handle content restriction settings.
    if ($modulename == 'mod_assign' && $mform->elementExists("submissionplugins")) { // This should be mod_assign
        $mform->hideif('originality_restrictcontent', 'assignsubmission_onlinetext_enabled');
    } else if ($modulename != 'mod_forum' && $modulename != 'mod_hsuforum') {
        // Freeze setting for modules that don't support content type restriction.
        $mform->setDefault('originality_restrictcontent', 0);
        $mform->hardFreeze('originality_restrictcontent');
    }
}


/**
 * Adds the list of plagiarism settings to a form.
 *
 * @param object $mform - Moodle form object.
 */
function plagiarism_originality_get_form_elements($mform) {
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
        PLAGIARISM_ORIGINALITY_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_originality"),
        PLAGIARISM_ORIGINALITY_DRAFTSUBMIT_FINAL => get_string("submitonfinal", "plagiarism_originality")
    );

    $mform->addElement('header', 'plagiarismdesc', get_string('originality', 'plagiarism_originality'));
    $mform->addElement('select', 'use_originality', get_string("use_originality", "plagiarism_originality"), $ynoptions);

    // Metadata Analysis
    $mform->addElement('select', 'originality_metadata_analysis', get_string('originality_metadata_analysis', 'plagiarism_originality'), $ynoptions);
    $mform->addHelpButton('originality_metadata_analysis', 'originality_metadata_analysis', 'plagiarism_originality');
    $mform->setType('originality_metadata_analysis', PARAM_INT);
    // AI Authorship
    $mform->addElement('select', 'originality_enable_ai', get_string('originality_enable_ai', 'plagiarism_originality'), $ynoptions);
    $mform->addHelpButton('originality_enable_ai', 'originality_enable_ai', 'plagiarism_originality');
    $mform->setType('originality_enable_ai', PARAM_INT);
    // Archive Documents
    $mform->addElement('select', 'originality_archive', get_string('originality_archive', 'plagiarism_originality'), $ynoptions);
    $mform->addHelpButton('originality_archive', 'originality_archive', 'plagiarism_originality');
    $mform->setType('originality_archive', PARAM_INT);
    // Translations
    $mform->addElement('select', 'originality_enable_translations', get_string('originality_enable_translations', 'plagiarism_originality'), $ynoptions);
    $mform->addHelpButton('originality_enable_translations', 'originality_enable_translations', 'plagiarism_originality');
    $mform->setType('originality_enable_translations', PARAM_INT);
    $mform->addElement('select', 'originality_translation_languages', get_string('originality_translation_languages', 'plagiarism_originality'), $languages, ['multiple' => true]);
    $mform->setType('originality_translation_languages', PARAM_TAGLIST);
    $mform->disabledIf('originality_translation_languages', 'originality_enable_translations', 'eq', 0);

    // Contextual Similarity
    $mform->addElement('select', 'originality_enable_context_similarity',
        get_string('originality_enable_context_similarity', 'plagiarism_originality'), $ynoptions);
    $mform->setType('originality_enable_context_similarity', PARAM_INT);
    $mform->setDefault('originality_enable_context_similarity', 0);
    // Threshold input (always optional in the form)
    $mform->addElement('text', 'originality_context_threshold',
        get_string('originality_context_threshold', 'plagiarism_originality'));
    $mform->setType('originality_context_threshold', PARAM_INT);
    $mform->setDefault('originality_context_threshold', 50);
    $mform->addHelpButton('originality_context_threshold', 'originality_context_threshold', 'plagiarism_originality');
    // Hide threshold unless select is set to yes
    $mform->hideIf('originality_context_threshold', 'originality_enable_context_similarity', 'neq', 1);
    // Include URLs
    $mform->addElement('select', 'originality_enable_include_urls',
        get_string('originality_enable_include_urls', 'plagiarism_originality'), $ynoptions);
    $mform->setType('originality_enable_include_urls', PARAM_INT);
    $mform->setDefault('originality_enable_include_urls', 0);
    $mform->addElement('text', 'originality_include_urls',
        get_string('originality_include_urls', 'plagiarism_originality'));
    $mform->setType('originality_include_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_include_urls', 'originality_include_urls', 'plagiarism_originality');
    // Hide input unless enabled (set to yes/1)
    $mform->hideIf('originality_include_urls', 'originality_enable_include_urls', 'neq', 1);
    // Exclude URLs
    $mform->addElement('select', 'originality_enable_exclude_urls',
        get_string('originality_enable_exclude_urls', 'plagiarism_originality'), $ynoptions);
    $mform->setType('originality_enable_exclude_urls', PARAM_INT);
    $mform->setDefault('originality_enable_exclude_urls', 0);

    $mform->addElement('text', 'originality_exclude_urls',
        get_string('originality_exclude_urls', 'plagiarism_originality'));
    $mform->setType('originality_exclude_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_exclude_urls', 'originality_exclude_urls', 'plagiarism_originality');

    $mform->hideIf('originality_exclude_urls', 'originality_enable_exclude_urls', 'neq', 1);

    // Show student report
    $share_report_options = [
        0 => get_string("showstudentreport_not_shared", "plagiarism_originality"),
        1 => get_string("showstudentreport_immediately", "plagiarism_originality"),
        2 => get_string("showstudentreport_after_grading", "plagiarism_originality"),
        3 => get_string("showstudentreport_due_date", "plagiarism_originality")
    ];
    $mform->addElement('select', 'originality_show_student_report', get_string('originality_show_student_report', 'plagiarism_originality'), $share_report_options);
    $mform->addHelpButton('originality_show_student_report', 'originality_show_student_report', 'plagiarism_originality');

    if ($mform->elementExists('submissiondrafts')) {
        $mform->addElement('select', 'originality_draft_submit',
            get_string("originality_draft_submit", "plagiarism_originality"), $draftoptions);
    }

    $filetypes = plagiarism_originality_default_allowed_file_types(true);

    $supportedfiles = array();
    foreach ($filetypes as $ext => $mime) {
        $supportedfiles[$ext] = $ext;
    }
    $mform->addElement('select', 'originality_allowallfile', get_string('originality_allowallfile', 'plagiarism_originality'), $ynoptions);
    $mform->addHelpButton('originality_allowallfile', 'originality_allowallfile', 'plagiarism_originality');
    $mform->setType('originality_allowallfile', PARAM_INT);
    $mform->addElement('select', 'originality_selectfiletypes', get_string('originality_selectfiletypes', 'plagiarism_originality'),
        $supportedfiles, array('multiple' => true));
    $mform->setType('originality_selectfiletypes', PARAM_TAGLIST);

    $contentoptions = array(PLAGIARISM_ORIGINALITY_RESTRICTCONTENTNO => get_string('restrictcontentno', 'plagiarism_originality'),
        PLAGIARISM_ORIGINALITY_RESTRICTCONTENTFILES => get_string('restrictcontentfiles', 'plagiarism_originality'),
        PLAGIARISM_ORIGINALITY_RESTRICTCONTENTTEXT => get_string('restrictcontenttext', 'plagiarism_originality'));
    $mform->addElement('select', 'originality_restrictcontent',
        get_string('originality_restrictcontent', 'plagiarism_originality'), $contentoptions);
    $mform->addHelpButton('originality_restrictcontent', 'originality_restrictcontent', 'plagiarism_originality');
    $mform->setType('originality_restrictcontent', PARAM_INT);

}

/**
 * Used to obtain allowed file types
 *
 * @param boolean $checkdb
 * @return array()
 */
function plagiarism_originality_default_allowed_file_types($checkdb = false) {
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
        $types = $DB->get_records_sql($sql, array('name' => 'ext_%', 'plugin' => 'plagiarism_originality'));
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
 * @return stdClass The plagiarism submission record from {plagiarism_originality_subs}.
 */
function plagiarism_originality_get_plagiarism_file($cmid, $userid, $file, $relateduserid = null) {
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
        "SELECT * FROM {plagiarism_originality_subs}
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
                "SELECT * FROM {plagiarism_originality_subs}
                                 WHERE cm = ? AND userid = ? AND " .
                "externalid = ?",
                array($cmid, $userid, $externalid));
            if (!empty($plagiarismfile)) {
                $plagiarismfile->externalid = $filehash;
                $plagiarismfile->filename = $filename;
                $plagiarismfile->status = 'pending';
                $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
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
        if (!$pid = $DB->insert_record('plagiarism_originality_subs', $plagiarismfile)) {
            debugging("insert into originality_files failed");
        }
        $plagiarismfile->id = $pid;
        return $plagiarismfile;
    }
}

/**
 * Queues a specific file for processing by the plagiarism API.
 *
 * This creates or updates a record in the 'plagiarism_originality_subs' table
 * for the scheduled task to pick up. If a record already exists for this file,
 * it will be updated instead of creating a duplicate.
 *
 * @param int $cmid The course module ID.
 * @param int $userid The ID of the user who submitted.
 * @param \stored_file|stdClass $file The Moodle stored_file object or temp file object to process.
 * @param int|null $relateduserid The user ID of the person submitting on behalf of (optional).
 * @return void
 */
function plagiarism_originality_queue_file($cmid, $userid, $file, $relateduserid = null) {
    global $DB;

    // Get plagiarism settings for this course module
    $plagiarismvalues = $DB->get_records_menu('plagiarism_originality_conf',
        array('cm' => $cmid), '', 'name, value');

    // Determine filename based on file type
    if ($file instanceof \stored_file) {
        $filename = $file->get_filename();
        $storedfileid = $file->get_id();
        $identifier = null;
    } else if (is_object($file) && isset($file->filepath)) {
        $filename = basename($file->filepath);
        $storedfileid = null;
        $identifier = $file->filepath;
    } else {
        // Skip if we can't determine filename
        return;
    }

    // Get the file extension
    $pathinfo = pathinfo($filename);
    if (empty($pathinfo['extension'])) {
        return;
    }

    $ext = strtolower($pathinfo['extension']);

    // Check if we should validate file types
    if (isset($plagiarismvalues['originality_allowallfile']) &&
        empty($plagiarismvalues['originality_allowallfile'])) {

        // Get allowed file types from settings
        $allowedtypes = !empty($plagiarismvalues['originality_selectfiletypes'])
            ? explode(',', $plagiarismvalues['originality_selectfiletypes'])
            : array();

        // Always allow html files for online submissions
        $allowedtypes[] = 'html';
        $allowedtypes[] = 'htm';

        // Check if this file type is allowed
        if (!in_array($ext, $allowedtypes)) {
            return; // Skip this file silently
        }
    }

    // Check if a record already exists for this file/online text
    $existingrecord = null;

    if ($storedfileid) {
        // For regular files, check by storedfileid
        $existingrecord = $DB->get_record('plagiarism_originality_subs', [
            'cm' => $cmid,
            'userid' => $userid,
            'storedfileid' => $storedfileid
        ]);
    } else if ($identifier) {
        // For online text, check by identifier (or if no identifier, just check for null storedfileid)
        // We want to find the latest online text submission for this user in this cm
        $sql = "SELECT * FROM {plagiarism_originality_subs}
                WHERE cm = ? AND userid = ? AND storedfileid IS NULL
                ORDER BY timecreated DESC";
        $existingrecord = $DB->get_record_sql($sql, [$cmid, $userid], IGNORE_MULTIPLE);
    }

    $currenttime = time();

    if ($existingrecord) {
        // Record exists - update it

        // If the existing record has already been sent to the API (has externalid),
        // and the file content has changed, we need to handle this carefully
        if (!empty($existingrecord->externalid)) {
            // File was already submitted to API - we need to create a NEW record
            // because we can't update a submission that's already been sent
            // But first, mark the old one as superseded
            if ($existingrecord->status === 'report_requested' || $existingrecord->status === 'pending') {
                $existingrecord->status = 'superseded';
                $existingrecord->timemodified = $currenttime;
                $DB->update_record('plagiarism_originality_subs', $existingrecord);
            }

            // Create new record
            $record = new \stdClass();
            $record->cm = $cmid;
            $record->userid = $userid;
            $record->relateduserid = $relateduserid;
            $record->storedfileid = $storedfileid;
            $record->identifier = $identifier;
            $record->status = 'report_requested';
            $record->timecreated = $currenttime;
            $record->timemodified = $currenttime;

            $DB->insert_record('plagiarism_originality_subs', $record);

        } else {
            // Record exists but hasn't been sent to API yet - just update it
            $existingrecord->storedfileid = $storedfileid;
            $existingrecord->identifier = $identifier;
            $existingrecord->relateduserid = $relateduserid;
            $existingrecord->status = 'report_requested';
            $existingrecord->timemodified = $currenttime;
            $DB->update_record('plagiarism_originality_subs', $existingrecord);
        }
    } else {
        // No existing record - create new one
        $record = new \stdClass();
        $record->cm = $cmid;
        $record->userid = $userid;
        $record->relateduserid = $relateduserid;
        $record->storedfileid = $storedfileid;
        $record->identifier = $identifier;
        $record->status = 'report_requested';
        $record->timecreated = $currenttime;
        $record->timemodified = $currenttime;

        $DB->insert_record('plagiarism_originality_subs', $record);
    }
}

/**
 * Cleans up orphaned plagiarism records where the associated file has been deleted.
 *
 * This should be called periodically (e.g., from a scheduled task) to remove
 * records for files that no longer exist in Moodle's file storage.
 *
 * @return int Number of records cleaned up
 */
function plagiarism_originality_cleanup_orphaned_records() {
    global $DB;

    $fs = get_file_storage();
    $cleaned = 0;

    // Get all records with storedfileid that haven't been sent to API yet
    $records = $DB->get_recordset_select('plagiarism_originality_subs',
        'storedfileid IS NOT NULL AND (status = ? OR status = ?)',
        ['report_requested', 'pending']);

    foreach ($records as $record) {
        // Check if file still exists
        $file = $fs->get_file_by_id($record->storedfileid);
        if (!$file) {
            // File was deleted - remove the record if it hasn't been sent to API
            if (empty($record->externalid)) {
                $DB->delete_records('plagiarism_originality_subs', ['id' => $record->id]);
                $cleaned++;
            } else {
                // Mark as error since file is gone but was already submitted
                $record->status = 'error';
                $DB->update_record('plagiarism_originality_subs', $record);
            }
        }
    }
    $records->close();

    // Clean up temporary files for online text that are too old (> 7 days)
    $oldtime = time() - (7 * 24 * 60 * 60);
    $oldrecords = $DB->get_recordset_select('plagiarism_originality_subs',
        'identifier IS NOT NULL AND timecreated < ? AND (status = ? OR status = ? OR status = ?)',
        [$oldtime, 'report_requested', 'error', 'superseded']);

    foreach ($oldrecords as $record) {
        if (!empty($record->identifier) && file_exists($record->identifier)) {
            unlink($record->identifier);
        }
        if (empty($record->externalid)) {
            $DB->delete_records('plagiarism_originality_subs', ['id' => $record->id]);
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
function plagiarism_originality_create_temp_file($cmid, $courseid, $userid, $content) {
    global $CFG;
    $filename = "onlinetext_{$cmid}_{$userid}_" . time() . ".html";
    $filepath = $CFG->tempdir . "/plagiarism_originality/" . $filename;

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
 * @param stdClass $plagiarismfile The submission record from {plagiarism_originality_subs}.
 * @param api_client $client An instance of the API client.
 * @return bool|void False on failure.
 */
function plagiarism_originality_send_file($plagiarismfile, api_client $client) {
    global $DB;

    // Step 1: Create submission if not already done
    if (empty($plagiarismfile->externalid)) {
        $user = $DB->get_record('user', ['id' => $plagiarismfile->userid], '*', MUST_EXIST);

        // --- BLIND MARKING CHECK ---
        // 1. Default Author Name
        $authorname = $user->firstname . ' ' . $user->lastname;
        $isblind = false;

        // 2. Check if this is an Assignment with Blind Marking enabled
        try {
            $cm = get_coursemodule_from_id('', $plagiarismfile->cm);

            if ($cm && $cm->modname === 'assign') {
                // Fetch the assignment settings (we only need the blindmarking column)
                $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, blindmarking');

                if ($assign && !empty($assign->blindmarking)) {
                    // Blind marking is ON: Anonymize the author
                    $authorname = (string) $user->id;
                    $isblind = true;
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
        $settings = plagiarism_plugin_originality::get_settings_by_module($plagiarismfile->cm);

        // Add the blind marking flag to the settings array to pass it to the API client
        if ($isblind) {
            $settings['anonymous_submissions'] = true;
        }

        // Create metadata-only submission
        $submission = $client->create_submission(
            $filename,
            $authorname,
            $user->email,
            $mimetype,
            $plagiarismfile->cm,
            $settings
        );

        // Store external document ID and presigned URL
        // IMPORTANT: Don't overwrite identifier field - it contains the temp file path
        $plagiarismfile->externalid   = $submission->documentId;
        $plagiarismfile->presignedurl = $submission->presignedS3Url;
        $plagiarismfile->status       = 'pending';

        $DB->update_record('plagiarism_originality_subs', $plagiarismfile);

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
            $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
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
            $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
            return false;
        }
        $mimetype = 'text/html';
        mtrace("Loading online text from temp file: {$tempfilepath}");
    }

    if (empty($content)) {
        mtrace("No content found for fileid: {$plagiarismfile->id}");
        $plagiarismfile->status = 'error';
        $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
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

                    // Clear the identifier field since temp file is deleted
                    $plagiarismfile->identifier = null;
                    $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
                } else {
                    mtrace("Warning: Failed to delete temporary file: {$tempfilepath}");
                }
            }
        } else {
            mtrace("Failed to upload file content for documentId: {$plagiarismfile->externalid}");
            $plagiarismfile->status = 'error';
            $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
            return false;
        }
    } catch (\Exception $e) {
        mtrace("Error uploading file content for documentId: {$plagiarismfile->externalid}: " . $e->getMessage());
        $plagiarismfile->status = 'external_error';
        $plagiarismfile->errorresponse = $e->getMessage();
        $DB->update_record('plagiarism_originality_subs', $plagiarismfile);

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
 * @param stdClass $plagiarismfile The submission record from {plagiarism_originality_subs}.
 * @param api_client $client An instance of the API client.
 * @return void
 */
function plagiarism_originality_poll_file_status($plagiarismfile, api_client $client) {
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
                $plagiarismfile->similarity = $status->similarity ?? null;
                $plagiarismfile->translation_similarity = $status->translation_similarity ?? null;
                $plagiarismfile->ai_index = $status->ai_index ?? null;
                $plagiarismfile->originality = $status->originality ?? null;
                $plagiarismfile->character_replacement = $status->characterReplacement ?? null;
                $plagiarismfile->hidden_text = $status->hiddenText ?? null;
                $plagiarismfile->image_as_text = $status->imageAsText ?? null;
                $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
                break;
            case 2:
            default:
                $plagiarismfile->status = 'external_error';
                $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
                mtrace("Originality API returned error status for fileid {$plagiarismfile->id}. Response: " . json_encode($status));
                break;
        }

    } catch (\Exception $e) {
        $plagiarismfile->status = 'external_error';
        $plagiarismfile->errorresponse = $e->getMessage();
        $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
        mtrace("Originality API poll error for fileid {$plagiarismfile->id}: " . $e->getMessage());
    }
}

/**
 * Returns list of available statuses for filtering.
 * @return array
 */
function plagiarism_originality_statuscodes() {
    return array(
        'pending' => get_string('status_pending', 'plagiarism_originality'),
        'report_requested' => get_string('status_report_requested', 'plagiarism_originality'),
        'finished' => get_string('status_finished', 'plagiarism_originality'),
        'error' => get_string('status_error', 'plagiarism_originality'),
        'external_error' => get_string('status_external_error', 'plagiarism_originality'),
    );
}

/**
 * Helper function to warn admin if Cron not running correctly.
 *
 * @throws coding_exception
 * @throws dml_exception
 *
 */
function plagiarism_originality_checkcronhealth() {
    global $DB;

    $send_files = $DB->get_record('task_scheduled', array('component' => 'plagiarism_originality',
        'classname' => '\plagiarism_originality\task\send_files'));
    if (empty($send_files) || $send_files->lastruntime < time() - 3600 * 0.5) { // Check if run in last 30min.
        \core\notification::add(get_string('cronwarningsendfiles', 'plagiarism_originality'), \core\notification::ERROR);
    }
}