<?php

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

define('DRAFTSUBMIT_IMMEDIATE', 0);
define('DRAFTSUBMIT_FINAL', 1);

// Used by content type restriction form - inline-text vs file attachments.
define('PLAGIARISM_ORIGINALITY_RESTRICTCONTENTNO', 0);
define('PLAGIARISM_ORIGINALITY_RESTRICTCONTENTFILES', 1);
define('PLAGIARISM_ORIGINALITY_RESTRICTCONTENTTEXT', 2);

define('PLAGIARISM_ORIGINALITY_MAXATTEMPTS', 28);


class plagiarism_plugin_originality extends plagiarism_plugin {

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
        // 3. Generate existing links (e.g., from get_links_helper)
        // ==============================
        $output .= $this->get_links_helper($plagiarismvalues[$linkarray['cmid']], $linkarray);

        // ==============================
        // 5. Add "View Originality Report" if finished
        // ==============================
        if (!empty($linkarray['cmid']) && !empty($linkarray['userid']) && !empty($linkarray['file'])) {
            // $linkarray['file'] should be a stored_file object for this submission file.
            $file = $linkarray['file'];

            // Get the plagiarism record for this specific file.
            $record = $DB->get_record('plagiarism_originality_subs', [
                'cm' => $linkarray['cmid'],
                'userid' => $linkarray['userid'],
                'storedfileid' => $file->get_id(),
                'status' => 'finished'
            ]);

            if ($record) {
                $url = new moodle_url('/plagiarism/originality/view.php', ['id' => $record->id]);
                $output .= html_writer::link(
                    $url,
                    get_string('viewreport', 'plagiarism_originality'),
                    ['class' => 'btn btn-secondary ml-2', 'target' => '_blank']
                );
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
                            originality_queue_file($cmid, $userid, $file, $relateduserid);
                        }
                    }
                }

                if ($showcontent) { // If we should be handling in-line text.
                    $submission = $DB->get_record('assignsubmission_onlinetext', array('submission' => $eventdata['objectid']));
                    if (!empty($submission) && strlen(utf8_decode(strip_tags($submission->onlinetext))) >= $charcount) {
                        $file = originality_create_temp_file($cmid, $eventdata['courseid'], $userid, $submission->onlinetext);
                        originality_queue_file($cmid, $userid, $file, $relateduserid);
                    }
                }
            }
            return $result;
        }
        if ($eventdata['eventtype'] == 'quiz_submitted') {
            $result = true;

            $attemptid = $eventdata['objectid'];
            plagiarism_originality_quiz_queue_attempt($attemptid, true);
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

            $file = originality_create_temp_file($cmid, $eventdata['courseid'], $userid, $eventdata['other']['content']);
            originality_queue_file($cmid, $userid, $file, $relateduserid);
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

                originality_queue_file($cmid, $userid, $efile, $relateduserid);
            }
        }
        return $result;
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

