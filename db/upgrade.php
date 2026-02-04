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

    // Trigger upgrade if older than your new version 2026011900
    if ($oldversion < 2026011900) {

        // 1. Define and Add the new 'submissionid' field
        $table = new xmldb_table('plagiarism_inspera_subs');
        $field = new xmldb_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cm');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Backfill Data (Map UserID -> SubmissionID)
        // We use a Recordset for cross-database compatibility (Postgres/MySQL)
        // This links existing reports to the user's LATEST assignment submission.

        $sql = "SELECT p.id AS plugindataid, s.id AS submissionid
                  FROM {plagiarism_inspera_subs} p
                  JOIN {course_modules} cm ON p.cm = cm.id
                  JOIN {assign} a ON a.id = cm.instance
                  JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = p.userid
                 WHERE p.submissionid = 0 
                   AND s.latest = 1";

        $rs = $DB->get_recordset_sql($sql);

        foreach ($rs as $record) {
            $DB->set_field('plagiarism_inspera_subs', 'submissionid', $record->submissionid, ['id' => $record->plugindataid]);
        }
        $rs->close();

        // 3. Add the new index optimized for Group lookups
        $index = new xmldb_index('submission_file', XMLDB_INDEX_NOTUNIQUE, ['submissionid', 'storedfileid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 4. Cleanup: Remove old UserID-centric indexes that are now obsolete
        $oldIndex1 = new xmldb_index('cm_userid_storedfile', XMLDB_INDEX_NOTUNIQUE, ['cm', 'userid', 'storedfileid']);
        if ($dbman->index_exists($table, $oldIndex1)) {
            $dbman->drop_index($table, $oldIndex1);
        }

        $oldIndex2 = new xmldb_index('cm_userid_identifier', XMLDB_INDEX_NOTUNIQUE, ['cm', 'userid', 'identifier']);
        if ($dbman->index_exists($table, $oldIndex2)) {
            $dbman->drop_index($table, $oldIndex2);
        }

        // Main savepoint reached
        upgrade_plugin_savepoint(true, 2026011900, 'plagiarism', 'originality');
    }

    // Add error fields to store failure details and a filterable reason. Version 2026012100.
    if ($oldversion < 2026012100) {
        $table = new xmldb_table('plagiarism_inspera_subs');

        // errorresponse TEXT
        $field1 = new xmldb_field('errorresponse', XMLDB_TYPE_TEXT, null, null, null, null, null, 'storedfileid');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        // errorreason CHAR(30)
        $field2 = new xmldb_field('errorreason', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'errorresponse');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Index on errorreason for filtering
        $index = new xmldb_index('errorreason', XMLDB_INDEX_NOTUNIQUE, ['errorreason']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026012100, 'plagiarism', 'originality');
    }

    // Consolidate to a single Description column. Migrate data and drop old fields. Version 2026012200.
    if ($oldversion < 2026012200) {
        $table = new xmldb_table('plagiarism_inspera_subs');

        // 1) Add description TEXT if it does not exist yet.
        $descfield = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null, 'storedfileid');
        if (!$dbman->field_exists($table, $descfield)) {
            $dbman->add_field($table, $descfield);
        }

        // 2) Migrate data from errorresponse to description if present.
        // Use direct SQL to be DB-agnostic and efficient.
        if ($dbman->field_exists($table, new xmldb_field('errorresponse'))) {
            $DB->execute("UPDATE {plagiarism_inspera_subs} SET description = COALESCE(description, errorresponse) WHERE (description IS NULL OR description = '') AND errorresponse IS NOT NULL");
        }

        // 3) Drop index on errorreason if exists
        $erridx = new xmldb_index('errorreason', XMLDB_INDEX_NOTUNIQUE, ['errorreason']);
        if ($dbman->index_exists($table, $erridx)) {
            $dbman->drop_index($table, $erridx);
        }

        // 4) Drop old fields errorreason and errorresponse if they exist
        $errreasonfield = new xmldb_field('errorreason');
        if ($dbman->field_exists($table, $errreasonfield)) {
            $dbman->drop_field($table, $errreasonfield);
        }

        $errrespfield = new xmldb_field('errorresponse');
        if ($dbman->field_exists($table, $errrespfield)) {
            $dbman->drop_field($table, $errrespfield);
        }

        upgrade_plugin_savepoint(true, 2026012200, 'plagiarism', 'originality');
    }

    if ($oldversion < 2026012900) {
        $table = new xmldb_table('plagiarism_inspera_subs');

        // 1. Ensure 'description' exists
        $descfield = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null, 'storedfileid');
        if (!$dbman->field_exists($table, $descfield)) {
            $dbman->add_field($table, $descfield);
        }

        // 2. Ensure 'errorresponse' data is migrated (if we missed the previous step)
        // We check if BOTH fields exist. If so, migrate data.
        $oldfield = new xmldb_field('errorresponse');
        if ($dbman->field_exists($table, $oldfield) && $dbman->field_exists($table, $descfield)) {
            $DB->execute("UPDATE {plagiarism_inspera_subs} SET description = COALESCE(description, errorresponse) WHERE (description IS NULL OR description = '') AND errorresponse IS NOT NULL");
        }

        // 3. Ensure old fields are dropped
        if ($dbman->field_exists($table, $oldfield)) {
            $dbman->drop_field($table, $oldfield);
        }

        $reasonfield = new xmldb_field('errorreason');
        if ($dbman->field_exists($table, $reasonfield)) {
            $dbman->drop_field($table, $reasonfield);
        }

        $reasonidx = new xmldb_index('errorreason', XMLDB_INDEX_NOTUNIQUE, ['errorreason']);
        if ($dbman->index_exists($table, $reasonidx)) {
            $dbman->drop_index($table, $reasonidx);
        }

        upgrade_plugin_savepoint(true, 2026012900, 'plagiarism', 'originality');
    }

    return true;
}
