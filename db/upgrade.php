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
 * Originality upgrade tasks.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_plagiarism_inspera_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // CLEANUP LEGACY TRANSLATION DEFAULTS.
    // This removes global defaults (cm=0) for translations that were.
    // removed from the admin UI, preventing them from affecting new activities.
    if ($oldversion < 2026031301) {
        $likeenable = $DB->sql_like('name', ':nameenable', false);
        $likelangs = $DB->sql_like('name', ':namelangs', false);
        $params = [
            'nameenable' => $DB->sql_like_escape('originality_enable_translations_') . '%',
            'namelangs' => $DB->sql_like_escape('originality_translation_languages_') . '%',
        ];

        $DB->delete_records_select(
            'plagiarism_inspera_config',
            "cm = 0 AND ($likeenable OR $likelangs)",
            $params
        );

        // Plagiarism savepoint reached.
        upgrade_plugin_savepoint(true, 2026031301, 'plagiarism', 'inspera');
    }

    // ADD ORIGINALITY_SCORE COLUMN.
    if ($oldversion < 2026031601) {
        $table = new xmldb_table('plagiarism_inspera_subs');

        // Define the field exactly as it appears in install.xml.
        // Parameters: name, type, precision, unsigned, notnull, sequence, default, previous field.
        $field = new xmldb_field('originality_score', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'similarity');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Plagiarism savepoint reached.
        upgrade_plugin_savepoint(true, 2026031601, 'plagiarism', 'inspera');
    }

    // REPLACE STRICT FOREIGN KEY WITH INDEX FOR SUBMISSIONID.
    // This allows submissionid to safely store Forum Post IDs and Quiz Attempt IDs
    // without triggering database constraint errors against the assign_submission table.
    if ($oldversion < 2026060501) {
        $table = new xmldb_table('plagiarism_inspera_subs');

        // 1. Define the old strict foreign key so Moodle can find it.
        $key = new xmldb_key('submissionid', XMLDB_KEY_FOREIGN, ['submissionid'], 'assign_submission', ['id']);

        // Moodle requires find_key_name() to check if a key exists!
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }

        // 2. Define the new flexible index for fast searching.
        $index = new xmldb_index('submissionid', XMLDB_INDEX_NOTUNIQUE, ['submissionid']);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Plagiarism savepoint reached.
        upgrade_plugin_savepoint(true, 2026060501, 'plagiarism', 'inspera');
    }

    if ($oldversion < 2026060800) {
        // Define index cm_userid_sub_stored_ix to be added to plagiarism_inspera_subs.
        $table = new xmldb_table('plagiarism_inspera_subs');
        $index = new xmldb_index(
            'cm_userid_sub_stored_ix',
            XMLDB_INDEX_NOTUNIQUE,
            ['cm', 'userid', 'submissionid', 'storedfileid']
        );

        // Conditionally launch add index cm_userid_sub_stored_ix.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Inspera savepoint reached.
        upgrade_plugin_savepoint(true, 2026060800, 'plagiarism', 'inspera');
    }

    return true;
}