function originality_cm_use($cmid) {
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


function originality_supported_modules() {
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
 * Hook to add plagiarism specific settings to a module settings page
 *
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 */
function plagiarism_originality_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB, $CFG;

    $plugin = new plagiarism_plugin_originality();
    $plagiarismsettings = $plugin->get_settings();
    if (!$plagiarismsettings) {
        return;
    }

    $cmid = null;
    if ($cm = $formwrapper->get_coursemodule()) {
        $cmid = $cm->id;
    }
    $matches = array();
    if (!preg_match('/^mod_([^_]+)_mod_form$/', get_class($formwrapper), $matches)) {
        return;
    }
    $modulename = "mod_" . $matches[1];
    $modname = 'enable_' . $modulename;
    if (empty($plagiarismsettings[$modname])) {
        return;
    }
    $context = context_course::instance($formwrapper->get_course()->id);
    if (!empty($cmid)) {
        $plagiarismvalues = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => $cmid), '', 'name, value');
    }

    // Get Defaults - cmid(0) is the default list.
    $plagiarismdefaults = $DB->get_records_menu('plagiarism_originality_conf', array('cm' => 0), '', 'name, value');
    $plagiarismelements = $plugin->config_options();


    if (has_capability('plagiarism/originality:enable', $context)) {
        originality_get_form_elements($mform);
        if ($mform->elementExists('originality_draft_submit') && $mform->elementExists('submissiondrafts')) {
            $mform->hideif('originality_draft_submit', 'submissiondrafts', 'eq', 0);
        }

        if (!has_capability('plagiarism/originality:resubmitonclose', $context) &&
            $mform->elementExists('originality_resubmit_on_close')) {
            $mform->removeElement('originality_resubmit_on_close');
        }
        // Disable all plagiarism elements if use_plagiarism eg 0.
        foreach ($plagiarismelements as $element) {
            if ($element <> 'use_originality') { // Ignore this var.
                $mform->hideif($element, 'use_originality', 'eq', 0);
            }
        }

        $mform->hideif('originality_selectfiletypes', 'originality_allowallfile', 'eq', 1);
    } else { // Add plagiarism settings as hidden vars.
        foreach ($plagiarismelements as $element) {
            $mform->addElement('hidden', $element);
        }
        $mform->setType('originality_archive', PARAM_INT);
        $mform->setType('originality_restrictcontent', PARAM_INT);
        $mform->setType('originality_selectfiletypes', PARAM_TAGLIST);
        $mform->setType('originality_allowallfile', PARAM_INT);
    }

    $mform->setType('use_originality', PARAM_INT);
    $mform->setType('originality_enable_ai', PARAM_INT);
    $mform->setType('originality_enable_translations', PARAM_INT);
    $mform->setType('originality_translation_languages', PARAM_INT);
    $mform->setType('originality_enable_context_similarity', PARAM_INT);
    $mform->setType('originality_context_threshold', PARAM_INT);
    $mform->setType('originality_enable_include_urls', PARAM_INT);
    $mform->setType('originality_include_urls', PARAM_INT);
    $mform->setType('originality_enable_exclude_urls', PARAM_INT);
    $mform->setType('originality_include_urls', PARAM_INT);
    $mform->setType('originality_exclude_urls', PARAM_INT);
    $mform->setType('originality_show_student_report', PARAM_INT);
    $mform->setType('originality_draft_submit', PARAM_INT);

    // Now set defaults.
    foreach ($plagiarismelements as $element) {
        $defaultelement = $element.'_'.str_replace('mod_', '', $modulename);
        if (isset($plagiarismvalues[$element])) {
            $mform->setDefault($element, $plagiarismvalues[$element]);
        } else if (isset($plagiarismdefaults[$defaultelement])) {
            $mform->setDefault($element, $plagiarismdefaults[$defaultelement]);
        }
    }

    // Show advanced elements only if allowed.
    $defaultelementadvanced = 'originality_advanceditems_'.str_replace('mod_', '', $modulename);
    if (!empty($plagiarismdefaults[$defaultelementadvanced])) {
        $advancedsettings = explode(',', $plagiarismdefaults[$defaultelementadvanced]);
        if (has_capability('plagiarism/originality:advancedsettings', $context)) {
            foreach ($advancedsettings as $name) {
                if ($mform->elementExists($name)) {
                    $mform->setAdvanced($name, true);
                }
            }
        } else {
            // Otherwise, put them as hidden elements.
            foreach ($advancedsettings as $name) {
                if ($mform->elementExists($name)) {
                    $element = $mform->removeElement($name);
                    $mform->addElement('hidden', $name, $element->getValue());
                }
            }
        }
    }
    // Now handle content restriction settings.
    if ($modulename == 'mod_assign' && $mform->elementExists("submissionplugins")) { // This should be mod_assign
        $mform->hideif('originality_restrictcontent', 'assignsubmission_onlinetext_enabled');
    } else if ($modulename != 'mod_forum' && $modulename != 'mod_hsuforum') {
        $mform->setDefault('originality_restrictcontent', 0);
        $mform->hardFreeze('originality_restrictcontent');
    }
}


/**
 * Adds the list of plagiarism settings to a form.
 *
 * @param object $mform - Moodle form object.
 */
