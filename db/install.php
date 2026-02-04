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

    // --- 1. MIGRATE CONFIGURATION (DIRECT COPY) ---
    // Your code still uses 'originality_' prefixes (e.g. originality_client_id).
    // So we must copy the keys EXACTLY as they are.

    if ($DB->get_manager()->table_exists('plagiarism_originality_conf')) {
        if ($DB->count_records('plagiarism_inspera_config') == 0) {
            try {
                // NO REPLACE() here. We want 'originality_client_id' to stay 'originality_client_id'.
                $sql = "INSERT INTO {plagiarism_inspera_config} (cm, name, value)
                        SELECT cm, name, value 
                        FROM {plagiarism_originality_conf}";

                $DB->execute($sql);
                mtrace("Migrated configuration successfully (Exact Copy).");
            } catch (Exception $e) {
                mtrace("Warning: Config migration failed: " . $e->getMessage());
            }
        }
    }

    // --- 2. MIGRATE SUBMISSIONS (PATH FIX ONLY) ---
    if ($DB->get_manager()->table_exists('plagiarism_originality_subs')) {
        if ($DB->count_records('plagiarism_inspera_subs') == 0) {

            // Get columns safely
            $old_columns = $DB->get_columns('plagiarism_originality_subs');
            $new_columns = $DB->get_columns('plagiarism_inspera_subs');
            $common_keys = array_intersect(array_keys($old_columns), array_keys($new_columns));

            $select_parts = [];
            $insert_parts = [];

            foreach ($common_keys as $col) {
                $insert_parts[] = $col;

                // We MUST still update the file path in 'identifier'.
                // The plugin folder changed, so the path on disk changed from
                // .../temp/plagiarism_originality/... to .../temp/plagiarism_inspera/...
                if ($col === 'identifier') {
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
                $DB->execute($sql);
                mtrace("Migrated submissions successfully.");

                // Disable old plugin
                set_config('enabled', 0, 'plagiarism_originality');

            } catch (Exception $e) {
                mtrace("Warning: Submission migration failed: " . $e->getMessage());
            }
        }
    }

    return true;
}
