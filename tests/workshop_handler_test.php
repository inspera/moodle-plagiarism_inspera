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
 * Tests for the workshop display handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\display\workshop_handler;
use plagiarism_inspera\services\display\report_formatter;

/**
 * Tests for the workshop display handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \plagiarism_inspera\services\display\workshop_handler
 */
final class workshop_handler_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true); // Wipes the DB clean after the test.
    }

    /**
     * Tests the generation of links for an online-text submission in a Workshop.
     *
     * @covers \plagiarism_inspera\services\display\workshop_handler::get_links
     */

    public function test_get_links_online_text(): void {
        global $DB;

        $this->setAdminUser();

        // 1. Setup: Create a fake course, student, and workshop.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        $workshop = $generator->create_module('workshop', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('workshop', $workshop->id);

        // 2. Setup: Insert a fake row into our plugin's database table.
        $record = new \stdClass();
        $record->cm = $cm->id;
        $record->userid = $student->id;
        $record->storedfileid = null; // Online text.
        $record->status = 'finished';
        $record->similarity = 15; // Low risk.
        $record->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $record);

        // 3. Execute: Call our new handler.
        $formatter = new report_formatter();
        $handler = new workshop_handler($DB, $formatter);

        // Mock the data Moodle would pass to get_links.
        $linkarray = [
            'cmid' => $cm->id,
            'userid' => $student->id,
            'content' => '<p>Here is my workshop essay.</p>',
        ];

        // Mock the config so we don't need to populate plagiarism_inspera_config.
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        // True = simulate the user is a Grader/Teacher.
        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // 4. Assert: Did it successfully find the DB row and format it?
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('15', $html); // The 15% score.
        $this->assertStringContainsString('low', $html); // The low risk class.
    }
}
