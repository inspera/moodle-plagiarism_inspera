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
 * Post-installation migration script.
 *
 * @return bool
 */
function xmldb_plagiarism_inspera_install() {
    global $DB;

    // 1. Check if the OLD plugin's data exists
    // We check for the main submission table.
    if (!$DB->get_manager()->table_exists('plagiarism_originality_subs')) {
        // No old data found. This is a fresh install for a new customer.
        return true;
    }

    // 2. Migrate CONFIGURATION
    // Mapping: plagiarism_originality_conf -> plagiarism_inspera_config
    // (Assuming you standardized the table name to '_config' in the new plugin)
    if ($DB->get_manager()->table_exists('plagiarism_originality_conf')) {
        $sql = "INSERT INTO {plagiarism_inspera_config} (cm, name, value)
                SELECT cm, name, value 
                FROM {plagiarism_originality_conf}";

        try {
            $DB->execute($sql);
            mtrace("Migrated configuration from plagiarism_originality.");
        } catch (Exception $e) {
            // Log error but don't stop installation
            mtrace("Warning: Could not migrate config: " . $e->getMessage());
        }
    }

    // 3. Migrate SUBMISSIONS
    // Mapping: plagiarism_originality_subs -> plagiarism_inspera_subs
    // We explicitly list columns to be safe.
    // Note: We use REPLACE(identifier, ...) to fix the file paths in the DB.

    $sql = "INSERT INTO {plagiarism_inspera_subs} 
            (cm, userid, submissionid, storedfileid, identifier, status, 
             similarity, originality, timecreated, timemodified, error, 
             externalid, ai_index, translation_similarity, character_replacement, 
             hidden_text, image_as_text, description)
            SELECT 
             cm, userid, submissionid, storedfileid, 
             REPLACE(identifier, 'plagiarism_originality', 'plagiarism_inspera'), 
             status, similarity, originality, timecreated, timemodified, error, 
             externalid, ai_index, translation_similarity, character_replacement, 
             hidden_text, image_as_text, description
            FROM {plagiarism_originality_subs}";

    try {
        $DB->execute($sql);
        mtrace("Migrated submissions from plagiarism_originality.");

        // 4. Disable the OLD plugin to prevent 'Double Submission' conflicts
        // We set the 'enabled' flag of the old plugin to 0 in the global config.
        set_config('enabled', 0, 'plagiarism_originality');
        mtrace("Disabled old plagiarism_originality plugin to prevent conflicts.");

    } catch (Exception $e) {
        mtrace("Warning: Could not migrate submissions: " . $e->getMessage());
    }

    return true;
}