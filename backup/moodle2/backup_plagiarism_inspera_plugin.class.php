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
 * Contains class backup_plagiarism_inspera_plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Backup class for the Inspera Originality plagiarism plugin.
 */
class backup_plagiarism_inspera_plugin extends backup_plagiarism_plugin {

    /**
     * Define the structure for the module backup.
     */
    public function define_module_plugin_structure() {

        $userinfo = $this->get_setting_value('userinfo');
        $plugin = $this->get_plugin_element();

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        // --- PART A: SETTINGS (Renamed to inspera_) ---
        $insperaconfigs = new backup_nested_element('inspera_configs');
        $insperaconfig  = new backup_nested_element('inspera_config', array('id'), array('name', 'value'));

        $pluginwrapper->add_child($insperaconfigs);
        $insperaconfigs->add_child($insperaconfig);

        $insperaconfig->set_source_table('plagiarism_inspera_config', array('cm' => backup::VAR_PARENTID));

        // --- PART B: SUBMISSIONS (Renamed to inspera_) ---
        if ($userinfo) {
            $insperasubs = new backup_nested_element('inspera_subs');

            // Full column list
            $insperasub  = new backup_nested_element('inspera_sub', array('id'), array(
                'userid',
                'submissionid',
                'storedfileid',
                'identifier',
                'status',
                'similarity',
                'originality_score',
                'originality',
                'externalid',
                'ai_index',
                'translation_similarity',
                'character_replacement',
                'hidden_text',
                'image_as_text',
                'description',
                'timecreated',
                'timemodified'
            ));

            $pluginwrapper->add_child($insperasubs);
            $insperasubs->add_child($insperasub);

            $insperasub->set_source_table('plagiarism_inspera_subs', array('cm' => backup::VAR_PARENTID));
        }

        return $plugin;
    }
}
