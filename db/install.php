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

/**
 * Post-installation migration script for Inspera Originality.
 *
 * @return bool
 */
function xmldb_plagiarism_inspera_install() {
    global $DB;

    // --- 0. MIGRATE GLOBAL ADMIN SETTINGS (Fixed) ---
    // These live in {config_plugins}.

    // 1. Fetch all settings from the OLD plugin
    $old_configs = $DB->get_records('config_plugins', ['plugin' => 'plagiarism_originality']);

    if (!empty($old_configs)) {

        // 2. Check if the NEW plugin already has a Client ID configured.
        // We check this specific key to avoid overwriting if you manually set it up already.
        $existing_client = get_config('plagiarism_inspera', 'clientid');

        if (!$existing_client) {
            mtrace("Migrating global settings from plagiarism_originality...");

            foreach ($old_configs as $config) {
                // SKIP 'version' (Moodle manages this automatically)
                if ($config->name === 'version') {
                    continue;
                }

                // SKIP 'enabled' (Let the admin manually enable the new plugin when ready)
                if ($config->name === 'enabled') {
                    continue;
                }

                // Copy everything else (baseurl, clientid, apitoken, enable_mod_quiz, etc.)
                // We assume the keys are the same (e.g. 'clientid' -> 'clientid')
                set_config($config->name, $config->value, 'plagiarism_inspera');
            }
            mtrace("Global settings migration complete.");
        }
    }

    // --- 1. MIGRATE MODULE DEFAULTS (Custom Table) ---
    if ($DB->get_manager()->table_exists('plagiarism_originality_conf')) {
        // Only if new table is empty
        if ($DB->count_records('plagiarism_inspera_config') == 0) {
            try {
                // Direct copy of module defaults
                $sql = "INSERT INTO {plagiarism_inspera_config} (cm, name, value)
                        SELECT cm, name, value 
                        FROM {plagiarism_originality_conf}";

                $DB->execute($sql);
                mtrace("Migrated module defaults successfully.");
            } catch (Exception $e) {
                mtrace("Warning: Config migration failed: " . $e->getMessage());
            }
        }
    }

    // --- 2. MIGRATE SUBMISSIONS (With Path Fix and Sequence Reset) ---
    if ($DB->get_manager()->table_exists('plagiarism_originality_subs')) {
        if ($DB->count_records('plagiarism_inspera_subs') == 0) {

            $old_columns = $DB->get_columns('plagiarism_originality_subs');
            $new_columns = $DB->get_columns('plagiarism_inspera_subs');
            $common_keys = array_intersect(array_keys($old_columns), array_keys($new_columns));

            $select_parts = [];
            $insert_parts = [];

            foreach ($common_keys as $col) {
                $insert_parts[] = $col;
                if ($col === 'identifier') {
                    // Rename folder path in DB
                    $select_parts[] = "REPLACE(identifier, 'plagiarism_originality', 'plagiarism_inspera')";
                } else {
                    $select_parts[] = $col;
                }
            }

            $insert_str = implode(', ', $insert_parts);
            $select_str = implode(', ', $select_parts);

            $sql = "INSERT INTO {plagiarism_inspera_subs} ($insert_str)
                        SELECT $select_str FROM {plagiarism_originality_subs}";

            try {
                // 1. Perform the bulk copy
                $DB->execute($sql);

                // 2. CRITICAL: Reset the auto-increment sequence
                // This tells the DB: "The highest ID is now X, so the next insert should be X+1"
                $DB->get_manager()->reset_sequence('plagiarism_inspera_subs');

                mtrace("Migrated submissions successfully and reset ID sequence.");

                // 3. Disable the old plugin to prevent double-processing
                set_config('enabled', 0, 'plagiarism_originality');

            } catch (Exception $e) {
                mtrace("Warning: Submission migration failed: " . $e->getMessage());
            }
        }
    }

    return true;
}
