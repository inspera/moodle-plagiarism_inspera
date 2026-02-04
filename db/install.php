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
 * Safely migrates data from the old 'plagiarism_originality' plugin if it exists.
 *
 * @return bool
 */
function xmldb_plagiarism_inspera_install() {
    global $DB;

    // --- 1. MIGRATE CONFIGURATION ---
    if ($DB->get_manager()->table_exists('plagiarism_originality_conf')) {
        // Only copy if the new config table is empty (prevent duplicates on re-install)
        if ($DB->count_records('plagiarism_inspera_config') == 0) {
            try {
                // Dynamic mapping: old 'conf' -> new 'config'
                $sql = "INSERT INTO {plagiarism_inspera_config} (cm, name, value)
                        SELECT cm, name, value FROM {plagiarism_originality_conf}";
                $DB->execute($sql);
                mtrace("Migrated configuration successfully.");
            } catch (Exception $e) {
                mtrace("Warning: Config migration failed: " . $e->getMessage());
            }
        }
    }

    // --- 2. MIGRATE SUBMISSIONS (Robust Column Matching) ---
    if ($DB->get_manager()->table_exists('plagiarism_originality_subs')) {

        // Only migrate if new table is empty
        if ($DB->count_records('plagiarism_inspera_subs') == 0) {

            // A. Get columns from both tables
            $old_columns = $DB->get_columns('plagiarism_originality_subs');
            $new_columns = $DB->get_columns('plagiarism_inspera_subs');

            // B. Find common columns (Intersection)
            // We strip 'id' to allow the new table to generate fresh clean IDs,
            // OR keep it to preserve history. Preserving is usually better for logs.
            $common_keys = array_intersect(array_keys($old_columns), array_keys($new_columns));

            // C. Build the SQL dynamically
            $select_parts = [];
            $insert_parts = [];

            foreach ($common_keys as $col) {
                $insert_parts[] = $col;

                if ($col === 'identifier') {
                    // Special handling: Rename the folder path in the DB
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
                mtrace("Migrated submissions successfully (" . count($common_keys) . " columns matched).");

                // D. Disable the old plugin to prevent conflicts
                set_config('enabled', 0, 'plagiarism_originality');

            } catch (Exception $e) {
                mtrace("Warning: Submission migration failed: " . $e->getMessage());
                // Detailed debug info in error log
                error_log("MIGRATION ERROR SQL: " . $sql);
            }
        }
    }

    return true;
}