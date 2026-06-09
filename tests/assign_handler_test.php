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
 * @category   test
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
     * Tests the generation of links for online text in an Assignment.
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

    /**
     * Tests that the assignment handler strictly ignores records with matching
     * submissionids if they belong to a different course module context (Polymorphic safety test).
     *
     * @covers \plagiarism_inspera\services\display\assign_handler::get_links
     */
    public function test_get_links_ignores_polymorphic_collisions(): void {
        global $DB;
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // 1. Create a fake Moodle Assignment Submission.
        $submission = new \stdClass();
        $submission->assignment = $assign->id;
        $submission->userid = $student->id;
        $submission->status = 'submitted';
        $submission->latest = 1;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submissionid = $DB->insert_record('assign_submission', $submission);

        // 2. CRITICAL: Insert a colliding record belonging to a different existing CMID (e.g., a Forum post).
        // It shares the exact same submissionid numeric value, but is a different module instance.
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $forumcm = get_coursemodule_from_instance('forum', $forum->id);

        $collidingrecord = new \stdClass();
        $collidingrecord->cm = $forumcm->id; // Valid foreign key!
        $collidingrecord->userid = $student->id;
        $collidingrecord->submissionid = $submissionid; // Exact match collision!
        $collidingrecord->storedfileid = null;
        $collidingrecord->status = 'finished';
        $collidingrecord->similarity = 45;
        $collidingrecord->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $collidingrecord);

        // 3. Execute the Handler using the valid Assignment configuration.
        $formatter = new report_formatter();
        $handler = new assign_handler($DB, $formatter);

        $linkarray = [
            'cmid' => $cm->id, // This is the assignment CM, not the forum one!
            'userid' => $student->id,
            'content' => '<p>My assignment text</p>',
        ];
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // 4. Assertions: Because of our fix, the handler should NOT find the record
        // due to the CM mismatch, returning an empty string instead of the colliding data.
        $this->assertEmpty($html);
    }
}
