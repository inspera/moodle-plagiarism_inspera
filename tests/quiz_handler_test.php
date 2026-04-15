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
 * Tests for the quiz display handler.
 *
 * @category   test
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\display\quiz_handler;
use plagiarism_inspera\services\display\report_formatter;

/**
 * Tests for the quiz display handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \plagiarism_inspera\services\display\quiz_handler
 */
final class quiz_handler_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Tests the generation of links for a file attachment in a Quiz.
     *
     * @covers \plagiarism_inspera\services\display\quiz_handler::get_links
     */
    public function test_get_links_file_attachment(): void {
        global $DB;
        $this->setAdminUser();

        // 1. Setup Data Generator.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $fakefileid = 999;

        // 2. Insert our plugin's score linking to a specific file ID.
        $record = new \stdClass();
        $record->cm = $cm->id;
        $record->userid = $student->id;
        $record->storedfileid = $fakefileid; // Matches the mock below.
        $record->status = 'finished';
        $record->similarity = 45; // Medium risk.
        $record->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $record);

        // 3. Mock the Moodle stored_file object to bypass complex File API setup.
        $mockfile = $this->createMock(\stored_file::class);
        $mockfile->method('get_id')->willReturn($fakefileid);

        // 4. Execute the Handler.
        $formatter = new report_formatter();
        $handler = new quiz_handler($DB, $formatter);

        $linkarray = [
            'cmid' => $cm->id,
            'userid' => $student->id,
            'file' => $mockfile, // Pass the mocked file object!
        ];
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // 5. Assertions.
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('45', $html);
        $this->assertStringContainsString('medium', $html); // Should be yellow/medium risk.
    }
}
