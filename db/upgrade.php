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
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_plagiarism_inspera_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // ---  CLEANUP LEGACY TRANSLATION DEFAULTS ---
    // This removes global defaults (cm=0) for translations that were
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

    // --- ADD ORIGINALITY_SCORE COLUMN ---
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

    return true;
}
