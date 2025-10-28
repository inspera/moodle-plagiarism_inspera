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

class plagiarism_originality_setup_form extends moodleform {
    public function definition () {
        $mform = $this->_form;

        // Explanation at the top.
        //$mform->addElement('html', get_string('originalityexplain', 'plagiarism_originality'));

        // Enable checkbox.
        $mform->addElement('checkbox', 'enabled', get_string('use_originality', 'plagiarism_originality'));
        $mform->setDefault('enabled', 0);

        // Base API URL.
        $mform->addElement('text', 'baseurl', get_string('baseurl', 'plagiarism_originality'));
        $mform->addHelpButton('baseurl', 'baseurl', 'plagiarism_originality');
        $mform->addRule('baseurl', null, 'required', null, 'client');
        $mform->setType('baseurl', PARAM_URL);
        $mform->setDefault('baseurl', '');

        // Client ID.
        $mform->addElement('text', 'clientid', get_string('clientid', 'plagiarism_originality'));
        $mform->addHelpButton('clientid', 'clientid', 'plagiarism_originality');
        $mform->addRule('clientid', null, 'required', null, 'client');
        $mform->setType('clientid', PARAM_ALPHANUMEXT);
        $mform->setDefault('clientid', '');

        // Institution ID.
        $mform->addElement('text', 'institutionid', get_string('institutionid', 'plagiarism_originality'));
        $mform->addHelpButton('institutionid', 'institutionid', 'plagiarism_originality');
        $mform->addRule('institutionid', null, 'required', null, 'client');
        $mform->setType('institutionid', PARAM_ALPHANUMEXT);
        $mform->setDefault('institutionid', '');


        $mods = core_component::get_plugin_list('mod');
        foreach ($mods as $mod => $modname) {
            if (plugin_supports('mod', $mod, FEATURE_PLAGIARISM)) {
                $modstring = 'enable_mod_' . $mod;
                $mform->addElement('checkbox', $modstring, get_string('enableplugin', 'plagiarism_originality', $mod));
                if ($modname == 'assign') {
                    $mform->setDefault($modstring, 1);
                }
            }
        }

        // Add form submit/cancel buttons.
        $this->add_action_buttons(true);
    }
}
