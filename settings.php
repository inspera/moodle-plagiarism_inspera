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
 * The main admin settings page for the Inspera Originality plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

require_login();
admin_externalpage_setup('plagiarisminspera');

$context = context_system::instance();
require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");

$mform = new plagiarism_inspera_setup_form();
$plagiarismplugin = new plagiarism_plugin_inspera();

if ($mform->is_cancelled()) {
    // Redirects to current page.
    redirect(new moodle_url('/plagiarism/inspera/settings.php'));
}

echo $OUTPUT->header();
$currenttab = 'originalityettings';
require_once('originality_tabs.php');
if (($data = $mform->get_data()) && confirm_sesskey()) {
    if (!isset($data->enabled)) {
        $data->enabled = 0;
    }

    $supportedmodules = plagiarism_inspera_supported_modules();
    foreach ($supportedmodules as $mod) {
        if (plugin_supports('mod', $mod, FEATURE_PLAGIARISM)) {
            $modstring = 'enable_mod_' . $mod;
            if (!isset($data->$modstring)) {
                $data->$modstring = 0;
            }
        }
    }

    foreach ($data as $field => $value) {
        if ($field != 'submitbutton') { // Ignore the button.
            $value = trim($value); // Strip trailing spaces.
            if ($field == 'baseurl') { // Strip trailing slash from api.
                $value = rtrim($value, '/');
            }
            set_config($field, $value, 'plagiarism_inspera');
        }
    }

    // Here there could be a check that the api is valid.
    echo $OUTPUT->notification(get_string('savedconfigsuccess', 'plagiarism_inspera'), 'notifysuccess');
}

$plagiarismsettings = (array)get_config('plagiarism_inspera');
$mform->set_data($plagiarismsettings);

echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
$mform->display();
echo $OUTPUT->box_end();
echo $OUTPUT->footer();
