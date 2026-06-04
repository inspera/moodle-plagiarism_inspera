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
 * Main library file for plagiarism_inspera.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// 1. Dependencies.
global $CFG;
require_once($CFG->dirroot . '/plagiarism/lib.php');
require_once($CFG->dirroot . '/lib/filelib.php');

// 2. Constants.
define('PLAGIARISM_INSPERA_SHOW_NEVER', 0);
define('PLAGIARISM_INSPERA_SHOW_ALWAYS', 1);
define('PLAGIARISM_INSPERA_SHOW_AFTER_GRADING', 2);
define('PLAGIARISM_INSPERA_SHOW_DUE_DATE', 3);

define('PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE', 0);
define('PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL', 1);

define('PLAGIARISM_INSPERA_RESTRICTCONTENTNO', 0);
define('PLAGIARISM_INSPERA_RESTRICTCONTENTFILES', 1);
define('PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT', 2);

define('PLAGIARISM_INSPERA_MAXATTEMPTS', 28);

/**
 * The main plugin class for Inspera Originality.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_plugin_inspera extends plagiarism_plugin {
    /**
     * Gets the global sitewide settings for this plugin.
     *
     * @return array|false The array of config settings, or false if disabled/misconfigured.
     */
    public static function get_settings() {
        // Moodle's get_config is already memory-cached, so we safely pull it fresh every time.
        $plagiarismsettings = (array)get_config('plagiarism_inspera');

        // Check if enabled.
        if (isset($plagiarismsettings['enabled']) && $plagiarismsettings['enabled']) {
            // Check to make sure required settings are set!
            if (empty($plagiarismsettings['baseurl'])) {
                return false;
            }
            return $plagiarismsettings;
        }

        return false;
    }

    /**
     * Returns the mapping of settings to their expected PARAM types.
     *
     * @return array
     */
    public static function get_param_types() {
        return [
            'use_originality'                       => PARAM_INT,
            'originality_display_type'              => PARAM_ALPHA,
            'originality_allowallfile'              => PARAM_INT,
            'originality_archive'                   => PARAM_INT,
            'originality_restrictcontent'           => PARAM_INT,
            'originality_selectfiletypes'           => PARAM_TAGLIST,
            'originality_metadata_analysis'         => PARAM_INT,
            'originality_enable_ai'                 => PARAM_INT,
            'originality_enable_translations'       => PARAM_INT,
            'originality_translation_languages'     => PARAM_TAGLIST,
            'originality_enable_context_similarity' => PARAM_INT,
            'originality_context_threshold'         => PARAM_INT,
            'originality_enable_exclude_source_criteria' => PARAM_INT,
            'originality_exclude_source_threshold'  => PARAM_INT,
            'originality_enable_include_urls'       => PARAM_INT,
            'originality_include_urls'              => PARAM_TEXT,
            'originality_enable_exclude_urls'       => PARAM_INT,
            'originality_exclude_urls'              => PARAM_TEXT,
            'originality_show_student_report'       => PARAM_INT,
            'originality_draft_submit'              => PARAM_INT,
            'originality_excludecitations'          => PARAM_INT,
            'originality_enable_whitelist_characters' => PARAM_INT,
            'originality_whitelist_characters' => PARAM_TAGLIST,
        ];
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
        $typesmap = self::get_param_types();

        // Load module config from plagiarism config table.
        $records = $DB->get_records('plagiarism_inspera_config', ['cm' => $cmid]);
        foreach ($records as $rec) {
            $value = $rec->value;

            // Apply strict cleaning if the type is known, otherwise apply generic trimmed/raw cleaning.
            if (isset($typesmap[$rec->name])) {
                $value = clean_param($value, $typesmap[$rec->name]);
            } else {
                $value = clean_param($value, PARAM_RAW_TRIMMED);
            }

            $settings[$rec->name] = $value;
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
        $options = [
            'use_originality',
            'originality_display_type',
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
            'originality_enable_exclude_source_criteria',
            'originality_exclude_source_threshold',
            'originality_enable_include_urls',
            'originality_include_urls',
            'originality_enable_exclude_urls',
            'originality_exclude_urls',
            'originality_show_student_report',
            'originality_draft_submit',
            'originality_excludecitations',
            'originality_enable_whitelist_characters',
            'originality_whitelist_characters',
        ];
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
        global $DB;

        // Bypass the static cache in PHPUnit. Static variables persist for the lifetime
        // of the PHP process, which causes state leakage between isolated tests.
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            $manager = new \plagiarism_inspera\services\display\display_manager($DB);
            return $manager->generate_links($linkarray);
        }

        // Use a static variable to cache the manager instance across multiple calls
        // in the same production page request. This ensures the internal config cache
        // is utilized efficiently without repeated DB queries.
        static $manager = null;

        if ($manager === null) {
            $manager = new \plagiarism_inspera\services\display\display_manager($DB);
        }

        return $manager->generate_links($linkarray);
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
        $plagiarismvalues = $DB->get_records_menu('plagiarism_inspera_config', ['cm' => $cmid], '', 'name, value');
        if (empty($plagiarismvalues['use_originality'])) {
            // Originality not in use for this cm - return.
            return true;
        }

        // Check if the module associated with this event still exists.
        if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
            return true;
        }

        $userid = $eventdata['userid'];
        $relateduserid = !empty($eventdata['relateduserid']) ? $eventdata['relateduserid'] : null;
        $courseid = $eventdata['courseid'] ?? 0;

        // QUIZ SUBMISSION.
        if ($eventdata['eventtype'] === 'quiz_submitted') {
            // SECURITY / LOGIC GUARD: Ensure quizzes are globally enabled before queuing.
            // Note: If get_settings() returns an object in your plugin, use $plagiarismsettings->enable_mod_quiz instead.
            if (empty($plagiarismsettings['enable_mod_quiz'])) {
                return true;
            }

            $attemptid = $eventdata['objectid'];
            $this->process_quiz_attempt($attemptid, $cmid, $courseid, $userid, $relateduserid);
            return true;
        }

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

        // Check Group Submission.
        $sql = "SELECT a.teamsubmission
                  FROM {assign} a
                  JOIN {course_modules} cm ON a.id = cm.instance
                 WHERE cm.id = ?";
        $assignmentconfig = $DB->get_record_sql($sql, [$cmid]);

        if ($assignmentconfig && !empty($assignmentconfig->teamsubmission)) {
            if ($showcontent) {
                $showcontent = false;
            }
        }

        $charcount = plagiarism_inspera_charcount();

        // Finalize event.
        if ($eventdata['eventtype'] == 'assignsubmission_submitted' && empty($eventdata['other']['submission_editable'])) {
            if (
                isset($plagiarismvalues['originality_draft_submit']) &&
                $plagiarismvalues['originality_draft_submit'] == PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL
            ) {
                require_once("$CFG->dirroot/mod/assign/locallib.php");
                $modulecontext = context_module::instance($cmid);
                if ($showfiles) {
                    $fs = get_file_storage();
                    if (
                        $files = $fs->get_area_files(
                            $modulecontext->id,
                            'assignsubmission_file',
                            ASSIGNSUBMISSION_FILE_FILEAREA,
                            $eventdata['objectid'],
                            "id",
                            false
                        )
                    ) {
                        foreach ($files as $file) {
                            plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, $submissionid);
                        }
                    }
                }

                // If showcontent will be FALSE here if groups are enabled, so this block is skipped safely.
                if ($showcontent) {
                    // If we should be handling in-line text.
                    $submission = $DB->get_record(
                        'assignsubmission_onlinetext',
                        ['submission' => $eventdata['objectid']]
                    );
                    if (!empty($submission) && \core_text::strlen(strip_tags($submission->onlinetext)) >= $charcount) {
                        $file = plagiarism_inspera_create_temp_file(
                            $cmid,
                            $courseid,
                            $userid,
                            $submission->onlinetext,
                            $submissionid
                        );
                        plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, $submissionid);
                    }
                }
            }
            return true;
        }

        if (
            isset($plagiarismvalues['originality_draft_submit']) &&
            $plagiarismvalues['originality_draft_submit'] == PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL
        ) {
            return true;
        }

        // Draft/Upload.
        if (
            !empty($eventdata['other']['content']) &&
            $showcontent &&
            \core_text::strlen(strip_tags($eventdata['other']['content'])) >= $charcount
        ) {
            $file = plagiarism_inspera_create_temp_file(
                $cmid,
                $courseid,
                $userid,
                $eventdata['other']['content'],
                $submissionid
            );
            plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, $submissionid);
        }

        if (!empty($eventdata['other']['pathnamehashes']) && $showfiles) {
            foreach ($eventdata['other']['pathnamehashes'] as $hash) {
                $fs = get_file_storage();
                $efile = $fs->get_file_by_hash($hash);
                if ($efile && $efile->get_filename() !== '.') {
                    plagiarism_inspera_queue_file($cmid, $userid, $efile, $relateduserid, $submissionid);
                }
            }
        }
        return true;
    }

    /**
     * Helper to process a submitted quiz attempt.
     */
    private function process_quiz_attempt($attemptid, $cmid, $courseid, $userid, $relateduserid) {
        global $CFG, $DB;

        // 1. Get Unique Usage ID.
        $uniqueid = $DB->get_field('quiz_attempts', 'uniqueid', ['id' => $attemptid], IGNORE_MISSING);
        if (!$uniqueid) {
            return;
        }

        // 2. Load Question Engine.
        try {
            require_once($CFG->dirroot . '/question/engine/lib.php');
            $quba = \question_engine::load_questions_usage_by_activity($uniqueid);
        } catch (\Exception $e) {
            debugging("INSPERA ERROR: Failed to load question usage: " . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        // 3. Load Plugin Settings & Determine What to Submit.
        $settings = self::get_settings_by_module($cmid);

        // Default to '0' (PLAGIARISM_INSPERA_RESTRICTCONTENTNO) -> Submit Everything.
        $restrictcontent = isset($settings['originality_restrictcontent'])
            ? (int)$settings['originality_restrictcontent']
            : PLAGIARISM_INSPERA_RESTRICTCONTENTNO;

        // Define Logic Flags.
        $doprocesstext = ($restrictcontent === PLAGIARISM_INSPERA_RESTRICTCONTENTNO ||
            $restrictcontent === PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT);

        $doprocessfiles = ($restrictcontent === PLAGIARISM_INSPERA_RESTRICTCONTENTNO ||
            $restrictcontent === PLAGIARISM_INSPERA_RESTRICTCONTENTFILES);

        $slots = $quba->get_slots();
        $charcount = plagiarism_inspera_charcount();
        $fs = get_file_storage();

        // We need the exact Context ID to safely query the files table.
        $context = $quba->get_owning_context();

        // 4. Loop through questions.
        foreach ($slots as $slot) {
            try {
                $qa = $quba->get_question_attempt($slot);
                $question = $qa->get_question();

                // Only process Essay questions.
                if ($question->get_type_name() !== 'essay') {
                    continue;
                }

                // PART A: HANDLE ONLINE TEXT.
                if ($doprocesstext) {
                    // Get the raw, full answer directly from the question attempt step data.
                    // Rather than the summarized/stripped version.
                    $responsetext = $qa->get_last_qt_var('answer');

                    if ($responsetext !== null && $responsetext !== '') {
                        $cleantext = trim(strip_tags($responsetext));

                        if (\core_text::strlen($cleantext) >= $charcount) {
                            $uniquefilename = "quiz_{$cmid}_{$userid}_{$qa->get_database_id()}.html";
                            // Note: Passing 0 for submissionid since quizzes don't use assign_submission IDs.
                            $file = plagiarism_inspera_create_temp_file(
                                $cmid,
                                $courseid,
                                $userid,
                                $responsetext,
                                0,
                                $uniquefilename
                            );
                            plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, 0);
                        }
                    }
                }

                // PART B: HANDLE ATTACHED FILES.
                if ($doprocessfiles) {
                    $processedhashes = [];

                    foreach ($qa->get_step_iterator() as $step) {
                        $stepid = $step->get_id();

                        // Use Moodle's File API to strictly filter by Context, Component, and Filearea.
                        $files = $fs->get_area_files(
                            $context->id, // Context ID.
                            'question', // Component.
                            'response_attachments', // Filearea for Essay uploads.
                            $stepid, // Item ID.
                            'id', // Sort.
                            false // Do not include directories.
                        );

                        foreach ($files as $file) {
                            // Filter Duplicates (Same file appearing in multiple steps).
                            $contenthash = $file->get_contenthash();
                            if (in_array($contenthash, $processedhashes)) {
                                continue;
                            }
                            $processedhashes[] = $contenthash;

                            // Queue the file.
                            plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid, 0);
                        }
                    }
                }
            } catch (\Exception $e) {
                debugging("INSPERA ERROR: Slot $slot failed: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}

/**
 * Public helper that triggers plagiarism processing for a submitted quiz attempt.
 *
 * Builds the minimal event-data array expected by plagiarism_plugin_inspera::event_handler()
 * from a raw {quiz_attempts} record and delegates to it.  This function exists so that
 * tests and other callers can trigger the same processing path that the Moodle event
 * observer would normally invoke, without needing to dispatch a real Moodle event.
 *
 * @package plagiarism_inspera
 * @param \stdClass $attempt A row from {quiz_attempts} containing at minimum id, quiz, userid.
 */
function plagiarism_inspera_quiz_attempt_submitted(\stdClass $attempt) {
    global $DB;

    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], 'id, course', MUST_EXIST);
    $cm   = get_coursemodule_from_instance('quiz', $attempt->quiz, $quiz->course, false, MUST_EXIST);

    $eventdata = [
        'eventtype'         => 'quiz_submitted',
        'contextinstanceid' => (int) $cm->id,
        'objectid'          => (int) $attempt->id,
        'userid'            => (int) $attempt->userid,
        'relateduserid'     => null,
        'courseid'          => (int) $quiz->course,
        'other'             => [
            'quizid'    => (int) $attempt->quiz,
            'attemptid' => (int) $attempt->id,
        ],
    ];

    $plugin = new \plagiarism_plugin_inspera();
    $plugin->event_handler($eventdata);
}


/**
 * Helper function to get allowed char count.
 * @package plagiarism_inspera
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
 * @package plagiarism_inspera
 * @param int $cmid The course module ID to check.
 * @return array|false The settings array if enabled, false otherwise.
 */
function plagiarism_inspera_cm_use($cmid) {
    global $DB;
    static $useoriginality = [];
    if (!isset($useoriginality[$cmid])) {
        $pvalues = $DB->get_records_menu('plagiarism_inspera_config', ['cm' => $cmid], '', 'name,value');
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
 * @package plagiarism_inspera
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
        case 0: // Not shared.
            return false;
        case 1: // Immediately after it is available.
            return true;
        case 2: // After grading.
            // Determine if there is a grade for this assignment instance for this user.
            // Resolve course module and instance.
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
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
            } else if ($cm->modname === 'quiz') {
                // Logic for Quiz: Check if the specific attempt is graded.
                // Path A: Online Text (Extracted from filename).
                if (!empty($record->identifier) && preg_match('/_(\d+)\.html$/', $record->identifier, $m)) {
                    $sql = "SELECT qa.sumgrades
                            FROM {quiz_attempts} qa
                            JOIN {question_attempts} qu ON qa.uniqueid = qu.questionusageid
                            WHERE qu.id = ?";
                    $sumgrades = $DB->get_field_sql($sql, [$m[1]]);
                    return ($sumgrades !== false && $sumgrades !== null);
                }

                // Path B: File Attachment (Walk the tables from file -> step -> attempt).
                if (!empty($record->storedfileid)) {
                    $sql = "SELECT qa.sumgrades
                            FROM {files} f
                            JOIN {question_attempt_steps} qas ON f.itemid = qas.id
                            JOIN {question_attempts} qu ON qas.questionattemptid = qu.id
                            JOIN {quiz_attempts} qa ON qu.questionusageid = qa.uniqueid
                            WHERE f.id = ?";
                    $sumgrades = $DB->get_field_sql($sql, [$record->storedfileid]);
                    return ($sumgrades !== false && $sumgrades !== null);
                }
            } else if ($cm->modname === 'workshop') {
                // Logic for Workshop.
                // 1. Try to get the final grade from the Gradebook first.
                require_once($GLOBALS['CFG']->libdir . '/gradelib.php');
                $grades = grade_get_grades($cm->course, 'mod', 'workshop', $cm->instance, $userid);

                if (!empty($grades->items)) {
                    foreach ($grades->items as $item) {
                        if (empty($item->grades) || !is_array($item->grades)) {
                            continue;
                        }

                        $g = $item->grades[$userid] ?? null;
                        if (!$g) {
                            continue;
                        }

                        // Show if there is a grade, or if it has been explicitly overridden in the gradebook.
                        if (!empty($g->overridden) || ($g->str_grade !== '-' && $g->grade !== null)) {
                            return true;
                        }
                    }
                }

                // 2. Fallback: Query internal workshop tables directly.
                $submission = $DB->get_record(
                    'workshop_submissions',
                    ['workshopid' => $cm->instance, 'authorid' => $userid],
                    'id, grade, gradeover',
                    IGNORE_MISSING
                );

                if ($submission) {
                    // Check if the submission has received a final aggregated or overridden grade.
                    if (is_numeric($submission->grade) || is_numeric($submission->gradeover)) {
                        return true;
                    }

                    // Check if a reviewer has actually filled out the rubric.
                    if (
                        $DB->record_exists_select(
                            'workshop_assessments',
                            'submissionid = ? AND timemodified > 0',
                            [$submission->id]
                        )
                    ) {
                        return true;
                    }
                }
            }
            return false;
        case 3: // Due date / Close date.
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $now = time();

            if ($cm->modname === 'assign') {
                // 1. CHECK EXTENSIONS (Highest Priority).
                $flags = $DB->get_record(
                    'assign_user_flags',
                    ['assignment' => $cm->instance, 'userid' => $userid],
                    'id, extensionduedate',
                    IGNORE_MISSING
                );

                if (!empty($flags) && !empty($flags->extensionduedate)) {
                    // If extension is set, show ONLY if extension date has passed.
                    return $now >= (int)$flags->extensionduedate;
                }

                // 2. CHECK USER OVERRIDES (Medium Priority).
                // If the user has a specific override (e.g. for accessibility), use that.
                $uoverride = $DB->get_record(
                    'assign_overrides',
                    ['assignid' => $cm->instance, 'userid' => $userid],
                    'id, duedate',
                    IGNORE_MISSING
                );

                if (!empty($uoverride) && !empty($uoverride->duedate)) {
                    return $now >= (int)$uoverride->duedate;
                }

                // 3. CHECK GLOBAL ASSIGNMENT DUE DATE (Lowest Priority).
                // Fallback to the standard date set in settings.
                $assign = $DB->get_record(
                    'assign',
                    ['id' => $cm->instance],
                    'id, duedate',
                    IGNORE_MISSING
                );

                if (!empty($assign) && !empty($assign->duedate)) {
                    return $now >= (int)$assign->duedate;
                }
            } else if ($cm->modname === 'quiz') {
                // Logic for Quiz: Check Close Date (including overrides).
                // Check User Overrides first.
                $quoverride = $DB->get_record(
                    'quiz_overrides',
                    ['quiz' => $cm->instance, 'userid' => $userid],
                    'id, timeclose',
                    IGNORE_MISSING
                );

                if ($quoverride && !empty($quoverride->timeclose)) {
                    return $now >= (int)$quoverride->timeclose;
                }

                // Check Global Close Date.
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, timeclose', IGNORE_MISSING);
                if ($quiz && !empty($quiz->timeclose)) {
                    return $now >= (int)$quiz->timeclose;
                }
            } else if ($cm->modname === 'workshop') {
                // Logic for Workshop: Check submissionend.
                // Note: Workshops do not have user overrides or extensions in Moodle core.
                $workshop = $DB->get_record('workshop', ['id' => $cm->instance], 'id, submissionend', IGNORE_MISSING);
                if ($workshop && !empty($workshop->submissionend)) {
                    return $now >= (int)$workshop->submissionend;
                }
            }
            return false;
        default:
            return false;
    }
}

/**
 * Function to list question types that originality supports.

 * @package plagiarism_inspera
 * @return array
 *
 */
function plagiarism_inspera_supported_qtypes() {
    return ['essay'];
}

/**
 * Returns a list of Moodle modules supported by this plugin.
 *
 * @package plagiarism_inspera
 * @return string[] An array of module names (e.g., 'assign', 'quiz').
 */
function plagiarism_inspera_supported_modules() {
    $supportedmodules = ['assign', 'quiz', 'workshop'];
    return $supportedmodules;
}

/**
 * Hook to save plagiarism specific settings on a module settings page.
 *
 * @package plagiarism_inspera
 * @param stdClass $data
 * @param stdClass $course
 */
function plagiarism_inspera_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    $plugin = new plagiarism_plugin_inspera();
    // Do nothing if plagiarism is not enabled.
    if (!$plugin->get_settings()) {
        return $data;
    }

    if (isset($data->use_originality)) {
        // If the activity is NOT an assignment.
        // Or if assignment drafts are disabled, force the setting to IMMEDIATE.
        if (
            !isset($data->modulename) ||
            $data->modulename !== 'assign' ||
            empty($data->submissiondrafts)
        ) {
            $data->originality_draft_submit = PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE;
        }
        // Array of possible plagiarism config options.
        $plagiarismelements = $plugin->config_options();

        // First get existing values.
        if (empty($data->coursemodule)) {
            debugging("originality settings failure - no coursemodule set in form data, originality could not be enabled.");
            return $data;
        }
        $existingelements = $DB->get_records_menu(
            'plagiarism_inspera_config',
            ['cm' => $data->coursemodule],
            '',
            'name, id'
        );

        // 1. Save Standard Settings (Teacher choices).
        foreach ($plagiarismelements as $element) {
            $newelement = new stdClass();
            $newelement->cm = $data->coursemodule;
            $newelement->name = $element;

            if (isset($data->$element) && is_array($data->$element)) {
                $newelement->value = implode(',', $data->$element);
            } else {
                // Determine the value, defaulting to 0 for most fields.
                $val = (isset($data->$element) ? $data->$element : 0);

                // Normalization for Display Type.
                if ($element === 'originality_display_type') {
                    // If the value is 0, empty, or invalid, force it to 'similarity'.
                    if (empty($val) || !in_array($val, ['similarity', 'originality'], true)) {
                        $val = 'similarity';
                    }
                }
                $newelement->value = $val;
            }

            if (isset($existingelements[$element])) {
                $newelement->id = $existingelements[$element];
                $DB->update_record('plagiarism_inspera_config', $newelement);
            } else {
                $DB->insert_record('plagiarism_inspera_config', $newelement);
            }
        }

        // 2. SNAPSHOT LOGIC: Freeze Admin Rules for this Assignment.
        // This ensures that future Admin changes do not break existing assignments.

        // Determine the module suffix (e.g., '_assign').
        $modulename = $data->modulename ?? 'assign';
        $suffix = '_' . $modulename;

        // The 3 lists that control visibility/locking.
        $configlists = [
            'originality_lockeditems' . $suffix,
            'originality_hiddenitems' . $suffix,
            'originality_advanceditems' . $suffix,
        ];

        // Get Global Defaults (Admin Settings).
        // We use cm=0 to fetch the global configuration.
        $admindefaults = $DB->get_records_menu('plagiarism_inspera_config', ['cm' => 0], '', 'name, value');

        foreach ($configlists as $configname) {
            // Check if this assignment ALREADY has this list defined locally.
            // We check the DB directly to be safe, or check our pre-fetched $existingelements array.
            $alreadyexists = isset($existingelements[$configname]);

            if (!$alreadyexists) {
                // If Missing (New Assignment): Copy the current Admin Default into this assignment.
                $newrecord = new stdClass();
                $newrecord->cm = $data->coursemodule;
                $newrecord->name = $configname;

                // Use the admin value, or empty string if not set globally.
                $newrecord->value = isset($admindefaults[$configname]) ? $admindefaults[$configname] : '';

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
 * @package plagiarism_inspera
 * @param moodleform|null $formwrapper The module form wrapper instance (or null in legacy calls)
 * @param array|null $data  Submitted form values (cleaned) or null
 * @param array|null $files Submitted files or null
 * @return array An array of validation errors keyed by element name
 */
function plagiarism_inspera_coursemodule_validation($formwrapper = null, $data = null, $files = null) {
    $errors = [];

    // Backward-compatibility: Some Moodle versions/invocations call this hook with only.
    // two arguments: ($data, $files). If we detect that pattern (first param is the data array.
    // and second is files, third missing), remap them to the new signature.
    if ($data === null && $files === null && is_array($formwrapper)) {
        $files = $data; // Remains null in this legacy call.
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
            // Remove empties (can be [''] when nothing is selected).
            $filtered = array_values(array_filter($selected, function ($v) {
                return $v !== '' && $v !== null;
            }));
            $isempty = count($filtered) === 0;
        } else if (is_string($selected)) {
            $trimmed = trim($selected);
            if ($trimmed === '') {
                $isempty = true;
            } else if (strpos($trimmed, ',') !== false) {
                $parts = array_map('trim', explode(',', $trimmed));
                $isempty = count(array_filter($parts, function ($v) {
                    return $v !== '';
                })) === 0;
            } else {
                $isempty = false; // Single non-empty value.
            }
        } else {
            $isempty = true;
        }

        if ($isempty) {
            // Returning an error keyed to the element shows the message below the field and blocks save.
            $errors['originality_selectfiletypes'] = get_string('errorselectfiletypesrequired', 'plagiarism_inspera');
        }
    }

    // Exclude Source Criteria Validation.
    if (
        !empty($data['originality_enable_exclude_source_criteria']) &&
        $data['originality_enable_exclude_source_criteria'] == 1
    ) {
        $rawsource = trim((string)($data['originality_exclude_source_threshold'] ?? ''));

        // Must match the form rule: integers from 1 to 100 inclusive (no leading zeros).
        if (!preg_match('/^(100|[1-9][0-9]?)$/', $rawsource)) {
            $errors['originality_exclude_source_threshold'] = get_string(
                'errorexcludesourcethreshold',
                'plagiarism_inspera'
            );
        }
    }

    // Whitelist Characters Validation (Bulletproof Server-Side Check).
    if (
        !empty($data['originality_enable_whitelist_characters']) &&
        $data['originality_enable_whitelist_characters'] == 1
    ) {
        $whitelistdata = $data['originality_whitelist_characters'] ?? '';

        // Normalize data to an array.
        $characters = is_array($whitelistdata) ? $whitelistdata : explode(',', (string)$whitelistdata);

        foreach ($characters as $char) {
            $cleanedchar = trim($char);
            // Reject if any single chip exceeds 2 characters.
            if ($cleanedchar !== '' && core_text::strlen($cleanedchar) > 2) {
                $errors['originality_whitelist_characters'] = get_string('originality_whitelist_error', 'plagiarism_inspera');
                break; // Stop checking, one error is enough to block the save.
            }
        }
    }

    if (
        !empty($data['originality_enable_context_similarity']) &&
        $data['originality_enable_context_similarity'] == 1
    ) {
        $rawcontext = trim((string)($data['originality_context_threshold'] ?? ''));

        // Must match the form rule: integers from 50 to 100 inclusive.
        if (!preg_match('/^(100|[5-9][0-9])$/', $rawcontext)) {
            $errors['originality_context_threshold'] = get_string('contextthresholdmin', 'plagiarism_inspera');
        }
    }

    return $errors;
}

/**
 * Hook to add plagiarism specific settings to a module settings page.
 *
 * @package plagiarism_inspera
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 */
function plagiarism_inspera_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    $typesmap = plagiarism_plugin_inspera::get_param_types();

    // 1. Guard Clauses (Early Exit).
    $plugin = new plagiarism_plugin_inspera();
    $plagiarismsettings = $plugin->get_settings();
    if (!$plagiarismsettings) {
        return;
    }

    $matches = [];
    if (!preg_match('/^mod_([^_]+)_mod_form$/', get_class($formwrapper), $matches)) {
        return;
    }
    $modulename = "mod_" . $matches[1];
    $modname = 'enable_' . $modulename;

    if (empty($plagiarismsettings[$modname])) {
        return;
    }

    // 2. Load Settings Data.
    $cmid = null;
    if ($cm = $formwrapper->get_coursemodule()) {
        $cmid = $cm->id;
    }
    $context = context_course::instance($formwrapper->get_course()->id);
    $plagiarismelements = $plugin->config_options();

    $plagiarismvalues = [];
    if (!empty($cmid)) {
        $plagiarismvalues = $DB->get_records_menu('plagiarism_inspera_config', ['cm' => $cmid], '', 'name, value');
    }

    $plagiarismdefaults = $DB->get_records_menu('plagiarism_inspera_config', ['cm' => 0], '', 'name, value');

    // 3. Add Form Elements.
    if (has_capability('plagiarism/inspera:enable', $context)) {
        // This helper function adds the elements AND their specific parent/child hideIf rules.
        plagiarism_inspera_get_form_elements($mform, $modulename);

        if (
            !has_capability('plagiarism/inspera:resubmitonclose', $context) &&
            $mform->elementExists('originality_resubmit_on_close')
        ) {
            $mform->removeElement('originality_resubmit_on_close');
        }

        // Disable sub-elements if the main 'use_originality' is set to 'No'.
        // Exclude child lists from this global loop to protect their 'Show More' CSS state.
        $childelements = ['originality_translation_languages', 'originality_whitelist_characters'];

        foreach ($plagiarismelements as $element) {
            if (
                $element != 'use_originality' &&
                !in_array($element, $childelements, true) &&
                $mform->elementExists($element)
            ) {
                $mform->hideIf($element, 'use_originality', 'eq', 0);
            }
        }
    } else {
        // User does NOT have permission: Add all settings as hidden fields.
        foreach ($plagiarismelements as $element) {
            $mform->addElement('hidden', $element);
            $mform->setType($element, $typesmap[$element] ?? PARAM_RAW);
        }
    }

    // 4. Set Default Values (Cleaned up and Array-safe).
    foreach ($plagiarismelements as $element) {
        $defaultelement = $element . '_' . str_replace('mod_', '', $modulename);
        $val = null;

        if (isset($plagiarismvalues[$element])) {
            $val = $plagiarismvalues[$element];
        } else if (isset($plagiarismdefaults[$defaultelement])) {
            $val = $plagiarismdefaults[$defaultelement];
        } else {
            // Safe initial states for brand new activities.
            if ($element === 'originality_enable_translations') {
                $val = 0;
            } else if (
                in_array(
                    $element,
                    [
                        'originality_translation_languages',
                        'originality_selectfiletypes',
                        'originality_whitelist_characters',
                    ]
                )
            ) {
                $val = '';
            } else if ($element === 'originality_allowallfile') {
                $val = 1;
            } else if ($element === 'originality_display_type') {
                $val = 'originality';
            } else if (
                in_array(
                    $element,
                    [
                        'originality_excludecitations',
                        'originality_enable_exclude_source_criteria',
                        'originality_enable_whitelist_characters',
                    ]
                )
            ) {
                $val = 0;
            }
        }

        // Convert strings to arrays for Multi-Selects and Autocomplete.
        $arrayelements = [
            'originality_translation_languages',
            'originality_selectfiletypes',
            'originality_whitelist_characters',
        ];
        if ($val !== null && in_array($element, $arrayelements)) {
            if (is_string($val)) {
                $trimmedval = trim((string)$val);
                if ($trimmedval === '') {
                    $val = [];
                } else {
                    // Explode, trim whitespace, remove empty artifacts, and re-index.
                    $val = array_values(
                        array_filter(
                            array_map('trim', explode(',', $trimmedval)),
                            function ($c) {
                                return $c !== '';
                            }
                        )
                    );
                }
            }

            // Explicitly inject the saved tags as options so Moodle's UI renders the chips!
            if (
                $element === 'originality_whitelist_characters' &&
                is_array($val) &&
                !empty($val) &&
                $mform->elementExists($element)
            ) {
                $formelement = $mform->getElement($element);
                foreach ($val as $chip) {
                    $formelement->addOption($chip, $chip);
                }
            }
        }

        if ($val !== null && $mform->elementExists($element)) {
            $mform->setDefault($element, $val);
        }
    }

    // 5. Handle Hidden, Locked, and Advanced Settings.
    $suffix = '_' . str_replace('mod_', '', $modulename);

    $getlistvalues = function ($basename) use ($suffix, $plagiarismvalues, $plagiarismdefaults) {
        $fullname = $basename . $suffix;
        if (isset($plagiarismvalues[$fullname])) {
            $val = $plagiarismvalues[$fullname];
        } else if (isset($plagiarismdefaults[$fullname])) {
            $val = $plagiarismdefaults[$fullname];
        } else {
            return [];
        }

        if (!is_array($val)) {
            $valstr = trim((string)$val);
            if ($valstr === '') {
                return [];
            }
            return array_values(
                array_filter(
                    array_map(
                        'trim',
                        explode(',', $valstr)
                    ),
                    function ($v) {
                        return $v !== '';
                    }
                )
            );
        }
        return $val;
    };

    $hiddenmap = array_flip($getlistvalues('originality_hiddenitems'));
    $lockedmap = array_flip($getlistvalues('originality_lockeditems'));
    $advancedmap = array_flip($getlistvalues('originality_advanceditems'));

    $isadmin = has_capability('plagiarism/inspera:manage_locked_settings', $context);
    $hasadvanceditems = false;

    // Iterate over all possible plugin settings.
    foreach ($plagiarismelements as $name) {
        if (!$mform->elementExists($name)) {
            continue;
        }

        // BUNDLE CASCADE LOGIC.
        $ishidden   = isset($hiddenmap[$name]);
        $islocked   = isset($lockedmap[$name]);
        $isadvanced = isset($advancedmap[$name]);

        // If the parent toggle is restricted, cascade that restriction to the child list.
        if ($name === 'originality_translation_languages') {
            $ishidden   = $ishidden || isset($hiddenmap['originality_enable_translations']);
            $islocked   = $islocked || isset($lockedmap['originality_enable_translations']);
            $isadvanced = $isadvanced || isset($advancedmap['originality_enable_translations']);
        }
        if ($name === 'originality_selectfiletypes') {
            $ishidden   = $ishidden || isset($hiddenmap['originality_allowallfile']);
            $islocked   = $islocked || isset($lockedmap['originality_allowallfile']);
            $isadvanced = $isadvanced || isset($advancedmap['originality_allowallfile']);
        }
        if ($name === 'originality_exclude_source_threshold') {
            $ishidden   = $ishidden || isset($hiddenmap['originality_enable_exclude_source_criteria']);
            $islocked   = $islocked || isset($lockedmap['originality_enable_exclude_source_criteria']);
            $isadvanced = $isadvanced || isset($advancedmap['originality_enable_exclude_source_criteria']);
        }
        // Cascade logic for the new Whitelist Characters input.
        if ($name === 'originality_whitelist_characters') {
            $ishidden   = $ishidden || isset($hiddenmap['originality_enable_whitelist_characters']);
            $islocked   = $islocked || isset($lockedmap['originality_enable_whitelist_characters']);
            $isadvanced = $isadvanced || isset($advancedmap['originality_enable_whitelist_characters']);
        }

        // RULE 1: HIDDEN ITEMS.
        if ($ishidden && !$isadmin) {
            // Determine coherent fallback value using the element's PARAM type.
            $paramtype = $typesmap[$name] ?? PARAM_RAW;

            // Base fallbacks: Empty string for text/tags, 0 for toggles/ints.
            $fallback = in_array($paramtype, [PARAM_TEXT, PARAM_TAGLIST, PARAM_ALPHA]) ? '' : 0;

            // Explicit overrides for fields that need non-zero or specific string defaults.
            if ($name === 'originality_allowallfile') {
                $fallback = 1;
            } else if ($name === 'originality_display_type') {
                $fallback = 'originality';
            } else if ($name === 'originality_context_threshold') {
                $fallback = 50;
            } else if ($name === 'originality_exclude_source_threshold') {
                $fallback = 5;
            }

            $value = $plagiarismvalues[$name] ?? $fallback;

            // SECURITY: Moodle hidden elements require strings/ints. Prevent "Array to string" PHP notices.
            $hiddenval = is_array($value) ? implode(',', $value) : (string)$value;

            $mform->removeElement($name);
            $mform->addElement('hidden', $name, $hiddenval);
            $mform->setType($name, $paramtype);
            continue;
        }

        // RULE 2: LOCKED ITEMS.
        if ($islocked) {
            $mform->setAdvanced($name, true);
            $hasadvanceditems = true;

            if (!$isadmin) {
                // Hide child lists if parent toggle is locked to a state that makes them irrelevant.
                if ($name === 'originality_translation_languages') {
                    $toggleval = $plagiarismvalues['originality_enable_translations'] ?? 0;
                    if ($toggleval == 0) {
                        $mform->removeElement($name);
                        $mform->addElement('hidden', $name, ''); // String, not array.
                        $mform->setType($name, PARAM_TAGLIST);
                        continue;
                    }
                }

                if ($name === 'originality_selectfiletypes') {
                    $allowval = $plagiarismvalues['originality_allowallfile'] ?? 1;
                    if ($allowval == 1) {
                        $mform->removeElement($name);
                        $mform->addElement('hidden', $name, ''); // String, not array.
                        $mform->setType($name, PARAM_TAGLIST);
                        continue;
                    }
                }

                if ($name === 'originality_exclude_source_threshold') {
                    $sourceval = $plagiarismvalues['originality_enable_exclude_source_criteria'] ?? 0;
                    if ($sourceval == 0) {
                        $mform->removeElement($name);
                        $mform->addElement('hidden', $name, 0); // Safe integer default.
                        $mform->setType($name, PARAM_INT);
                        continue;
                    }
                }

                // ADDED: Hide whitelist chips if admin locks "Whitelist Characters" to NO (0).
                if ($name === 'originality_whitelist_characters') {
                    $whitelistval = $plagiarismvalues['originality_enable_whitelist_characters'] ?? 0;
                    if ($whitelistval == 0) {
                        $mform->removeElement($name);
                        $mform->addElement('hidden', $name, ''); // Safe empty string default.
                        $mform->setType($name, PARAM_TAGLIST);
                        continue;
                    }
                }

                $mform->freeze($name);
            }
        }

        // RULE 3: ADVANCED ITEMS.
        if ($isadvanced) {
            $mform->setAdvanced($name, true);
            $hasadvanceditems = true;
        }
    }

    if ($hasadvanceditems && $mform->elementExists('originality_advanced_anchor')) {
        $mform->setAdvanced('originality_advanced_anchor', true);
    }

    // 7. Handle Module-Specific Logic.
    if ($modulename == 'mod_assign') {
        if ($mform->elementExists('originality_restrictcontent')) {
            $mform->hideIf('originality_restrictcontent', 'assignsubmission_file_enabled', 'notchecked');
            $mform->hideIf('originality_restrictcontent', 'assignsubmission_onlinetext_enabled', 'notchecked');
        }
    } else if ($modulename == 'mod_workshop') {
        // Workshop: Show setting but hide if either text or file submissions are disabled.
        // This ensures the restriction setting only shows when 'Both' are theoretically possible.
        if ($mform->elementExists('originality_restrictcontent')) {
            $mform->hideIf('originality_restrictcontent', 'submissiontypetextavailable', 'notchecked');
            $mform->hideIf('originality_restrictcontent', 'submissiontypefileavailable', 'notchecked');
        }
    } else if (!in_array($modulename, ['mod_forum', 'mod_hsuforum', 'mod_quiz'])) {
        // For modules that TRULY do not support mixed content.
        // Remove the visual element entirely to prevent hideIf() JS conflicts.
        // Safely pass 0 to the database behind the scenes.
        if ($mform->elementExists('originality_restrictcontent')) {
            $mform->removeElement('originality_restrictcontent');
            $mform->addElement('hidden', 'originality_restrictcontent', 0);
            $mform->setType('originality_restrictcontent', PARAM_INT);
        }
    }

    global $PAGE;
    $PAGE->requires->js_call_amd('plagiarism_inspera/originality_form_behaviour', 'init');
}

/**
 * Adds the list of plagiarism settings to a form.
 *
 * @package plagiarism_inspera
 * @param object $mform - Moodle form object.
 * @param string $modulename - Moodle module frankenstyle name (for example, mod_assign).
 */
function plagiarism_inspera_get_form_elements($mform, $modulename = '') {
    $ynoptions = [ 0 => get_string('no'), 1 => get_string('yes')];

    // Supported languages for Translations.
    $languages = [
        'en' => 'English', 'sq' => 'Albanian', 'bg' => 'Bulgarian', 'hr' => 'Croatian', 'cs' => 'Czech',
        'da' => 'Danish', 'nl' => 'Dutch', 'et' => 'Estonian', 'fi' => 'Finnish', 'fr' => 'French',
        'de' => 'German', 'el' => 'Greek', 'hu' => 'Hungarian', 'it' => 'Italian', 'lv' => 'Latvian',
        'lt' => 'Lithuanian', 'mk' => 'Macedonian', 'no' => 'Norwegian', 'pl' => 'Polish', 'pt' => 'Portuguese',
        'ro' => 'Romanian', 'ru' => 'Russian', 'sr' => 'Serbian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
        'es' => 'Spanish', 'sv' => 'Swedish', 'tr' => 'Turkish', 'bs' => 'Bosnian',
    ];
    ksort($languages); // Alphabetical.

    $mform->addElement('header', 'plagiarismdesc', get_string('originality', 'plagiarism_inspera'));

    // Create a static empty div on top. The "Show more" link will be always on top.
    $mform->addElement('static', 'originality_advanced_anchor', '', '');

    // Enable Originality Check.
    $mform->addElement('select', 'use_originality', get_string("use_originality", "plagiarism_inspera"), $ynoptions);
    $mform->addHelpButton('use_originality', 'use_originality_teachers', 'plagiarism_inspera');
    $mform->setType('use_originality', PARAM_INT);

    // Score to Display.
    $displayoptions = [
        'originality' => get_string('originality_score', 'plagiarism_inspera'),
        'similarity' => get_string('similarity_score', 'plagiarism_inspera'),
    ];
    $mform->addElement(
        'select',
        'originality_display_type',
        get_string(
            'originality_display_type',
            'plagiarism_inspera'
        ),
        $displayoptions
    );
    $mform->addHelpButton('originality_display_type', 'originality_display_type', 'plagiarism_inspera');
    $mform->setType('originality_display_type', PARAM_ALPHA);

    // Allow all supported File Types.
    $filetypes = plagiarism_inspera_default_allowed_file_types(true);
    $supportedfiles = [];
    foreach ($filetypes as $ext => $mime) {
        $supportedfiles[$ext] = $ext;
    }
    $mform->addElement(
        'select',
        'originality_allowallfile',
        get_string(
            'originality_allowallfile',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->addHelpButton('originality_allowallfile', 'originality_allowallfile', 'plagiarism_inspera');
    $mform->setType('originality_allowallfile', PARAM_INT);

    $mform->addElement(
        'select',
        'originality_selectfiletypes',
        get_string(
            'originality_selectfiletypes',
            'plagiarism_inspera'
        ),
        $supportedfiles,
        ['multiple' => true]
    );
    $mform->addHelpButton('originality_selectfiletypes', 'originality_selectfiletypes', 'plagiarism_inspera');
    $mform->setType('originality_selectfiletypes', PARAM_TAGLIST);

    // When originality is enabled AND allow-all is set to No, require at least one file type to be selected.
    $mform->addRule(
        'originality_selectfiletypes',
        get_string('errorselectfiletypesrequired', 'plagiarism_inspera'),
        'callback',
        function ($value) use ($mform) {
            // Helper to safely get single select values as ints.
            $getint = function (string $name, int $default = 0) use ($mform): int {
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
                    $vals = array_values(array_filter($value, function ($v) {
                        return $v !== '' && $v !== null;
                    }));
                } else if (is_string($value)) {
                    $trimmed = trim($value);
                    if ($trimmed !== '') {
                        if (strpos($trimmed, ',') !== false) {
                            $parts = array_map('trim', explode(',', $trimmed));
                            $vals = array_values(array_filter($parts, function ($v) {
                                return $v !== '';
                            }));
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

    // Hide file type selection when "Allow all" is YES (value 1).
    $mform->hideIf('originality_selectfiletypes', 'originality_allowallfile', 'eq', 1);

    // AI Authorship.
    $mform->addElement(
        'select',
        'originality_enable_ai',
        get_string(
            'originality_enable_ai',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->addHelpButton('originality_enable_ai', 'originality_enable_ai', 'plagiarism_inspera');
    $mform->setType('originality_enable_ai', PARAM_INT);

    // Archive Documents.
    $mform->addElement(
        'select',
        'originality_archive',
        get_string(
            'originality_archive',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->addHelpButton('originality_archive', 'originality_archive', 'plagiarism_inspera');
    $mform->setType('originality_archive', PARAM_INT);

    // Whitelist Characters.
    $mform->addElement(
        'select',
        'originality_enable_whitelist_characters',
        get_string('originality_enable_whitelist_characters', 'plagiarism_inspera'),
        $ynoptions
    );
    $mform->addHelpButton(
        'originality_enable_whitelist_characters',
        'originality_enable_whitelist_characters',
        'plagiarism_inspera'
    );
    $mform->setType('originality_enable_whitelist_characters', PARAM_INT);
    $mform->setDefault('originality_enable_whitelist_characters', 0);

    // The "Chips/Tags" input box.
    $mform->addElement(
        'autocomplete',
        'originality_whitelist_characters',
        get_string('originality_whitelist_characters', 'plagiarism_inspera'),
        [],
        [
            'tags' => true,
            'multiple' => true,
            'placeholder' => get_string('originality_whitelist_placeholder', 'plagiarism_inspera'),
        ]
    );
    $mform->setType('originality_whitelist_characters', PARAM_TAGLIST);

    // Add activity-level validation for the 2-character maximum limit.
    $mform->addRule(
        'originality_whitelist_characters',
        get_string('originality_whitelist_error', 'plagiarism_inspera'),
        'callback',
        function ($value) use ($mform) {
            // If the parent toggle is turned OFF, bypass validation entirely.
            $enabletoggle = $mform->getSubmitValue('originality_enable_whitelist_characters');
            if ($enabletoggle === null) {
                $enabletoggle = $mform->getElementValue('originality_enable_whitelist_characters');
            }
            if (is_array($enabletoggle)) {
                $enabletoggle = reset($enabletoggle);
            }
            if ((int)$enabletoggle !== 1) {
                return true;
            }

            // Normalize the elements into an iterable array.
            $characters = [];
            if (is_array($value)) {
                $characters = $value;
            } else if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $characters = strpos($trimmed, ',') !== false ? explode(',', $trimmed) : [$trimmed];
                }
            }

            // Inspect each tag item.
            foreach ($characters as $char) {
                $cleanedchar = trim($char);
                if ($cleanedchar !== '' && core_text::strlen($cleanedchar) > 2) {
                    return false; // Reject the save if any single tag contains > 2 characters.
                }
            }
            return true; // Clear for submission.
        }
    );

    $mform->hideIf(
        'originality_whitelist_characters',
        'originality_enable_whitelist_characters',
        'eq',
        0
    );

    // Exclude Citations.
    $mform->addElement(
        'select',
        'originality_excludecitations',
        get_string('originality_excludecitations', 'plagiarism_inspera'),
        $ynoptions
    );
    $mform->addHelpButton('originality_excludecitations', 'originality_excludecitations', 'plagiarism_inspera');
    $mform->setType('originality_excludecitations', PARAM_INT);

    // Exclude Source Criteria.
    $mform->addElement(
        'select',
        'originality_enable_exclude_source_criteria',
        get_string('originality_enable_exclude_source_criteria', 'plagiarism_inspera'),
        $ynoptions
    );
    $mform->addHelpButton(
        'originality_enable_exclude_source_criteria',
        'originality_enable_exclude_source_criteria',
        'plagiarism_inspera'
    );
    $mform->setType('originality_enable_exclude_source_criteria', PARAM_INT);
    $mform->setDefault('originality_enable_exclude_source_criteria', 0);

    $mform->addElement(
        'text',
        'originality_exclude_source_threshold',
        get_string('originality_exclude_source_threshold', 'plagiarism_inspera'),
        ['style' => 'width: 80px;']
    );
    $mform->setType('originality_exclude_source_threshold', PARAM_TEXT);
    $mform->setDefault('originality_exclude_source_threshold', 5);
    $mform->addHelpButton(
        'originality_exclude_source_threshold',
        'originality_exclude_source_threshold',
        'plagiarism_inspera'
    );

    $mform->addRule(
        'originality_exclude_source_threshold',
        get_string('errorexcludesourcethreshold', 'plagiarism_inspera'),
        'regex',
        '/^(100|[1-9][0-9]?)$/'
    );

    $mform->hideIf('originality_exclude_source_threshold', 'originality_enable_exclude_source_criteria', 'eq', 0);

    // Contextual Similarity.
    $mform->addElement(
        'select',
        'originality_enable_context_similarity',
        get_string(
            'originality_enable_context_similarity',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->setType('originality_enable_context_similarity', PARAM_INT);
    $mform->setDefault('originality_enable_context_similarity', 0);
    $mform->addHelpButton(
        'originality_enable_context_similarity',
        'originality_enable_context_similarity',
        'plagiarism_inspera'
    );

    // Threshold input (always optional in the form).
    $mform->addElement(
        'text',
        'originality_context_threshold',
        get_string(
            'originality_context_threshold',
            'plagiarism_inspera'
        )
    );
    $mform->setType('originality_context_threshold', PARAM_TEXT);
    $mform->setDefault('originality_context_threshold', 50);
    $mform->addHelpButton('originality_context_threshold', 'originality_context_threshold', 'plagiarism_inspera');
    $mform->addRule(
        'originality_context_threshold',
        get_string('contextthresholdmin', 'plagiarism_inspera'),
        'regex',
        '/^(100|[5-9][0-9])$/'
    );
    // Hide threshold unless select is set to yes.
    $mform->hideIf('originality_context_threshold', 'originality_enable_context_similarity', 'neq', 1);

    // Exclude URLs.
    $mform->addElement(
        'select',
        'originality_enable_exclude_urls',
        get_string(
            'originality_enable_exclude_urls',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->setType('originality_enable_exclude_urls', PARAM_INT);
    $mform->setDefault('originality_enable_exclude_urls', 0);
    $mform->addHelpButton('originality_enable_exclude_urls', 'originality_enable_exclude_urls', 'plagiarism_inspera');

    $mform->addElement('text', 'originality_exclude_urls', get_string('originality_exclude_urls', 'plagiarism_inspera'));
    $mform->setType('originality_exclude_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_exclude_urls', 'originality_exclude_urls', 'plagiarism_inspera');
    $mform->hideIf('originality_exclude_urls', 'originality_enable_exclude_urls', 'neq', 1);

    // Include URLs.
    $mform->addElement(
        'select',
        'originality_enable_include_urls',
        get_string(
            'originality_enable_include_urls',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->setType('originality_enable_include_urls', PARAM_INT);
    $mform->setDefault('originality_enable_include_urls', 0);
    $mform->addHelpButton('originality_enable_include_urls', 'originality_enable_include_urls', 'plagiarism_inspera');

    $mform->addElement('text', 'originality_include_urls', get_string('originality_include_urls', 'plagiarism_inspera'));
    $mform->setType('originality_include_urls', PARAM_TEXT);
    $mform->addHelpButton('originality_include_urls', 'originality_include_urls', 'plagiarism_inspera');
    // Hide input unless enabled (set to yes/1).
    $mform->hideIf('originality_include_urls', 'originality_enable_include_urls', 'neq', 1);

    // Metadata Analysis.
    $mform->addElement(
        'select',
        'originality_metadata_analysis',
        get_string(
            'originality_metadata_analysis',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->addHelpButton('originality_metadata_analysis', 'originality_metadata_analysis', 'plagiarism_inspera');
    $mform->setType('originality_metadata_analysis', PARAM_INT);

    // Show student report.
    $sharereportoptions = [
        0 => get_string("showstudentreport_not_shared", "plagiarism_inspera"),
        1 => get_string("showstudentreport_immediately", "plagiarism_inspera"),
        2 => get_string("showstudentreport_after_grading", "plagiarism_inspera"),
        3 => get_string("showstudentreport_due_date", "plagiarism_inspera"),
    ];
    $mform->addElement(
        'select',
        'originality_show_student_report',
        get_string(
            'originality_show_student_report',
            'plagiarism_inspera'
        ),
        $sharereportoptions
    );
    $mform->addHelpButton('originality_show_student_report', 'originality_show_student_report', 'plagiarism_inspera');
    $mform->setType('originality_show_student_report', PARAM_INT);

    if ($modulename === 'mod_assign') {
        // If submissiondrafts exists and is enabled, show both options; otherwise, show only Immediate.
        $draftoptionsfinal = [
            PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_inspera"),
            PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL => get_string("submitonfinal", "plagiarism_inspera"),
        ];
        $draftoptionsimmediate = [
            PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_inspera"),
        ];
        if ($mform->elementExists('submissiondrafts')) {
            // We cannot reliably read the runtime value here, so present both, but enforce on save.
            // However, when the module does not support drafts at all, the element won't exist.
            $mform->addElement(
                'select',
                'originality_draft_submit',
                get_string(
                    "originality_draft_submit",
                    "plagiarism_inspera"
                ),
                $draftoptionsfinal
            );
        } else {
            $mform->addElement(
                'select',
                'originality_draft_submit',
                get_string(
                    "originality_draft_submit",
                    "plagiarism_inspera"
                ),
                $draftoptionsimmediate
            );
            $mform->setDefault('originality_draft_submit', PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE);
        }
        $mform->addHelpButton('originality_draft_submit', 'originality_draft_submit', 'plagiarism_inspera');
        $mform->setType('originality_draft_submit', PARAM_INT);
    }

    // Translations.
    $mform->addElement(
        'select',
        'originality_enable_translations',
        get_string(
            'originality_enable_translations',
            'plagiarism_inspera'
        ),
        $ynoptions
    );
    $mform->addHelpButton('originality_enable_translations', 'originality_enable_translations', 'plagiarism_inspera');
    $mform->setType('originality_enable_translations', PARAM_INT);

    $mform->addElement(
        'select',
        'originality_translation_languages',
        get_string(
            'originality_translation_languages',
            'plagiarism_inspera'
        ),
        $languages,
        ['multiple' => true]
    );
    $mform->setType('originality_translation_languages', PARAM_TAGLIST);
    $mform->addHelpButton(
        'originality_translation_languages',
        'originality_translation_languages',
        'plagiarism_inspera'
    );
    $mform->hideIf('originality_translation_languages', 'originality_enable_translations', 'eq', 0);

    $contentoptions = [PLAGIARISM_INSPERA_RESTRICTCONTENTNO => get_string('restrictcontentno', 'plagiarism_inspera'),
        PLAGIARISM_INSPERA_RESTRICTCONTENTFILES => get_string('restrictcontentfiles', 'plagiarism_inspera'),
        PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT => get_string('restrictcontenttext', 'plagiarism_inspera')];

    $mform->addElement(
        'select',
        'originality_restrictcontent',
        get_string(
            'originality_restrictcontent',
            'plagiarism_inspera'
        ),
        $contentoptions
    );
    $mform->addHelpButton('originality_restrictcontent', 'originality_restrictcontent_teachers', 'plagiarism_inspera');
    $mform->setType('originality_restrictcontent', PARAM_INT);
}

/**
 * Used to obtain allowed file types
 *
 * @package plagiarism_inspera
 * @param boolean $checkdb
 * @return array()
 */
function plagiarism_inspera_default_allowed_file_types($checkdb = false) {
    global $DB;

    $filetypes = [
        // Standard Text & Word Processing.
        'doc'     => 'application/msword',
        'docx'    => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf'     => 'application/pdf',
        'txt'     => 'text/plain',
        'rtf'     => 'application/rtf',
        'odt'     => 'application/vnd.oasis.opendocument.text',
        'pages'   => 'application/x-iwork-pages-sffpages',
        'wpd'     => 'application/vnd.wordperfect',

        // Spreadsheets.
        'xls'     => 'application/vnd.ms-excel',
        'xlsx'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods'     => 'application/vnd.oasis.opendocument.spreadsheet',
        'numbers' => 'application/x-iwork-numbers-sffnumbers',
        'csv'     => 'text/csv',

        // Presentations.
        'ppt'     => 'application/vnd.ms-powerpoint',
        'pptx'    => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ppsx'    => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
        'odp'     => 'application/vnd.oasis.opendocument.presentation',
        'key'     => 'application/x-iwork-keynote-sffkey',

        // Web & Data Formats.
        'html'    => 'text/html',
        'htm'     => 'text/html',
        'json'    => 'application/json',
        'xml'     => 'application/xml',
        'md'      => 'text/markdown',
        'ps'      => 'application/postscript',

        // Legacy/Undocumented (Kept for backwards compatibility).
        'sxw'     => 'application/vnd.sun.xml.writer',
        'wps'     => 'application/vnd.ms-works',
        'hwp'     => 'application/x-hwp',
    ];

    if ($checkdb) {
        // Get all filetypes from db as well.
        $sql = 'SELECT name, value FROM {config_plugins} WHERE plugin = :plugin AND ' . $DB->sql_like('name', ':name');
        $types = $DB->get_records_sql($sql, ['name' => 'ext_%', 'plugin' => 'plagiarism_inspera']);
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
 * @package plagiarism_inspera
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
    $params = [
        'cm' => $cmid,
        'userid' => $userid,
        'externalid' => $filehash,
    ];
    $sql = "SELECT *
                 FROM {plagiarism_inspera_subs}
                 WHERE cm = :cm
                 AND userid = :userid
                 AND externalid = :externalid";

    $plagiarismfile = $DB->get_record_sql($sql, $params);

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
                [$cmid, $userid, $externalid]
            );
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
 * NOTE: This is a legacy wrapper. All logic has been moved to
 * \plagiarism_inspera\services\queue_service for better testability
 * and consistency across modules.
 *
 * @param int $cmid The course module ID.
 * @param int $userid The ID of the user who submitted.
 * @param \stored_file|stdClass $file The Moodle stored_file object or temp file object to process.
 * @param int|null $relateduserid The user ID of the person submitting on behalf of (optional).
 * @param int|null $submissionid The ID of the assign_submission record.
 * @return void
 */
function plagiarism_inspera_queue_file($cmid, $userid, $file, $relateduserid = null, ?int $submissionid = null) {
    global $DB;

    // Use a static variable to persist the service (and its caches) across multiple calls.
    /** @var \plagiarism_inspera\services\queue_service|null $queueservice */
    static $queueservice = null;

    if ($queueservice === null) {
        $queueservice = new \plagiarism_inspera\services\queue_service($DB);
    }

    // Delegate the work.
    $queueservice->queue_file(
        (int)$cmid,
        (int)$userid,
        $file,
        $relateduserid,
        $submissionid
    );
}

/**
 * Cleans up orphaned plagiarism records where the associated file has been deleted.
 *
 * This should be called periodically (e.g., from a scheduled task) to remove
 * records for files that no longer exist in Moodle's file storage.
 *
 * @package plagiarism_inspera
 * @return int Number of records cleaned up
 */
function plagiarism_inspera_cleanup_orphaned_records() {
    global $DB;

    $fs = get_file_storage();
    $cleaned = 0;

    // 1. Handle Moodle File Uploads.
    // Use get_recordset for memory efficiency.
    $recordset = $DB->get_recordset_select(
        'plagiarism_inspera_subs',
        'storedfileid IS NOT NULL AND (status = ? OR status = ?)',
        ['report_requested', 'pending']
    );

    foreach ($recordset as $record) {
        // Check if the source file actually exists in Moodle's file pool.
        if (!$fs->get_file_by_id($record->storedfileid)) {
            if (empty($record->externalid)) {
                // If it never reached Inspera, we can safely wipe the DB record.
                $DB->delete_records('plagiarism_inspera_subs', ['id' => $record->id]);
                $cleaned++;
            } else {
                // If it reached Inspera but the source is gone, mark as error and stop polling.
                $record->status = 'error';
                $record->description = 'Source file deleted from Moodle storage.';
                $record->timemodified = time();
                $DB->update_record('plagiarism_inspera_subs', $record);
            }
        }
    }
    $recordset->close();

    // 2. Clean up temporary files for online text that are too old (> 7 days).
    $oldtime = time() - (7 * DAYSECS);
    $sql = "identifier IS NOT NULL AND timecreated < ? AND status IN (?, ?, ?)";
    $params = [$oldtime, 'report_requested', 'error', 'superseded'];

    $oldrecords = $DB->get_recordset_select('plagiarism_inspera_subs', $sql, $params);

    // Resolve the base path.
    $safebase = make_temp_directory('plagiarism_inspera');
    $realbasepath = realpath($safebase);

    if ($realbasepath === false) {
        mtrace('SECURITY WARNING: Base temp directory could not be resolved. File cleanup will be skipped.');
    } else {
        // Ensure base path ends with a directory separator for reliable prefix checking.
        $realbasepath = str_replace('\\', '/', $realbasepath);
        if (substr($realbasepath, -1) !== '/') {
            $realbasepath .= '/';
        }
    }

    foreach ($oldrecords as $record) {
        $tempfilepath = $record->identifier;

        // Only attempt file cleanup if we successfully resolved the base path.
        if ($realbasepath !== false && !empty($tempfilepath) && file_exists($tempfilepath)) {
            $realfilepath = realpath($tempfilepath);

            if ($realfilepath === false) {
                mtrace("SECURITY WARNING: Cleanup skipped unresolved path: {$tempfilepath}");
            } else {
                $normalizedfilepath = str_replace('\\', '/', $realfilepath);

                $isunsafe = (strpos($normalizedfilepath, '..') !== false) ||
                    (strpos($normalizedfilepath, $realbasepath) !== 0);

                if ($isunsafe) {
                    mtrace("SECURITY WARNING: Cleanup skipped unauthorized path: {$tempfilepath}");
                } else if (is_file($realfilepath)) {
                    @unlink($realfilepath);
                }
            }
        }

        // Always clean up the DB record if it never reached Inspera.
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
 * @package plagiarism_inspera
 * @param int $cmid The course module ID.
 * @param int $courseid The course ID.
 * @param int $userid The user ID.
 * @param string $content The text content to write to the file.
 * @param int $submissionid The assignment submission ID (0 for quizzes).
 * @param string|null $specificname An optional strict filename (used by Quizzes to prevent overwrite).
 * @return stdClass An object with ->filepath and ->filename properties.
 */
function plagiarism_inspera_create_temp_file(
    $cmid,
    $courseid,
    $userid,
    $content,
    $submissionid = 0,
    $specificname = null
) {
    // Use the specific name if provided (Quizzes), otherwise use default (Assignments).
    if ($specificname) {
        // Strip all path separators and illegal characters to prevent Arbitrary File Write.
        $filename = clean_param($specificname, PARAM_FILE);

        // If the sanitizer stripped everything, the input was completely invalid/malicious.
        if (empty($filename)) {
            throw new \coding_exception('Invalid specificname provided to plagiarism_inspera_create_temp_file');
        }
    } else {
        $filename = "onlinetext_{$cmid}_{$userid}_{$submissionid}.html";
    }

    // Use Moodle's core temporary directory helper.
    // This safely creates the directory if it doesn't exist and applies correct permissions.
    $tempdir = make_temp_directory('plagiarism_inspera');
    $filepath = $tempdir . '/' . $filename;

    // Sanitize content before wrapping in HTML.
    $cleanedcontent = format_text(
        $content,
        FORMAT_HTML,
        [
            'context' => \context_system::instance(),
            'filter' => false, // Don't apply filters, just clean.
            'noclean' => false, // Do apply cleaning.
        ]
    );

    // Wrap content in basic HTML structure if not already HTML.
    $htmlcontent = $cleanedcontent;

    // Check if content starts with a DOCTYPE or <html> tag (ignoring whitespace).
    $htmlpattern = '/^\s*(<!DOCTYPE\s+html.*?>|<html[\s>])/i';
    if (!preg_match($htmlpattern, $cleanedcontent)) {
        $header = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $header .= '<title>' . get_string('onlinetextsubmission', 'plagiarism_inspera') . '</title>';
        $header .= '</head><body>';

        $footer = '</body></html>';

        $htmlcontent = $header . $cleanedcontent . $footer;
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
 * @package plagiarism_inspera
 * @param stdClass $plagiarismfile The submission record from {plagiarism_inspera_subs}.
 * @param api_client $client An instance of the API client.
 * @return bool|void False on failure.
 */
function plagiarism_inspera_send_file($plagiarismfile, \plagiarism_inspera\apiclient\api_client $client) {
    global $DB;

    // Pre-flight: Load content before creating a remote submission to avoid ghost documents.
    $content = null;
    $mimetype = 'text/html';
    $filename = 'submission.html';
    $tempfilepath = null;

    $handlemissingfile = function (string $reason) use ($DB, $plagiarismfile) {
        if (!empty($plagiarismfile->externalid)) {
            mtrace(
                "Skipping Inspera submission for fileid {$plagiarismfile->id}: {$reason}. " .
                "Preserving queue record as error because externalid {$plagiarismfile->externalid} already exists."
            );
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = "Source file unavailable: {$reason}";
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return;
        }

        mtrace(
            "Skipping Inspera submission for fileid {$plagiarismfile->id}: {$reason}. " .
            "Deleting queue record from plagiarism_inspera_subs."
        );
        $DB->delete_records('plagiarism_inspera_subs', ['id' => $plagiarismfile->id]);
    };

    if (!empty($plagiarismfile->storedfileid)) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($plagiarismfile->storedfileid);

        if (!$file) {
            $handlemissingfile("stored file {$plagiarismfile->storedfileid} is missing");
            return false;
        }

        $content = $file->get_content();
        $mimetype = $file->get_mimetype();
        $filename = $file->get_filename();
    } else if (!empty($plagiarismfile->identifier)) {
        // Check identifier field for temporary file path (online text).
        $tempfilepath = $plagiarismfile->identifier;

        // Validate target directory using resolved paths.
        // Prevent Arbitrary File Read via malicious backup restoration (including symlink escapes).
        $safebase = make_temp_directory('plagiarism_inspera');
        $realbasepath = realpath($safebase);
        if ($realbasepath === false) {
            mtrace('SECURITY FATAL: Base temp directory could not be resolved for identifier validation.');
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = 'Security violation: Invalid file path detected.';
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return false;
        }

        $normalizedbase = str_replace('\\', '/', $realbasepath);
        if (substr($normalizedbase, -1) !== '/') {
            $normalizedbase .= '/';
        }

        // Ensure the resolved target directory remains inside the safe base.
        $targetdir = dirname($tempfilepath);
        $realtargetdir = realpath($targetdir);
        $normalizedtargetdir = $realtargetdir !== false ? str_replace('\\', '/', $realtargetdir) : '';
        if ($normalizedtargetdir !== '' && substr($normalizedtargetdir, -1) !== '/') {
            $normalizedtargetdir .= '/';
        }

        $isunsafe = ($realtargetdir === false) ||
            (strpos($normalizedtargetdir, $normalizedbase) !== 0);

        if ($isunsafe) {
            mtrace("SECURITY FATAL: Unauthorized directory or traversal attempt detected in identifier path: {$tempfilepath}");

            // Mark the record as an error so cron stops trying to process it.
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = 'Security violation: Invalid file path detected.';
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
            return false;
        }

        // REHYDRATION LOGIC START.
        // If the file is missing (e.g. deleted by cleanup), try to recreate it from DB.
        if (!file_exists($tempfilepath)) {
            mtrace("Temp file missing: {$tempfilepath}. Attempting rehydration...");

            if (plagiarism_inspera_rehydrate_file($plagiarismfile, $tempfilepath)) {
                mtrace("Rehydration successful: File recreated.");
            } else {
                mtrace("Rehydration failed: Could not retrieve content from database.");
            }
        }

        if (file_exists($tempfilepath)) {
            $realfilepath = realpath($tempfilepath);
            $normalizedresolvedpath = $realfilepath !== false ? str_replace('\\', '/', $realfilepath) : '';
            $isunsafe = ($realfilepath === false) ||
                (strpos($normalizedresolvedpath, $normalizedbase) !== 0) ||
                !is_file($realfilepath);

            if ($isunsafe) {
                mtrace("SECURITY FATAL: Unauthorized resolved identifier path detected: {$tempfilepath}");
                $plagiarismfile->status = 'error';
                $plagiarismfile->description = 'Security violation: Invalid file path detected.';
                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                return false;
            }

            $tempfilepath = $realfilepath;
        }

        $content = @file_get_contents($tempfilepath);
        if ($content === false) {
            $handlemissingfile('temporary online-text file is unreadable');
            if (!empty($tempfilepath) && file_exists($tempfilepath) && !unlink($tempfilepath)) {
                mtrace("Warning: Failed to delete unreadable temporary file: {$tempfilepath}");
            }
            return false;
        }
        $mimetype = 'text/html';
        mtrace("Loading online text from temp file: {$tempfilepath}");
    } else {
        $handlemissingfile('no stored file or online-text identifier is available');
        return false;
    }

    if ($content === '' || $content === null) {
        $handlemissingfile('no content available to upload');

        if (!empty($tempfilepath) && file_exists($tempfilepath) && !unlink($tempfilepath)) {
            mtrace("Warning: Failed to delete empty temporary file: {$tempfilepath}");
        }
        return false;
    }

    // Step 1: Create submission if not already done, or if status is report_requested (to ensure fresh presigned URL).
    if (empty($plagiarismfile->externalid) || $plagiarismfile->status === 'report_requested') {
        $plagiarismfile->externalid = null; // Clear existing ID to ensure we don't use a stale one if creation fails.
        $user = $DB->get_record('user', ['id' => $plagiarismfile->userid], '*', MUST_EXIST);

        // BLIND MARKING CHECK.
        // 1. Default Author Name.
        $authorname = $user->firstname . ' ' . $user->lastname;
        $isblind = false;
        $isteamsubmission = false;

        // 2. Check if this is an Assignment with Blind Marking enabled.
        try {
            $cm = get_coursemodule_from_id('', $plagiarismfile->cm);

            if ($cm && $cm->modname === 'assign') {
                // Fetch both 'blindmarking' and 'teamsubmission'.
                $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, blindmarking, teamsubmission');

                if ($assign) {
                    if (!empty($assign->blindmarking)) {
                        $authorname = (string) $user->id; // Anonymize author.
                        $isblind = true;
                    }
                    if (!empty($assign->teamsubmission)) {
                        $isteamsubmission = true; // Assignment is configured for groups.
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback: If cm lookup fails, stick to the default name or log it.
            mtrace("Warning: Failed to load CM for blind marking check (fileid: {$plagiarismfile->id}). " . $e->getMessage());
        }

        // Get originality settings for the course module.
        $settings = plagiarism_plugin_inspera::get_settings_by_module($plagiarismfile->cm);

        // Add the blind marking flag to the settings array to pass it to the API client.
        if ($isblind) {
            $settings['anonymous_submissions'] = true;
        }

        // 1. Build educators list (teachers for this activity).
        $educators = [];
        try {
            $cm = get_coursemodule_from_id(null, $plagiarismfile->cm, 0, false, MUST_EXIST);
            $context = \context_module::instance($plagiarismfile->cm);

            // Fetch dynamic grading capability based on module type.
            $gradecapabilities = plagiarism_inspera_get_grade_capabilities();
            $capability = $gradecapabilities[$cm->modname] ?? null;

            // Only query if we have an explicit capability mapped for this module.
            if ($capability !== null) {
                $users = get_enrolled_users($context, $capability, 0, 'u.*', null, 0, 0, true);

                if (!empty($users)) {
                    foreach ($users as $u) {
                        if (empty($u->id) || empty($u->email)) {
                            continue;
                        }
                        $educators[] = [
                            'id' => (string)$u->id,
                            'name' => fullname($u),
                            'email' => $u->email,
                        ];
                    }
                }
            } else {
                mtrace("Notice: No grading capability mapped for module '{$cm->modname}'. Sending empty educators list.");
            }
        } catch (\Throwable $e) {
            mtrace("Warning: Failed to fetch educators list for CM {$plagiarismfile->cm}. " . $e->getMessage());
        }

        // 2. BUILD STUDENTS LIST.
        $students = [];
        // Only run this logic if the Assignment is actually configured for Groups.
        if ($isteamsubmission && !empty($plagiarismfile->submissionid)) {
            try {
                $submission = $DB->get_record('assign_submission', ['id' => $plagiarismfile->submissionid]);

                // Check if the submission actually belongs to a valid group (ID > 0).
                // This filters out "Default Group" / "No Group" (which are 0).
                if ($submission && !empty($submission->groupid)) {
                    $groupmembers = groups_get_members($submission->groupid);

                    foreach ($groupmembers as $gm) {
                        if (empty($gm->id) || empty($gm->email)) {
                            continue;
                        }

                        if ($isblind) {
                            $sname = $gm->id;
                            $semail = $gm->id . '@blind.marking';
                        } else {
                            $sname = fullname($gm);
                            $semail = $gm->email;
                        }

                        $students[] = [
                            'id' => (string)$gm->id,
                            'name' => $sname,
                            'email' => $semail,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                mtrace("Error fetching group members: " . $e->getMessage());
            }
        }

        // Prepate DTO for Metadata.
        $metadata = new \stdClass();
        $metadata->title        = $filename;
        $metadata->author       = $authorname;
        $metadata->email        = $user->email;
        $metadata->doctype      = $mimetype;
        $metadata->assignmentid = $plagiarismfile->cm;

        // Create submission.
        try {
            $submission = $client->create_submission(
                $metadata,
                $settings,
                $educators,
                $students
            );
        } catch (\Throwable $e) {
            // If there is any API error while creating submission.
            mtrace("Error creating submission for fileid: {$plagiarismfile->id}: " . $e->getMessage());
            $plagiarismfile->status = 'error';
            $plagiarismfile->description = $e->getMessage();
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);

            // Pre-flight may have created/rehydrated an online-text temp file; clean it on API failure.
            if (!empty($tempfilepath) && file_exists($tempfilepath) && !unlink($tempfilepath)) {
                mtrace("Warning: Failed to delete temporary file after create_submission failure: {$tempfilepath}");
            }
            return false;
        }

        // Store external document ID and presigned URL.
        $plagiarismfile->externalid   = $submission->documentId;
        $transienturl = $submission->presignedS3Url;

        $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);

        $plagiarismfile->presignedurl = $transienturl;

        mtrace("Created submission for fileid: {$plagiarismfile->id}, documentId: {$submission->documentId}");
    }

    // Extract the transient URL to a local variable and strip it from the DB object.
    // This prevents dml_write_exceptions on all subsequent DB updates in Step 2.
    $uploadurl = $plagiarismfile->presignedurl ?? null;
    unset($plagiarismfile->presignedurl);

    // Step 2: Upload pre-flight validated content.

    try {
        $success = $client->upload_to_presigned_url($uploadurl, $content, $mimetype);
        if ($success) {
            $plagiarismfile->timemodified = time();
            $plagiarismfile->status = 'pending';
            $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);

            mtrace("Uploaded file content for documentId: {$plagiarismfile->externalid}");

            // Clean up temporary file if it exists.
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

            if (!empty($tempfilepath) && file_exists($tempfilepath) && !unlink($tempfilepath)) {
                mtrace("Warning: Failed to delete temporary file after upload failure: {$tempfilepath}");
            }
            return false;
        }
    } catch (\Throwable $e) {
        mtrace("Error uploading file content for documentId: {$plagiarismfile->externalid}: " . $e->getMessage());
        $plagiarismfile->status = 'external_error';
        $plagiarismfile->description = $e->getMessage();
        $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);

        // Clean up temporary file on error.
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
 * @package plagiarism_inspera
 * @param stdClass $plagiarismfile The submission record from {plagiarism_inspera_subs}.
 * @param api_client $client An instance of the API client.
 * @return void
 */
function plagiarism_inspera_poll_file_status($plagiarismfile, \plagiarism_inspera\apiclient\api_client $client) {
    global $DB;

    if (empty($plagiarismfile->externalid) || $plagiarismfile->status !== 'pending') {
        return;
    }

    try {
        $status = $client->check_document_status($plagiarismfile->externalid);

        switch ($status->status) {
            case -1:
                // Still processing.
                break;
            case 0:
                // Queued, do nothing.
                break;
            case 1:
                // Processed successfully → update record with returned data.
                $plagiarismfile->status = 'finished';

                // 1. Similarity Score.
                $similarity = null;
                if (isset($status->similarity)) {
                    $similarity = $status->similarity;
                }

                // 2. Originality Percentage.
                $originalityscore = null;
                if (isset($status->originality_percentage)) {
                    $originalityscore = $status->originality_percentage;
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

                // Assign back to record (DB columns tolerate strings or numbers).
                $plagiarismfile->similarity = $similarity;
                $plagiarismfile->originality_score = $originalityscore;
                $plagiarismfile->translation_similarity = $translation;
                $plagiarismfile->ai_index = $aiindex;
                $plagiarismfile->originality = $status->originality ?? null;
                $plagiarismfile->character_replacement = $charrepl;
                $plagiarismfile->hidden_text = $status->hiddenText ?? null;
                $plagiarismfile->image_as_text = $status->imageAsText ?? null;
                $plagiarismfile->timemodified = time();
                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                break;
            case 2:
                $graceperiodreference = (int)($plagiarismfile->timemodified ?? $plagiarismfile->timecreated ?? time());
                $elapsedseconds = time() - $graceperiodreference;
                if ($elapsedseconds < DAYSECS) {
                    mtrace("Originality API returned status 2 for fileid {$plagiarismfile->id}; keeping pending " .
                        "during grace period.");
                    break;
                }

                $plagiarismfile->status = 'external_error';
                $plagiarismfile->description = isset($status->message) ? (string)$status->message : json_encode($status);

                $plagiarismfile->timemodified = time();

                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                mtrace("Originality API returned status 2 after grace period for fileid {$plagiarismfile->id}. Response: " .
                    json_encode($status));
                break;
            default:
                $plagiarismfile->status = 'external_error';
                $plagiarismfile->description = isset($status->message) ? (string)$status->message : json_encode($status);
                $plagiarismfile->timemodified = time();
                $DB->update_record('plagiarism_inspera_subs', $plagiarismfile);
                mtrace("Originality API returned error status for fileid {$plagiarismfile->id}. Response: " .
                    json_encode($status));
                break;
        }
    } catch (\Throwable $e) {
        mtrace("Originality API poll error for fileid {$plagiarismfile->id}: " . $e->getMessage());
    }
}

/**
 * Returns list of available statuses for filtering.
 *
 * @package plagiarism_inspera
 * @return array
 */
function plagiarism_inspera_statuscodes() {
    return [
        'pending' => get_string('status_pending', 'plagiarism_inspera'),
        'report_requested' => get_string('status_report_requested', 'plagiarism_inspera'),
        'finished' => get_string('status_finished', 'plagiarism_inspera'),
        'error' => get_string('status_error', 'plagiarism_inspera'),
        'external_error' => get_string('status_external_error', 'plagiarism_inspera'),
        'superseded' => get_string('status_superseded', 'plagiarism_inspera'),
    ];
}

/**
 * Helper function to warn admin if Cron not running correctly.
 *
 * @package plagiarism_inspera
 * @throws coding_exception
 * @throws dml_exception
 *
 */
function plagiarism_inspera_checkcronhealth() {
    global $DB;

    $sendfiles = $DB->get_record('task_scheduled', ['component' => 'plagiarism_inspera',
        'classname' => '\plagiarism_inspera\task\send_files']);
    if (empty($sendfiles) || $sendfiles->lastruntime < time() - 3600 * 0.5) { // Check if run in last 30min.
        \core\notification::add(get_string('cronwarningsendfiles', 'plagiarism_inspera'), \core\notification::ERROR);
    }
}

/**
 * Attempts to regenerate a missing temporary file for Online Text submissions.
 * Supports both Assignments and Quizzes.
 *
 * @package plagiarism_inspera
 * @param stdClass $record The plagiarism_inspera_subs record
 * @param string $filepath The full path where the file should be
 * @return boolean True if successfully recreated
 */
function plagiarism_inspera_rehydrate_file($record, $filepath) {
    global $DB, $CFG;

    // Validate target directory.
    $expectedbase = rtrim($CFG->tempdir, '/') . '/plagiarism_inspera/';

    // Normalize paths to prevent slash-direction bypasses (e.g., on Windows servers).
    $normalizedfilepath = str_replace('\\', '/', $filepath);
    $normalizedbase     = str_replace('\\', '/', $expectedbase);

    // 1. Block any directory traversal attempts ("../").
    if (strpos($normalizedfilepath, '..') !== false) {
        mtrace("Security block: Path traversal attempt detected in rehydration path.");
        return false;
    }

    // 2. Enforce the base directory prefix.
    if (strpos($normalizedfilepath, $normalizedbase) !== 0) {
        mtrace("Security block: Attempted to write rehydrated file outside of plugin temp directory.");
        return false;
    }

    // Safety check: We can only rehydrate Online Text (where storedfileid is NULL).
    if (!empty($record->storedfileid)) {
        return false;
    }

    $content = '';
    $filename = basename($filepath);
    $submissionid = !empty($record->submissionid) ? (int)$record->submissionid : 0;

    // CASE A: QUIZ SUBMISSION.
    // We detect Quizzes by the filename pattern: quiz_{cmid}_{userid}_{qaid}.html.
    if (preg_match('/^quiz_(\d+)_(\d+)_(\d+)\.html$/', $filename, $matches)) {
        $cmidfromfilename   = (int)$matches[1];
        $useridfromfilename = (int)$matches[2];
        $qaid              = (int)$matches[3]; // The Question Attempt ID.

        // Validate filename ownership.
        if (!empty($record->cm) && (int)$record->cm !== $cmidfromfilename) {
            mtrace("Security block: cmid in quiz filename does not match record cm.");
            return false;
        }
        if (!empty($record->userid) && (int)$record->userid !== $useridfromfilename) {
            mtrace("Security block: userid in quiz filename does not match record userid.");
            return false;
        }

        try {
            // Moodle requires loading the full Usage first, then extracting the Slot.
            $qarecord = $DB->get_record('question_attempts', ['id' => $qaid], 'questionusageid, slot', IGNORE_MISSING);

            if ($qarecord) {
                require_once($CFG->dirroot . '/question/engine/lib.php');
                // 1. Load the entire attempt usage.
                $quba = \question_engine::load_questions_usage_by_activity($qarecord->questionusageid);
                // 2. Extract the specific question attempt using the slot number.
                $qa = $quba->get_question_attempt($qarecord->slot);
                // 3. Get the submitted text.
                $content = $qa->get_last_qt_var('answer');
            }
        } catch (\Exception $e) {
            mtrace("Error rehydrating Quiz text: " . $e->getMessage());
            return false;
        }
    } else if ($submissionid > 0) {
        // CASE B: ASSIGNMENT SUBMISSION.
        // We detect Assignments if there is a valid submissionid.
        // Get the online text from the assignment tables.
        $onlinetext = $DB->get_record(
            'assignsubmission_onlinetext',
            ['submission' => $record->submissionid],
            'onlinetext',
            IGNORE_MISSING
        );
        if ($onlinetext) {
            $content = $onlinetext->onlinetext;
        }
    }

    // If we found content, write it directly to the exact path the caller expects.
    if (!empty($content)) {
        // Match the formatting and sanitization rules from create_temp_file exactly.
        $cleanedcontent = format_text($content, FORMAT_HTML, [
            'context' => context_system::instance(),
            'filter' => false, // Don't apply filters, just clean.
            'noclean' => false, // DO apply cleaning.
        ]);

        $htmlcontent = $cleanedcontent;

        // Conditionally wrap only if it's not already a full HTML document.
        $htmlpattern = '/^\s*(<!DOCTYPE\s+html.*?>|<html[\s>])/i';
        if (!preg_match($htmlpattern, $cleanedcontent)) {
            $header = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
            $header .= '<title>' . get_string('onlinetextsubmission', 'plagiarism_inspera') . '</title>';
            $header .= '</head><body>';

            $footer = '</body></html>';

            $htmlcontent = $header . $cleanedcontent . $footer;
        }

        // 2. Ensure directory exists.
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            make_writable_directory($dir);
        }

        // 3. Write the byte-equivalent content to the path.
        if (file_put_contents($filepath, $htmlcontent) !== false) {
            @chmod($filepath, $GLOBALS['CFG']->filepermissions);
            return file_exists($filepath);
        }
    }

    return false;
}

/**
 * Returns the capability required to act as a grader/manager for supported modules.
 * Centralized to prevent capability mapping drift across endpoints.
 *
 * @return array Map of module name to its required grading capability.
 */
function plagiarism_inspera_get_grade_capabilities(): array {
    return [
        'assign'   => 'mod/assign:grade',
        'quiz'     => 'mod/quiz:grade',
        'workshop' => 'mod/workshop:viewallsubmissions',
    ];
}

/**
 * Fetches plugin settings for a specific module instance deterministically.
 *
 * @param int $cmid The course module ID.
 * @return array Map of setting name to value.
 */
function plagiarism_inspera_get_cm_settings(int $cmid): array {
    global $DB;
    // We order by ID so that if duplicates exist, the newest entry (highest ID)
    // consistently wins, making the behavior deterministic.
    return $DB->get_records_menu(
        'plagiarism_inspera_config',
        ['cm' => $cmid],
        'id ASC',
        'name, value'
    );
}
