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
 * Contains class restore_plagiarism_inspera_plugin
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore class for the Inspera Originality plagiarism plugin.
 */
class restore_plagiarism_inspera_plugin extends restore_plagiarism_plugin {
    /**
     * Define the paths to be processed.
     */
    protected function define_module_plugin_structure() {
        $paths = [];

        // 1. SETTINGS.
        // We use a clean name for the internal handler.
        $elename = 'insperaconfigmod';

        // This MUST match the XML structure in backup_plagiarism_inspera_plugin.class.php.
        $elepath = $this->get_pathfor('/inspera_configs/inspera_config');
        $paths[] = new restore_path_element($elename, $elepath);

        // 2. SUBMISSIONS.
        if ($this->task->get_setting_value('userinfo')) {
            $elename = 'insperasubs';
            $elepath = $this->get_pathfor('/inspera_subs/inspera_sub');
            $paths[] = new restore_path_element($elename, $elepath);
        }

        return $paths;
    }

    /**
     * PROCESS: Assignment Settings
     */
    public function process_insperaconfigmod($data) {
        global $DB;
        $data = (object)$data;

        if (empty($this->task->get_moduleid())) {
            return;
        }

        $data->cm = $this->task->get_moduleid();

        // Force Translations to OFF (0) and clear languages when an activity is restored or duplicated.
        if ($data->name === 'originality_enable_translations') {
            $data->value = '0'; // Force to No.
        }
        if ($data->name === 'originality_translation_languages') {
            $data->value = ''; // Clear out the previously selected languages.
        }

        unset($data->id);
        $DB->insert_record('plagiarism_inspera_config', $data);
    }

    /**
     * PROCESS: Submission Data
     */
    public function process_insperasubs($data) {
        global $DB;
        $data = (object)$data;

        // 1. Map Context.
        $data->cm = $this->task->get_moduleid();

        // 2. Map User.
        $data->userid = $this->get_mappingid('user', $data->userid);

        unset($data->id);
        $DB->insert_record('plagiarism_inspera_subs', $data);
    }
}
