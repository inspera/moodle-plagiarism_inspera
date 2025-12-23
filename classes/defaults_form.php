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

global $CFG;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The settings form for module-level defaults.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_originality_defaults_form extends moodleform {

    /**
     * Defines the form elements for the module defaults.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $supportedmodules = plagiarism_originality_supported_modules();
        $ynoptions = [0 => get_string('no'), 1 => get_string('yes')];

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

        foreach ($supportedmodules as $sm) {
            if (!plugin_supports('mod', $sm, FEATURE_PLAGIARISM)) {
                continue;
            }

            $mform->addElement('header', 'plagiarismdesc_'.$sm, get_string('originalitydefaults_'.$sm, 'plagiarism_originality'));

            // Use Originality
            $mform->addElement('select', 'use_originality_' . $sm, get_string('use_originality', 'plagiarism_originality'), $ynoptions);
            $mform->addHelpButton('use_originality_' . $sm, 'use_originality_admin', 'plagiarism_originality');

            $mform->addElement('select', 'originality_allowallfile_'.$sm,
                get_string('originality_allowallfile', 'plagiarism_originality'), $ynoptions);
            $mform->addHelpButton('originality_allowallfile_'.$sm, 'originality_allowallfile', 'plagiarism_originality');
            $mform->setType('originality_allowallfile_'.$sm, PARAM_INT);

            $filetypes = plagiarism_originality_default_allowed_file_types(true);

            $supportedfiles = array();
            foreach ($filetypes as $ext => $mime) {
                $supportedfiles[$ext] = $ext;
            }

            $mform->addElement('select', 'originality_selectfiletypes_'.$sm, get_string('originality_selectfiletypes', 'plagiarism_originality'),
                $supportedfiles, array('multiple' => true));
            $mform->addHelpButton('originality_selectfiletypes_'.$sm, 'originality_selectfiletypes', 'plagiarism_originality');
            $mform->setType('originality_selectfiletypes_'.$sm, PARAM_TAGLIST);

            // Hide file type selection when "Allow all" is YES (value 1)
            $mform->hideIf('originality_selectfiletypes_' . $sm, 'originality_allowallfile_' . $sm, 'eq', 1);

            // AI Authorship
            $mform->addElement('select', 'originality_enable_ai_' . $sm, get_string('originality_enable_ai', 'plagiarism_originality'), $ynoptions);
            $mform->addHelpButton('originality_enable_ai_' . $sm, 'originality_enable_ai', 'plagiarism_originality');
            $mform->setType('originality_enable_ai_' . $sm, PARAM_INT);

            // Archive Documents
            $mform->addElement('select', 'originality_archive_' . $sm, get_string('originality_archive', 'plagiarism_originality'), $ynoptions);
            $mform->addHelpButton('originality_archive_' . $sm, 'originality_archive', 'plagiarism_originality');
            $mform->setType('originality_archive_' . $sm, PARAM_INT);

            // Contextual Similarity
            $mform->addElement('select', 'originality_enable_context_similarity_' . $sm,
                get_string('originality_enable_context_similarity', 'plagiarism_originality'), $ynoptions);
            $mform->setType('originality_enable_context_similarity_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_context_similarity_' . $sm, 0);
            $mform->addHelpButton('originality_enable_context_similarity_' . $sm, 'originality_enable_context_similarity', 'plagiarism_originality');

            // Threshold input (always optional in the form)
            $mform->addElement('text', 'originality_context_threshold_' . $sm,
                get_string('originality_context_threshold', 'plagiarism_originality'));
            $mform->setType('originality_context_threshold_' . $sm, PARAM_INT);
            $mform->setDefault('originality_context_threshold_' . $sm, 50);
            $mform->addHelpButton('originality_context_threshold_' . $sm, 'originality_context_threshold', 'plagiarism_originality');

            // Hide threshold unless select is set to yes (1)
            $mform->hideIf('originality_context_threshold_' . $sm, 'originality_enable_context_similarity_' . $sm, 'neq', 1);

            // ========================
            // Exclude URLs
            // ========================
            $mform->addElement('select', 'originality_enable_exclude_urls_' . $sm,
                get_string('originality_enable_exclude_urls', 'plagiarism_originality'), $ynoptions);
            $mform->setType('originality_enable_exclude_urls_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_exclude_urls_' . $sm, 0);
            $mform->addHelpButton('originality_enable_exclude_urls_' . $sm, 'originality_enable_exclude_urls', 'plagiarism_originality');

            $mform->addElement('text', 'originality_exclude_urls_' . $sm,
                get_string('originality_exclude_urls', 'plagiarism_originality'));
            $mform->setType('originality_exclude_urls_' . $sm, PARAM_TEXT);
            $mform->addHelpButton('originality_exclude_urls_' . $sm, 'originality_exclude_urls', 'plagiarism_originality');

            $mform->hideIf('originality_exclude_urls_' . $sm, 'originality_enable_exclude_urls_' . $sm, 'neq', 1);

            // ========================
            // Include URLs
            // ========================
            $mform->addElement('select', 'originality_enable_include_urls_' . $sm,
                get_string('originality_enable_include_urls', 'plagiarism_originality'), $ynoptions);
            $mform->setType('originality_enable_include_urls_' . $sm, PARAM_INT);
            $mform->setDefault('originality_enable_include_urls_' . $sm, 0);
            $mform->addHelpButton('originality_enable_include_urls_' . $sm, 'originality_enable_include_urls', 'plagiarism_originality');

            $mform->addElement('text', 'originality_include_urls_' . $sm,
                get_string('originality_include_urls', 'plagiarism_originality'));
            $mform->setType('originality_include_urls_' . $sm, PARAM_TEXT);
            $mform->addHelpButton('originality_include_urls_' . $sm, 'originality_include_urls', 'plagiarism_originality');

            // Hide input unless enabled (set to yes/1)
            $mform->hideIf('originality_include_urls_' . $sm, 'originality_enable_include_urls_' . $sm, 'neq', 1);

            // Metadata Analysis
            $mform->addElement('select', 'originality_metadata_analysis_' . $sm, get_string('originality_metadata_analysis', 'plagiarism_originality'), $ynoptions);
            $mform->addHelpButton('originality_metadata_analysis_' . $sm, 'originality_metadata_analysis', 'plagiarism_originality');
            $mform->setType('originality_metadata_analysis_' . $sm, PARAM_INT);

            // Show student report
            $share_report_options = [
                0 => get_string("showstudentreport_not_shared", "plagiarism_originality"),
                1 => get_string("showstudentreport_immediately", "plagiarism_originality"),
                2 => get_string("showstudentreport_after_grading", "plagiarism_originality"),
                3 => get_string("showstudentreport_due_date", "plagiarism_originality")
            ];
            $mform->addElement('select', 'originality_show_student_report_' . $sm, get_string('originality_show_student_report', 'plagiarism_originality'), $share_report_options);
            $mform->addHelpButton('originality_show_student_report_' . $sm, 'originality_show_student_report', 'plagiarism_originality');

            $contentoptions = array(PLAGIARISM_ORIGINALITY_RESTRICTCONTENTNO => get_string('restrictcontentno', 'plagiarism_originality'),
                PLAGIARISM_ORIGINALITY_RESTRICTCONTENTFILES => get_string('restrictcontentfiles', 'plagiarism_originality'),
                PLAGIARISM_ORIGINALITY_RESTRICTCONTENTTEXT => get_string('restrictcontenttext', 'plagiarism_originality'));
            $mform->addElement('select', 'originality_restrictcontent_'.$sm,
                get_string('originality_restrictcontent', 'plagiarism_originality'), $contentoptions);
            $mform->addHelpButton('originality_restrictcontent_'.$sm, 'originality_restrictcontent_admin', 'plagiarism_originality');
            $mform->setType('originality_restrictcontent_'.$sm, PARAM_INT);

            // Translations
            $mform->addElement('select', 'originality_enable_translations_' . $sm, get_string('originality_enable_translations', 'plagiarism_originality'), $ynoptions);
            $mform->addHelpButton('originality_enable_translations_' . $sm, 'originality_enable_translations', 'plagiarism_originality');
            $mform->setType('originality_enable_translations_' . $sm, PARAM_INT);

            $mform->addElement('select', 'originality_translation_languages_' . $sm, get_string('originality_translation_languages', 'plagiarism_originality'), $languages, ['multiple' => true]);
            $mform->setType('originality_translation_languages_' . $sm, PARAM_TAGLIST);
            $mform->addHelpButton('originality_translation_languages_' . $sm, 'originality_translation_languages', 'plagiarism_originality');
            $mform->hideIf('originality_translation_languages_' . $sm, 'originality_enable_translations_' . $sm, 'eq', 0);

            if ($sm == 'assign') {
                $mform->addElement('select', 'originality_draft_submit_'.$sm,
                    get_string("originality_draft_submit", "plagiarism_originality"), $draftoptions);
                $mform->addHelpButton('originality_draft_submit_'.$sm, 'originality_draft_submit', 'plagiarism_originality');
            }

            $items = array();
            foreach (plagiarism_plugin_originality::config_options() as $setting) {
                $items[$setting] = get_string($setting, 'plagiarism_originality');
            }

            $mform->addElement('select', 'originality_hiddenitems_'.$sm,
                get_string('originality_hiddenitems', 'plagiarism_originality'), $items,
                array('size' => 5));
            $mform->getElement('originality_hiddenitems_'.$sm)->setMultiple(true);
            $mform->setType('originality_hiddenitems_'.$sm, PARAM_TAGLIST);
            $mform->addHelpButton('originality_hiddenitems_'.$sm, 'originality_hiddenitems', 'plagiarism_originality');

            $mform->addElement('select', 'originality_lockeditems_'.$sm,
                get_string('originality_lockeditems', 'plagiarism_originality'), $items,
                array('size' => 5));
            $mform->getElement('originality_lockeditems_'.$sm)->setMultiple(true);
            $mform->setType('originality_lockeditems_'.$sm, PARAM_TAGLIST);
            $mform->addHelpButton('originality_lockeditems_'.$sm, 'originality_lockeditems', 'plagiarism_originality');

            $mform->addElement('select', 'originality_advanceditems_'.$sm,
                get_string('originality_advanceditems', 'plagiarism_originality'), $items,
                array('size' => 5));
            $mform->getElement('originality_advanceditems_'.$sm)->setMultiple(true);
            $mform->addHelpButton('originality_advanceditems_'.$sm, 'originality_advanceditems', 'plagiarism_originality');
            $mform->setType('originality_advanceditems_'.$sm, PARAM_TAGLIST);

        }

        $this->add_action_buttons(true);
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

        foreach (plagiarism_originality_supported_modules() as $sm) {
            $enablekey = 'originality_enable_context_similarity_' . $sm;
            $thresholdkey = 'originality_context_threshold_' . $sm;

            if (!empty($data[$enablekey]) && $data[$enablekey] == 1) {
                $threshold = (int)($data[$thresholdkey] ?? 0);
                if ($threshold < 50) {
                    $errors[$thresholdkey] = get_string('contextthresholdmin', 'plagiarism_originality');
                }
            }

            // Include URLs
            if (!empty($data['originality_enable_include_urls_' . $sm]) && empty(trim($data['originality_include_urls_' . $sm]))) {
                $errors['originality_include_urls_' . $sm] = get_string('errorincludeurls', 'plagiarism_originality');
            }

            // Exclude URLs
            if (!empty($data['originality_enable_exclude_urls_' . $sm]) && empty(trim($data['originality_exclude_urls_' . $sm]))) {
                $errors['originality_exclude_urls_' . $sm] = get_string('errorexcludeurls', 'plagiarism_originality');
            }

            // Require at least one file type if "Allow all" is set to No (0).
            $allowallkey = 'originality_allowallfile_' . $sm;
            $selecttypeskey = 'originality_selectfiletypes_' . $sm;
            $allowall = isset($data[$allowallkey]) ? (int)$data[$allowallkey] : 1;
            if ($allowall === 0) {
                $selected = $data[$selecttypeskey] ?? [];
                if (is_string($selected)) {
                    $selected = array_filter(explode(',', $selected));
                }
                if (empty($selected)) {
                    $errors[$selecttypeskey] = get_string('errorselectfiletypes', 'plagiarism_originality');
                }
            }
        }

        return $errors;
    }

}
