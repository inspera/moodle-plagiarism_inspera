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
        $elename = 'insperaconfigmod';
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

        // 1. Map Context & User.
        $data->cm = $this->task->get_moduleid();
        $data->userid = $this->get_mappingid('user', $data->userid);

        // If the user wasn't restored (e.g., restoring without student data), discard this row.
        if (!$data->userid) {
            return;
        }

        // 2. Map Submission ID (Polymorphic Translation).
        $modname = $this->task->get_modulename();

        if ($data->submissionid > 0) {
            $mappingname = '';

            if ($modname === 'assign') {
                $mappingname = 'assign_submission';
            } else if ($modname === 'forum') {
                $mappingname = 'forum_post';
            } else if ($modname === 'hsuforum') {
                $mappingname = 'hsuforum_post';
            } else if ($modname === 'workshop') {
                $mappingname = 'workshop_submission';
            }

            if (!empty($mappingname)) {
                $newsubid = $this->get_mappingid($mappingname, $data->submissionid);
                if ($newsubid) {
                    $data->submissionid = $newsubid;
                } else {
                    // Parent entity wasn't restored (e.g., deleted post). Discard orphaned row.
                    return;
                }
            }
        }

        // 3. FIX: Map Stored File ID.
        if (!empty($data->storedfileid)) {
            $newfileid = $this->get_mappingid('file', (int)$data->storedfileid);
            if ($newfileid) {
                $data->storedfileid = $newfileid;
            } else {
                // The underlying core file attachment wasn't restored; discard orphaned record.
                return;
            }
        } else {
            $data->storedfileid = null;
        }

        // 4. Rebuild the Identifier (Temp Path) to prevent cross-course collisions.
        if (!empty($data->identifier)) {
            $filename = basename($data->identifier);
            $newfilename = $filename;

            // Handle Assignments/Forums/Workshops (onlinetext_cmid_userid_subid_hash.html).
            if (preg_match('/^onlinetext_\d+_\d+_\d+_(.+)\.html$/', $filename, $matches)) {
                $hash = $matches[1];
                $newfilename = "onlinetext_{$data->cm}_{$data->userid}_{$data->submissionid}_{$hash}.html";
            } else if (preg_match('/^quiz_\d+_\d+_(\d+)\.html$/', $filename, $matches)) {
                $oldqaid = $matches[1];
                $newqaid = $this->get_mappingid('question_attempt', $oldqaid) ?: $oldqaid;
                $newfilename = "quiz_{$data->cm}_{$data->userid}_{$newqaid}.html";
            }

            $data->identifier = str_replace($filename, $newfilename, $data->identifier);
        }

        unset($data->id);
        $DB->insert_record('plagiarism_inspera_subs', $data);
    }
}
