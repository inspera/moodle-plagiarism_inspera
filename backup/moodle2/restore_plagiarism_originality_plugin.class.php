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
 * Contains class restore_plagiarism_originality_plugin
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore class for the Inspera Originality plagiarism plugin.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_plagiarism_originality_plugin extends restore_plagiarism_plugin {

    /**
     * Returns the paths to be handled by the plugin at module (assignment) level.
     * VISIBILITY: Protected (Standard for Restore)
     */
    protected function define_module_plugin_structure() {
        $paths = array();

        // ------------------------------------------
        // 1. SETTINGS (The "Missing Link" fix)
        // ------------------------------------------
        // We give this a unique name 'originalityconfigmod' to match the processing function below.
        $elename = 'originalityconfigmod';

        // This path MUST match the XML structure defined in your BACKUP file.
        // /originality_configs/originality_config
        $elepath = $this->get_pathfor('/originality_configs/originality_config');
        $paths[] = new restore_path_element($elename, $elepath);

        // ------------------------------------------
        // 2. SUBMISSIONS (User Data - Optional but recommended)
        // ------------------------------------------
        // If you implemented the User Data backup, we restore it here.
        if ($this->task->get_setting_value('userinfo')) {
            $elename = 'originalitysubs';
            // /originality_subs/originality_sub
            $elepath = $this->get_pathfor('/originality_subs/originality_sub');
            $paths[] = new restore_path_element($elename, $elepath);
        }

        return $paths;
    }

    /**
     * PROCESS: Assignment Settings
     * This function name matches the $elename 'originalityconfigmod' above.
     */
    public function process_originalityconfigmod($data) {
        global $DB;
        $data = (object)$data;

        // Safety check: Ensure we are inside a module restore
        if (empty($this->task->get_moduleid())) {
            return;
        }

        // MAP: Old CM ID -> New CM ID
        $data->cm = $this->task->get_moduleid();

        // INSERT: Create the settings row for the new assignment
        // (Urkund just inserts directly, which is fine for restores)
        $DB->insert_record('plagiarism_originality_conf', $data);
    }

    /**
     * PROCESS: Submission Data (Scores/Reports)
     * This function name matches the $elename 'originalitysubs' above.
     */
    public function process_originalitysubs($data) {
        global $DB;
        $data = (object)$data;

        // 1. Map Context
        $data->cm = $this->task->get_moduleid();

        // 2. Map User (Critical: The user ID in the new course might be different)
        $data->userid = $this->get_mappingid('user', $data->userid);

        // 3. Handle File Mapping (Optional/Advanced)
        // For now, restoring the record with the old file ID is "safe enough"
        // because the report URL usually relies on external IDs or content hashes.
        // If you need exact file mapping, you would need:
        // $data->storedfileid = $this->get_mappingid('file', $data->storedfileid);

        $DB->insert_record('plagiarism_originality_subs', $data);
    }
}
