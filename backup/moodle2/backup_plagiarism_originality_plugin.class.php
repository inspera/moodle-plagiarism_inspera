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
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_plagiarism_inspera_plugin extends backup_plagiarism_plugin {

    /**
     * Define the structure for the module (Assignment, Forum, etc.) backup.
     * This attaches our XML inside the module's XML.
     */
    public function define_module_plugin_structure() {

        // 1. Check if the teacher selected "Include User Data" in the backup settings
        $userinfo = $this->get_setting_value('userinfo');

        // 2. Get the plugin element (The root node for this plugin in the XML)
        $plugin = $this->get_plugin_element();

        // 3. Create a wrapper (Standard practice to keep XML tidy)
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        // ==========================================
        // PART A: SETTINGS (The Config Table)
        // ==========================================

        // Define the XML elements for settings
        $originalityconfigs = new backup_nested_element('originality_configs');
        $originalityconfig  = new backup_nested_element('originality_config', array('id'), array('name', 'value'));

        // Build the tree
        $pluginwrapper->add_child($originalityconfigs);
        $originalityconfigs->add_child($originalityconfig);

        // Define the Source (Database -> XML)
        // backup::VAR_PARENTID here maps to the Course Module ID (cm) of the assignment being backed up.
        $originalityconfig->set_source_table('plagiarism_inspera_config', array('cm' => backup::VAR_PARENTID));

        // ==========================================
        // PART B: SUBMISSIONS (The Scores/Reports)
        // ==========================================
        // Only backup these records if "User Data" is included

        if ($userinfo) {
            $originalitysubs = new backup_nested_element('originality_subs');
            $originalitysub  = new backup_nested_element('originality_sub', array('id'), array(
                'userid',
                'submissionid',
                'storedfileid',
                'identifier',
                'status',
                'similarity',
                'externalid',
                'timecreated',
                'timemodified'
            ));

            $pluginwrapper->add_child($originalitysubs);
            $originalitysubs->add_child($originalitysub);

            // Define Source
            $originalitysub->set_source_table('plagiarism_inspera_subs', array('cm' => backup::VAR_PARENTID));
        }

        return $plugin;
    }
}
