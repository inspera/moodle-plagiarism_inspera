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

defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->libdir . '/formslib.php');

/**
 * The settings form for module-level defaults.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_inspera_defaults_form extends moodleform {
    /**
     * Defines the form elements for the module defaults.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $supportedmodules = plagiarism_inspera_supported_modules();
        $ynoptions = [0 => get_string('no'), 1 => get_string('yes')];

        $draftoptions = [
            PLAGIARISM_INSPERA_DRAFTSUBMIT_IMMEDIATE => get_string("submitondraft", "plagiarism_inspera"),
            PLAGIARISM_INSPERA_DRAFTSUBMIT_FINAL => get_string("submitonfinal", "plagiarism_inspera"),
        ];

        // Explain why module-level settings (like Translations) appear in the lists below.
        $infomsg = get_string('admin_overrides_info', 'plagiarism_inspera');
        $infohtml = html_writer::start_div('alert alert-info mt-4 mb-3', ['role' => 'alert']);
        $infohtml .= html_writer::tag('strong', s(get_string('admin_overrides_info_note', 'plagiarism_inspera')) . ':');
        $infohtml .= ' ' . s($infomsg);
        $infohtml .= html_writer::end_div();

        $mform->addElement('html', $infohtml);

        foreach ($supportedmodules as $sm) {
            if (!plugin_supports('mod', $sm, FEATURE_PLAGIARISM)) {
                continue;
            }

            $mform->addElement(
                'header',
                'plagiarismdesc_' . $sm,
                get_string('originalitydefaults_' . $sm, 'plagiarism_inspera')
            );

            // Use Originality.
            $mform->addElement(
                'select',
                'use_originality_' . $sm,
                get_string('use_originality', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton('use_originality_' . $sm, 'use_originality_admin', 'plagiarism_inspera');

            // Score Display Type Selection.
            $displayoptions = [
                'originality' => get_string('originality_score', 'plagiarism_inspera'),
                'similarity' => get_string('similarity_score', 'plagiarism_inspera'),
            ];
            $mform->addElement(
                'select',
                'originality_display_type_' . $sm,
                get_string('originality_display_type', 'plagiarism_inspera'),
                $displayoptions
            );
            $mform->addHelpButton('originality_display_type_' . $sm, 'originality_display_type', 'plagiarism_inspera');
            $mform->setDefault('originality_display_type_' . $sm, 'originality');
            $mform->setType('originality_display_type_' . $sm, PARAM_ALPHA);

            $mform->addElement(
                'select',
                'originality_allowallfile_' . $sm,
                get_string('originality_allowallfile', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton('originality_allowallfile_' . $sm, 'originality_allowallfile', 'plagiarism_inspera');
            $mform->setType('originality_allowallfile_' . $sm, PARAM_INT);

            $filetypes = plagiarism_inspera_default_allowed_file_types(true);
            $supportedfiles = [];
            foreach ($filetypes as $ext => $mime) {
                $supportedfiles[$ext] = $ext;
            }

            $mform->addElement(
                'select',
                'originality_selectfiletypes_' . $sm,
                get_string('originality_selectfiletypes', 'plagiarism_inspera'),
                $supportedfiles,
                ['multiple' => true]
            );
            $mform->addHelpButton(
                'originality_selectfiletypes_' . $sm,
                'originality_selectfiletypes',
                'plagiarism_inspera'
            );
            $mform->setType('originality_selectfiletypes_' . $sm, PARAM_TAGLIST);

            // Hide file type selection when "Allow all" is YES (value 1).
            $mform->hideIf('originality_selectfiletypes_' . $sm, 'originality_allowallfile_' . $sm, 'eq', 1);

            // AI Authorship.
            $mform->addElement(
                'select',
                'originality_enable_ai_' . $sm,
                get_string('originality_enable_ai', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton('originality_enable_ai_' . $sm, 'originality_enable_ai', 'plagiarism_inspera');
            $mform->setType('originality_enable_ai_' . $sm, PARAM_INT);

            // Archive Documents.
            $mform->addElement(
                'select',
                'originality_archive_' . $sm,
                get_string('originality_archive', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton('originality_archive_' . $sm, 'originality_archive', 'plagiarism_inspera');
            $mform->setType('originality_archive_' . $sm, PARAM_INT);

            // Whitelist Characters.
            $mform->addElement(
                'select',
                'originality_enable_whitelist_characters_' . $sm,
                get_string('originality_enable_whitelist_characters', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton(
                'originality_enable_whitelist_characters_' . $sm,
                'originality_enable_whitelist_characters',
                'plagiarism_inspera'
            );
            $mform->setType('originality_enable_whitelist_characters_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_whitelist_characters_' . $sm, 0);

            // The "Chips/Tags" input box.
            $mform->addElement(
                'autocomplete',
                'originality_whitelist_characters_' . $sm,
                get_string('originality_whitelist_characters', 'plagiarism_inspera'),
                [],
                [
                    'tags' => true,
                    'multiple' => true,
                    'placeholder' => get_string('originality_whitelist_placeholder', 'plagiarism_inspera'),
                ]
            );
            $mform->setType('originality_whitelist_characters_' . $sm, PARAM_TAGLIST);

            $mform->hideIf(
                'originality_whitelist_characters_' . $sm,
                'originality_enable_whitelist_characters_' . $sm,
                'eq',
                0
            );

            // Exclude Citations.
            $mform->addElement(
                'select',
                'originality_excludecitations_' . $sm,
                get_string('originality_excludecitations', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton('originality_excludecitations_' . $sm, 'originality_excludecitations', 'plagiarism_inspera');
            $mform->setType('originality_excludecitations_' . $sm, PARAM_INT);

            // Exclude Source Criteria.
            $mform->addElement(
                'select',
                'originality_enable_exclude_source_criteria_' . $sm,
                get_string('originality_enable_exclude_source_criteria', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton(
                'originality_enable_exclude_source_criteria_' . $sm,
                'originality_enable_exclude_source_criteria',
                'plagiarism_inspera'
            );
            $mform->setType('originality_enable_exclude_source_criteria_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_exclude_source_criteria_' . $sm, 0);

            $mform->addElement(
                'text',
                'originality_exclude_source_threshold_' . $sm,
                get_string('originality_exclude_source_threshold', 'plagiarism_inspera'),
                ['style' => 'width: 80px;']
            );
            $mform->setType('originality_exclude_source_threshold_' . $sm, PARAM_TEXT);
            $mform->setDefault('originality_exclude_source_threshold_' . $sm, 5);
            $mform->addHelpButton(
                'originality_exclude_source_threshold_' . $sm,
                'originality_exclude_source_threshold',
                'plagiarism_inspera'
            );

            $mform->addRule(
                'originality_exclude_source_threshold_' . $sm,
                get_string('errorexcludesourcethreshold', 'plagiarism_inspera'),
                'regex',
                '/^(100|[1-9][0-9]?)$/'
            );
            $mform->hideIf(
                'originality_exclude_source_threshold_' . $sm,
                'originality_enable_exclude_source_criteria_' . $sm,
                'eq',
                0
            );

            // Contextual Similarity.
            $mform->addElement(
                'select',
                'originality_enable_context_similarity_' . $sm,
                get_string('originality_enable_context_similarity', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->setType('originality_enable_context_similarity_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_context_similarity_' . $sm, 0);
            $mform->addHelpButton(
                'originality_enable_context_similarity_' . $sm,
                'originality_enable_context_similarity',
                'plagiarism_inspera'
            );

            // Threshold input.
            $mform->addElement(
                'text',
                'originality_context_threshold_' . $sm,
                get_string('originality_context_threshold', 'plagiarism_inspera')
            );
            $mform->setType('originality_context_threshold_' . $sm, PARAM_TEXT);
            $mform->setDefault('originality_context_threshold_' . $sm, 50);
            $mform->addHelpButton(
                'originality_context_threshold_' . $sm,
                'originality_context_threshold',
                'plagiarism_inspera'
            );

            $mform->addRule(
                'originality_context_threshold_' . $sm,
                get_string('contextthresholdmin', 'plagiarism_inspera'),
                'regex',
                '/^(100|[5-9][0-9])$/'
            );
            $mform->hideIf(
                'originality_context_threshold_' . $sm,
                'originality_enable_context_similarity_' . $sm,
                'neq',
                1
            );

            // Exclude URLs.
            $mform->addElement(
                'select',
                'originality_enable_exclude_urls_' . $sm,
                get_string('originality_enable_exclude_urls', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->setType('originality_enable_exclude_urls_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_exclude_urls_' . $sm, 0);
            $mform->addHelpButton(
                'originality_enable_exclude_urls_' . $sm,
                'originality_enable_exclude_urls',
                'plagiarism_inspera'
            );

            $mform->addElement(
                'text',
                'originality_exclude_urls_' . $sm,
                get_string('originality_exclude_urls', 'plagiarism_inspera')
            );
            $mform->setType('originality_exclude_urls_' . $sm, PARAM_TEXT);
            $mform->addHelpButton('originality_exclude_urls_' . $sm, 'originality_exclude_urls', 'plagiarism_inspera');

            $mform->hideIf('originality_exclude_urls_' . $sm, 'originality_enable_exclude_urls_' . $sm, 'neq', 1);

            // Include URLs.
            $mform->addElement(
                'select',
                'originality_enable_include_urls_' . $sm,
                get_string('originality_enable_include_urls', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->setType('originality_enable_include_urls_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_include_urls_' . $sm, 0);
            $mform->addHelpButton(
                'originality_enable_include_urls_' . $sm,
                'originality_enable_include_urls',
                'plagiarism_inspera'
            );

            $mform->addElement(
                'text',
                'originality_include_urls_' . $sm,
                get_string('originality_include_urls', 'plagiarism_inspera')
            );
            $mform->setType('originality_include_urls_' . $sm, PARAM_TEXT);
            $mform->addHelpButton('originality_include_urls_' . $sm, 'originality_include_urls', 'plagiarism_inspera');

            $mform->hideIf('originality_include_urls_' . $sm, 'originality_enable_include_urls_' . $sm, 'neq', 1);

            // Metadata Analysis.
            $mform->addElement(
                'select',
                'originality_metadata_analysis_' . $sm,
                get_string('originality_metadata_analysis', 'plagiarism_inspera'),
                $ynoptions
            );
            $mform->addHelpButton(
                'originality_metadata_analysis_' . $sm,
                'originality_metadata_analysis',
                'plagiarism_inspera'
            );
            $mform->setType('originality_metadata_analysis_' . $sm, PARAM_INT);

            // Show student report.
            $sharereportoptions = [
                0 => get_string("showstudentreport_not_shared", "plagiarism_inspera"),
                1 => get_string("showstudentreport_immediately", "plagiarism_inspera"),
                2 => get_string("showstudentreport_after_grading", "plagiarism_inspera"),
                3 => get_string("showstudentreport_due_date", "plagiarism_inspera"),
            ];
            $mform->addElement(
                'select',
                'originality_show_student_report_' . $sm,
                get_string('originality_show_student_report', 'plagiarism_inspera'),
                $sharereportoptions
            );
            $mform->addHelpButton(
                'originality_show_student_report_' . $sm,
                'originality_show_student_report',
                'plagiarism_inspera'
            );

            $restrictfiles = get_string('restrictcontentfiles', 'plagiarism_inspera');
            $restricttext = get_string('restrictcontenttext', 'plagiarism_inspera');
            $contentoptions = [
                PLAGIARISM_INSPERA_RESTRICTCONTENTNO => get_string('restrictcontentno', 'plagiarism_inspera'),
                PLAGIARISM_INSPERA_RESTRICTCONTENTFILES => $restrictfiles,
                PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT => $restricttext,
            ];
            $mform->addElement(
                'select',
                'originality_restrictcontent_' . $sm,
                get_string('originality_restrictcontent', 'plagiarism_inspera'),
                $contentoptions
            );
            $mform->addHelpButton(
                'originality_restrictcontent_' . $sm,
                'originality_restrictcontent_admin',
                'plagiarism_inspera'
            );
            $mform->setType('originality_restrictcontent_' . $sm, PARAM_INT);

            if ($sm == 'assign') {
                $mform->addElement(
                    'select',
                    'originality_draft_submit_' . $sm,
                    get_string("originality_draft_submit", "plagiarism_inspera"),
                    $draftoptions
                );
                $mform->addHelpButton(
                    'originality_draft_submit_' . $sm,
                    'originality_draft_submit',
                    'plagiarism_inspera'
                );
            }

            $items = [];
            // Exclude only the translation languages list, as Admins cannot pre-select its values.
            $dependentsettings = [
                'originality_translation_languages',
            ];
            foreach (plagiarism_plugin_inspera::config_options() as $setting) {
                if (in_array($setting, $dependentsettings, true)) {
                    continue; // Skip adding this to the Admin multi-select lists.
                }
                $items[$setting] = get_string($setting, 'plagiarism_inspera');
            }

            $mform->addElement(
                'select',
                'originality_hiddenitems_' . $sm,
                get_string('originality_hiddenitems', 'plagiarism_inspera'),
                $items,
                ['size' => 5]
            );
            $mform->getElement('originality_hiddenitems_' . $sm)->setMultiple(true);
            $mform->setType('originality_hiddenitems_' . $sm, PARAM_TAGLIST);
            $mform->addHelpButton('originality_hiddenitems_' . $sm, 'originality_hiddenitems', 'plagiarism_inspera');

            $mform->addElement(
                'select',
                'originality_lockeditems_' . $sm,
                get_string('originality_lockeditems', 'plagiarism_inspera'),
                $items,
                ['size' => 5]
            );
            $mform->getElement('originality_lockeditems_' . $sm)->setMultiple(true);
            $mform->setType('originality_lockeditems_' . $sm, PARAM_TAGLIST);
            $mform->addHelpButton('originality_lockeditems_' . $sm, 'originality_lockeditems', 'plagiarism_inspera');

            $mform->addElement(
                'select',
                'originality_advanceditems_' . $sm,
                get_string('originality_advanceditems', 'plagiarism_inspera'),
                $items,
                ['size' => 5]
            );
            $mform->getElement('originality_advanceditems_' . $sm)->setMultiple(true);
            $mform->addHelpButton(
                'originality_advanceditems_' . $sm,
                'originality_advanceditems',
                'plagiarism_inspera'
            );
            $mform->setType('originality_advanceditems_' . $sm, PARAM_TAGLIST);
        }

        $this->add_action_buttons(true);

        global $PAGE;
        $PAGE->requires->js_call_amd('plagiarism_inspera/originality_form_behaviour', 'init');
    }

    /**
     * Intercept form data to format comma-separated strings into arrays for Moodle's autocomplete elements.
     */
    public function set_data($defaultvalues) {
        // Moodle can pass data as an array or an object. Convert to array for easy handling.
        $isobject = is_object($defaultvalues);
        $data = $isobject ? (array)$defaultvalues : $defaultvalues;

        if (is_array($data)) {
            foreach (plagiarism_inspera_supported_modules() as $sm) {
                $key = 'originality_whitelist_characters_' . $sm;

                if (isset($data[$key]) && is_string($data[$key])) {
                    // 1. Convert the DB string "a,aa,b" into a clean PHP array ['a', 'aa', 'b'].
                    $val = trim($data[$key]);

                    $arr = [];
                    if ($val !== '') {
                        // Explode, trim whitespace, remove empty items, DEDUPLICATE, and re-index.
                        $arr = array_values(
                            array_unique(
                                array_filter(
                                    array_map(
                                        'trim',
                                        explode(
                                            ',',
                                            $val
                                        )
                                    ),
                                    function ($c) {
                                        return $c !== '';
                                    }
                                )
                            )
                        );
                    }

                    $data[$key] = $arr;

                    // 2. Inject the array items as <option>s so Moodle renders them as chips.
                    if ($this->_form->elementExists($key)) {
                        $el = $this->_form->getElement($key);

                        // Moodle's form processor may have auto-injected POSTed options.
                        // We must inspect the internal options array to prevent duplicating them.
                        $existingvalues = [];
                        if (isset($el->_options) && is_array($el->_options)) {
                            foreach ($el->_options as $opt) {
                                if (isset($opt['attr']['value'])) {
                                    $existingvalues[] = (string)$opt['attr']['value'];
                                }
                            }
                        }

                        foreach ($arr as $c) {
                            $strval = (string)$c;
                            // Only add the option if Moodle hasn't already added it.
                            if (!in_array($strval, $existingvalues, true)) {
                                $el->addOption($c, $c);
                                $existingvalues[] = $strval; // Track it so we don't double-add in this loop.
                            }
                        }
                    }
                }
            }
        }

        // Convert back to object if Moodle originally gave us an object.
        if ($isobject) {
            $data = (object)$data;
        }

        parent::set_data($data);
    }

    /**
     * Custom validation for the module defaults form.
     *
     * @param array $data The data submitted by the form.
     * @param array $files An array of files.
     * @return array An array of validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        foreach (plagiarism_inspera_supported_modules() as $sm) {
            $enablekey = 'originality_enable_context_similarity_' . $sm;
            $thresholdkey = 'originality_context_threshold_' . $sm;

            if (!empty($data[$enablekey]) && $data[$enablekey] == 1) {
                // Get raw string to prevent PHP type juggling.
                $rawcontext = trim((string)($data[$thresholdkey] ?? ''));
                // Must be purely digits AND >= 50.
                // Must match the form rule: integers from 50 to 100 inclusive.
                if (!preg_match('/^(100|[5-9][0-9])$/', $rawcontext)) {
                    $errors[$thresholdkey] = get_string('contextthresholdmin', 'plagiarism_inspera');
                }
            }

            // Include URLs.
            if (
                !empty($data['originality_enable_include_urls_' . $sm]) &&
                empty(trim($data['originality_include_urls_' . $sm]))
            ) {
                $errors['originality_include_urls_' . $sm] = get_string('errorincludeurls', 'plagiarism_inspera');
            }

            // Exclude URLs.
            if (
                !empty($data['originality_enable_exclude_urls_' . $sm]) &&
                empty(trim($data['originality_exclude_urls_' . $sm]))
            ) {
                $errors['originality_exclude_urls_' . $sm] = get_string('errorexcludeurls', 'plagiarism_inspera');
            }

            // Exclude Source Criteria Validation.
            if (
                !empty($data['originality_enable_exclude_source_criteria_' . $sm]) &&
                $data['originality_enable_exclude_source_criteria_' . $sm] == 1
            ) {
                $sourcethresholdkey = 'originality_exclude_source_threshold_' . $sm;
                // Get raw string to prevent PHP type juggling.
                $rawsource = trim((string)($data[$sourcethresholdkey] ?? ''));

                // Must match the form rule: integers from 1 to 100 inclusive (no leading zeros).
                if (!preg_match('/^(100|[1-9][0-9]?)$/', $rawsource)) {
                    $errors[$sourcethresholdkey] = get_string('errorexcludesourcethreshold', 'plagiarism_inspera');
                }
            }

            // Whitelist Characters Validation.
            if (
                !empty(
                    $data['originality_enable_whitelist_characters_' . $sm]
                ) &&
                $data['originality_enable_whitelist_characters_' . $sm] == 1
            ) {
                $whitelistkey = 'originality_whitelist_characters_' . $sm;
                $whitelistdata = $data[$whitelistkey] ?? '';

                // Moodle autocomplete with multiple=true submits an array. PARAM_TAGLIST converts it to a comma string.
                // Let's handle both just to be safe depending on where in the lifecycle validation fires.
                $characters = is_array($whitelistdata) ? $whitelistdata : explode(',', (string)$whitelistdata);

                foreach ($characters as $char) {
                    $cleanedchar = trim($char);
                    // If the chip has more than 2 characters (and isn't just an empty artifact), throw an error!
                    if ($cleanedchar !== '' && core_text::strlen($cleanedchar) > 2) {
                        $errors[$whitelistkey] = get_string('originality_whitelist_error', 'plagiarism_inspera');
                        break; // Stop checking, one error is enough to block the save.
                    }
                }
            }
        }

        return $errors;
    }
}
