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

use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Privacy Provider testcase for plagiarism_inspera.
 */
class provider_test extends provider_testcase {

    /**
     * Test getting the metadata - Ensures your lang strings and tables are mapped.
     */
    public function test_get_metadata() {
        $collection = new \core_privacy\local\metadata\collection('plagiarism_inspera');
        $newcollection = provider::get_metadata($collection);
        $this->assertNotEmpty($newcollection->get_collection());
    }

    /**
     * Test deletion for a user - Crucial for GDPR "Right to be Forgotten".
     */
    public function test_delete_plagiarism_for_user() {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $context = \context_module::instance($cm->id);

        $DB->insert_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 10,
            'timecreated' => time(),
        ]);

        provider::delete_plagiarism_for_user($user->id, $context);
        $this->assertEquals(0, $DB->count_records('plagiarism_inspera_subs', ['userid' => $user->id]));
    }
}