function originality_get_form_elements($mform) {
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
        DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_originality"),
        DRAFTSUBMIT_FINAL => get_string("submitonfinal", "plagiarism_originality")
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
    $mform->addElement('advcheckbox', 'originality_enable_context_similarity',
        get_string('originality_enable_context_similarity', 'plagiarism_originality'), null, ['group' => 1], [0,1]);
    $mform->setType('originality_enable_context_similarity', PARAM_INT);
    // Threshold input (always optional in the form)
    $mform->addElement('text', 'originality_context_threshold',
        get_string('originality_context_threshold', 'plagiarism_originality'));
    $mform->setType('originality_context_threshold', PARAM_INT);
    $mform->setDefault('originality_context_threshold', 50);
    $mform->addHelpButton('originality_context_threshold', 'originality_context_threshold', 'plagiarism_originality');
    // Disable threshold unless checkbox is checked
    $mform->disabledIf('originality_context_threshold', 'originality_enable_context_similarity', 'eq', 0);
    // Include URLs
    $mform->addElement('advcheckbox', 'originality_enable_include_urls',
        get_string('originality_enable_include_urls', 'plagiarism_originality'));
    $mform->setDefault('originality_enable_include_urls', 0);
    $mform->addElement('text', 'originality_include_urls',
        get_string('originality_include_urls', 'plagiarism_originality'));
    $mform->setType('originality_include_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_include_urls', 'originality_include_urls', 'plagiarism_originality');
    // Disable input unless enabled
    $mform->disabledIf('originality_include_urls', 'originality_enable_include_urls', 'eq', 0);
    // Exclude URLs
    $mform->addElement('advcheckbox', 'originality_enable_exclude_urls',
        get_string('originality_enable_exclude_urls', 'plagiarism_originality'));
    $mform->setDefault('originality_enable_exclude_urls', 0);

    $mform->addElement('text', 'originality_exclude_urls',
        get_string('originality_exclude_urls', 'plagiarism_originality'));
    $mform->setType('originality_exclude_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_exclude_urls', 'originality_exclude_urls', 'plagiarism_originality');

    $mform->disabledIf('originality_exclude_urls', 'originality_enable_exclude_urls', 'eq', 0);

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
            get_string("draftsubmit", "plagiarism_originality"), $draftoptions);
    }

    $contentoptions = array(PLAGIARISM_ORIGINALITY_RESTRICTCONTENTNO => get_string('restrictcontentno', 'plagiarism_originality'),
        PLAGIARISM_ORIGINALITY_RESTRICTCONTENTFILES => get_string('restrictcontentfiles', 'plagiarism_originality'),
        PLAGIARISM_ORIGINALITY_RESTRICTCONTENTTEXT => get_string('restrictcontenttext', 'plagiarism_originality'));
    $mform->addElement('select', 'originality_restrictcontent', get_string('restrictcontent', 'plagiarism_originality'), $contentoptions);
    $mform->addHelpButton('originality_restrictcontent', 'restrictcontent', 'plagiarism_originality');
    $mform->setType('originality_restrictcontent', PARAM_INT);

    $filetypes = originality_default_allowed_file_types(true);

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
function originality_default_allowed_file_types($checkdb = false) {
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
 * Updates a originality_files record.
 *
 * @param int $cmid - course module id
 * @param int $userid - user id
 * @param stored_file|string $file - identifier for this plagiarism record - hash of file, id of quiz question etc
 * @param int $relateduserid - relateduserid if passed.
 * @return int - id of originality_files record
 */
function originality_get_plagiarism_file($cmid, $userid, $file, $relateduserid = null) {
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
 * Queue file for sending to ORIGINALITY
 *
 * @param int $cmid - course module id
 * @param int $userid - user id
 * @param varied $file string if path to temp file or full Moodle file object.
 * @param int $relateduserid - related user if if passed. (use when sending to ORIGINALITY.
 * @return boolean
 */
function originality_queue_file($cmid, $userid, $file, $relateduserid = null) {
    global $DB;
    $record = new \stdClass();
    $record->cm = $cmid;
    $record->userid = $userid;
    $record->relateduserid = $relateduserid;
    $record->status = 'report_requested';
    $record->timecreated = time();

    if ($file instanceof \stored_file) {
        $record->storedfileid = $file->get_id(); // store Moodle file id
    }

    $DB->insert_record('plagiarism_originality_subs', $record);
}

/**
 * Helper: turn text into a temporary file.
 */
function originality_create_temp_file($cmid, $courseid, $userid, $content) {
    global $CFG;
    $filename = "onlinetext_{$cmid}_{$userid}_" . time() . ".txt";
    $filepath = $CFG->tempdir . "/plagiarism_originality/" . $filename;

    if (!is_dir(dirname($filepath))) {
        mkdir(dirname($filepath), $CFG->directorypermissions, true);
    }

    file_put_contents($filepath, $content);

    $file = new \stdClass();
    $file->filepath = $filepath;
    $file->filename = $filename;
    return $file;
}

function originality_send_file($plagiarismfile, api_client $client) {
    global $DB;

    // Step 1: Create submission if not already done
    if (empty($plagiarismfile->externalid)) {
        $user = $DB->get_record('user', ['id' => $plagiarismfile->userid], '*', MUST_EXIST);

        $filename = 'submission.txt';
        $mimetype = 'application/octet-stream';

        // If we have a Moodle stored file, use its filename/mimetype
        if (!empty($plagiarismfile->storedfileid)) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($plagiarismfile->storedfileid);
            if ($file) {
                $filename = $file->get_filename();
                $mimetype = $file->get_mimetype();
            }
        }

        // Get originality settings for the course module.
        $settings = plagiarism_plugin_originality::get_settings_by_module($plagiarismfile->cm);

        // Create metadata-only submission
        $submission = $client->create_submission(
            $filename,
            $user->firstname . ' ' . $user->lastname,
            $user->email,
            $mimetype,
            $plagiarismfile->cm, // cmid as assignmentId,
            $settings
        );

        // Store external document ID and presigned URL
        $plagiarismfile->externalid   = $submission->documentId;
        $plagiarismfile->presignedurl = $submission->presignedS3Url;
        $plagiarismfile->status       = 'pending';

        $DB->update_record('plagiarism_originality_subs', $plagiarismfile);

        mtrace("Created submission for fileid: {$plagiarismfile->id}, documentId: {$submission->documentId}");
    }

    // Step 2: Upload file content if we have a stored Moodle file
    if (!empty($plagiarismfile->storedfileid)) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($plagiarismfile->storedfileid);

        if (!$file) {
            mtrace("File not found for storedfileid: {$plagiarismfile->storedfileid}");
            $plagiarismfile->status = 'error';
            $DB->update_record('plagiarism_originality_subs', $plagiarismfile);
            return false;
        }

        $content = $file->get_content();

        try {
            $success = $client->upload_to_presigned_url($plagiarismfile->presignedurl, $content, $file->get_mimetype());
            if ($success) {
                mtrace("Uploaded file content for documentId: {$plagiarismfile->externalid}");
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
            return false;
        }
    }
}



function originality_get_file_object($plagiarismfile) {
    global $DB, $CFG;

    $userid = $plagiarismfile->userid;

    // Step 0: use related user if present (on-behalf submissions)
    //if (!empty($plagiarismfile->relateduserid)) {
    //    $userid = $plagiarismfile->relateduserid;
    //}

    $cm = get_coursemodule_from_id('', $plagiarismfile->cm, 0, false, MUST_EXIST);
    $modulecontext = context_module::instance($plagiarismfile->cm);
    $fs = get_file_storage();

    // Step 1: handle assignments
    if ($cm->modname === 'assign') {
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assign = new assign($modulecontext, null, null);

        // Fetch user's submission
        if ($assign->get_instance()->teamsubmission) {
            $submission = $assign->get_group_submission($userid, 0, false);
        } else {
            $submission = $assign->get_user_submission($userid, false);
        }

        if (!$submission) {
            return false;
        }

        $submissionplugins = $assign->get_submission_plugins();
        foreach ($submissionplugins as $plugin) {
            $component = $plugin->get_subtype() . '_' . $plugin->get_type();
            $fileareas = $plugin->get_file_areas();

            foreach ($fileareas as $filearea => $name) {
                $files = $fs->get_area_files(
                    $assign->get_context()->id,
                    $component,
                    $filearea,
                    $submission->id,
                    'timemodified',
                    false
                );
                foreach ($files as $file) {
                    mtrace("file name: " . $file->get_filename());
                    // Match by filename or contenthash (identifier)
                    mtrace("contenthash: " . $file->get_contenthash());
                    mtrace("externalid: " . $plagiarismfile->externalid);
                    if ($file->get_contenthash() === $plagiarismfile->externalid) {
                        return $file;
                    }
                }
            }
        }

        // If not found, check for online text submissions
        $sql = "SELECT o.onlinetext
                  FROM {assignsubmission_onlinetext} o
                 WHERE o.submission = ?";
        $text = $DB->get_field_sql($sql, [$submission->id]);
        if ($text) {
            // Create temp file
            $tempfile = tempnam($CFG->tempdir, 'originality');
            file_put_contents($tempfile, $text);

            $file = new stdClass();
            $file->type = 'temp';
            $file->filename = 'submission.txt';
            $file->filepath = $tempfile;
            $file->mimetype = 'text/plain';
            return $file;
        }
    }

    // Step 2: other modules (forum, quiz, workshop)
    if ($cm->modname === 'forum') {
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $posts = forum_get_user_posts($cm->instance, $userid);
        foreach ($posts as $post) {
            $files = $fs->get_area_files($modulecontext->id, 'mod_forum', 'attachment', $post->id, 'timemodified', false);
            foreach ($files as $file) {
                if ($file->get_contenthash() === $plagiarismfile->identifier) {
                    return $file;
                }
            }
        }
    }

    if ($cm->modname === 'quiz') {
        $files = $fs->get_area_files($modulecontext->id, 'question', 'response_attachments', null, 'timemodified', false);
        foreach ($files as $file) {
            if ($file->get_contenthash() === $plagiarismfile->identifier) {
                return $file;
            }
        }
    }

    // Add more modules here if needed (workshop, hsuforum, etc.)

    // Step 3: fallback - not found
    return false;
}



function originality_poll_file_status($plagiarismfile, api_client $client) {
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
                $plagiarismfile->characterReplacement = $status->characterReplacement ?? null;
                $plagiarismfile->hiddenText = $status->hiddenText ?? null;
                $plagiarismfile->imageAsText = $status->imageAsText ?? null;
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



