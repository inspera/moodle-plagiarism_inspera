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

// Ensure $CFG is available when this class file is included.
global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * The main settings form for the originality plagiarism plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_inspera_setup_form extends moodleform {
    /**
     * Defines the form elements for the plugin settings.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        // Explanation at the top.
        // $mform->addElement('html', get_string('originalityexplain', 'plagiarism_inspera'));

        // Enable checkbox.
        $mform->addElement('checkbox', 'enabled', get_string('use_originality', 'plagiarism_inspera'));
        $mform->setDefault('enabled', 0);

        // Base API URL.
        $mform->addElement('text', 'baseurl', get_string('baseurl', 'plagiarism_inspera'));
        $mform->addHelpButton('baseurl', 'baseurl', 'plagiarism_inspera');
        $mform->addRule('baseurl', null, 'required', null, 'client');
        $mform->setType('baseurl', PARAM_URL);
        $mform->setDefault('baseurl', '');

        // Client ID.
        $mform->addElement('text', 'clientid', get_string('clientid', 'plagiarism_inspera'));
        $mform->addHelpButton('clientid', 'clientid', 'plagiarism_inspera');
        $mform->addRule('clientid', null, 'required', null, 'client');
        $mform->setType('clientid', PARAM_ALPHANUMEXT);
        $mform->setDefault('clientid', '');

        // Institution ID.
        $mform->addElement('text', 'institutionid', get_string('institutionid', 'plagiarism_inspera'));
        $mform->addHelpButton('institutionid', 'institutionid', 'plagiarism_inspera');
        $mform->addRule('institutionid', null, 'required', null, 'client');
        $mform->setType('institutionid', PARAM_ALPHANUMEXT);
        $mform->setDefault('institutionid', '');

        // Get the list from our single source of truth in lib.php
        $modules = plagiarism_inspera_supported_modules();

        foreach ($modules as $mod) {
            // Double check that the module is actually installed on this site
            if (!core_component::get_component_directory("mod_$mod")) {
                continue;
            }

            // Check if Moodle considers this module to support plagiarism
            // (Assignments usually do by default)
            if (plugin_supports('mod', $mod, FEATURE_PLAGIARISM)) {
                $modstring = 'enable_mod_' . $mod;
                $modhuman = get_string('pluginname', 'mod_' . $mod);
                $mform->addElement('checkbox', $modstring, get_string('enableplugin', 'plagiarism_inspera', $modhuman));
                // Default to checked for 'assign'
                if ($mod == 'assign') {
                    $mform->setDefault($modstring, 1);
                }
            }
        }

        // Add form submit/cancel buttons.
        $this->add_action_buttons(true);
    }

    /**
     * Validate the form data.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array An array of errors (element name => error message).
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Only validate if we have the minimum required fields filled in.
        if (!empty($data['baseurl']) && !empty($data['clientid'])) {
            // Prepare the config object for the client
            $config = new stdClass();
            $config->baseurl = $data['baseurl'];
            $config->clientid = $data['clientid'];
            $config->institutionid = $data['institutionid'] ?? '';

            try {
                // Instantiate client with UNSAVED data
                $client = new \plagiarism_inspera\apiclient\api_client($config);

                // Attempt to fetch a token
                $client->test_connection();
            } catch (\Exception $e) {
                // If it fails, mark the 'baseurl' field with the error.
                // You could also map specific errors to clientid if you parsed the message.
                $errors['baseurl'] = get_string('connectionerror', 'plagiarism_inspera') . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }
}
