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

use core_privacy\tests\provider_testcase;
use context_module;

/**
 * Privacy Provider testcase for plagiarism_inspera.
 */
final class provider_test extends provider_testcase {
    /**
     * Test getting the metadata.
     * @covers \plagiarism_inspera\privacy\provider::get_metadata
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('plagiarism_inspera');
        $newcollection = provider::get_metadata($collection);
        $itemlist = $newcollection->get_collection();

        $this->assertNotEmpty($itemlist);
    }

    /**
     * Test that all plagiarism data for a specific user is deleted,
     * and that associated temp files are safely unlinked.
     * @covers \plagiarism_inspera\privacy\provider::delete_plagiarism_for_user
     */
    public function test_delete_plagiarism_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        // 1. Setup IDs.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // Utilized the imported context_module class here.
        $context = context_module::instance($cm->id);

        // 2. Setup Dummy File.
        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/dummy_privacy_test.html';
        file_put_contents($filepath, 'dummy data');
        $this->assertFileExists($filepath);

        // 3. Insert record.
        $DB->insert_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 10,
            'identifier' => $filepath,
            'timecreated' => time(),
        ]);

        $this->assertEquals(1, $DB->count_records('plagiarism_inspera_subs', ['userid' => $user->id]));

        // 4. Trigger the deletion.
        provider::delete_plagiarism_for_user($user->id, $context);

        // 5. Verify record is gone AND file is unlinked.
        $this->assertEquals(0, $DB->count_records('plagiarism_inspera_subs', ['userid' => $user->id]));
        $this->assertFileDoesNotExist($filepath);
    }

    /**
     * Test that all data in a specific context is deleted.
     * @covers \plagiarism_inspera\privacy\provider::delete_plagiarism_for_context
     */
    public function test_delete_plagiarism_for_context(): void {
        global $DB;
        $this->resetAfterTest();

        // 1. Setup IDs.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // Utilized the imported context_module class here.
        $context = context_module::instance($cm->id);

        // 2. Setup Dummy File.
        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/dummy_context_test.html';
        file_put_contents($filepath, 'dummy data');

        // 3. Insert records.
        $DB->insert_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user1->id,
            'submissionid' => 20,
            'identifier' => $filepath,
            'timecreated' => time(),
        ]);

        $DB->insert_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user2->id,
            'submissionid' => 21,
            'timecreated' => time(),
        ]);

        $this->assertEquals(2, $DB->count_records('plagiarism_inspera_subs', ['cm' => $cm->id]));
        $this->assertFileExists($filepath);

        // 4. Trigger deletion for the entire context.
        provider::delete_plagiarism_for_context($context);

        // 5. Verify records are gone and file is deleted.
        $this->assertEquals(0, $DB->count_records('plagiarism_inspera_subs', ['cm' => $cm->id]));
        $this->assertFileDoesNotExist($filepath);
    }
}
