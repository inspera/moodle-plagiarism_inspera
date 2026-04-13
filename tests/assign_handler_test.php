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
 * Tests for the assign display handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\display\assign_handler;
use plagiarism_inspera\services\display\report_formatter;

/**
 * Tests for the assignment display handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \plagiarism_inspera\services\display\assign_handler
 */
final class assign_handler_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Tests the generation of links for a file attachment in an Assignment.
     *
     * @covers \plagiarism_inspera\services\display\assign_handler::get_links
     */
    public function test_get_links_online_text(): void {
        global $DB;
        $this->setAdminUser(); // Prevent Guest output renderer errors.

        // 1. Setup Data Generator.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // 2. Create a fake Moodle Assignment Submission.
        $submission = new \stdClass();
        $submission->assignment = $assign->id;
        $submission->userid = $student->id;
        $submission->status = 'submitted';
        $submission->latest = 1;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submissionid = $DB->insert_record('assign_submission', $submission);

        // 3. Insert our plugin's score linking to that specific submissionid.
        $record = new \stdClass();
        $record->cm = $cm->id;
        $record->userid = $student->id;
        $record->submissionid = $submissionid; // Map it directly!
        $record->storedfileid = null;
        $record->status = 'finished';
        $record->similarity = 92; // High risk.
        $record->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $record);

        // 4. Execute the Handler.
        $formatter = new report_formatter();
        $handler = new assign_handler($DB, $formatter);

        $linkarray = [
            'cmid' => $cm->id,
            'userid' => $student->id,
            'content' => '<p>My assignment text</p>',
        ];
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // 5. Assertions.
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('92', $html);
        $this->assertStringContainsString('high', $html); // Should be red/high risk.
    }
}
