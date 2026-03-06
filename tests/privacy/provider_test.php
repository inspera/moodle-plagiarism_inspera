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
 * Privacy Provider tests for plagiarism_inspera.
 *
 * @package     plagiarism_inspera
 * @category    test
 * @copyright   2026 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\tests\provider_testcase;

/**
 * Privacy Provider testcase for plagiarism_inspera.
 */
class provider_test extends provider_testcase {

    /**
     * Test getting the metadata.
     * Ensures the plugin correctly declares what data it stores and where.
     */
    public function test_get_metadata() {
        $collection = new \core_privacy\local\metadata\collection('plagiarism_inspera');
        $newcollection = provider::get_metadata($collection);
        $itemlist = $newcollection->get_collection();

        // We expect the collection to contain our table and the external link definitions.
        $this->assertNotEmpty($itemlist);
    }

    /**
     * Test that all plagiarism data for a specific user in a context is deleted.
     */
    public function test_delete_plagiarism_for_user() {
        global $DB;
        $this->resetAfterTest();

        // 1. Setup IDs.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $context = \context_module::instance($cm->id);

        // 2. Insert a dummy record.
        $DB->insert_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 10,
            'timecreated' => time(),
        ]);

        $this->assertEquals(1, $DB->count_records('plagiarism_inspera_subs', ['userid' => $user->id]));

        // 3. Trigger the deletion.
        provider::delete_plagiarism_for_user($user->id, $context);

        // 4. Verify the record is gone.
        $this->assertEquals(0, $DB->count_records('plagiarism_inspera_subs', ['userid' => $user->id]));
    }

    /**
     * Test that all data in a specific context is deleted (e.g., when an activity is deleted).
     */
    public function test_delete_plagiarism_for_context() {
        global $DB;
        $this->resetAfterTest();

        // 1. Setup IDs.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $context = \context_module::instance($cm->id);

        // 2. Insert records for both users in the same context.
        foreach ([$user1, $user2] as $user) {
            $DB->insert_record('plagiarism_inspera_subs', [
                'cm' => $cm->id,
                'userid' => $user->id,
                'submissionid' => 20,
                'timecreated' => time(),
            ]);
        }

        $this->assertEquals(2, $DB->count_records('plagiarism_inspera_subs', ['cm' => $cm->id]));

        // 3. Trigger deletion for the entire context.
        provider::delete_plagiarism_for_context($context);

        // 4. Verify all records for that context are gone.
        $this->assertEquals(0, $DB->count_records('plagiarism_inspera_subs', ['cm' => $cm->id]));
    }
}