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
 * Tests for the forum display handler.
 *
 * @category   test
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\display\forum_handler;
use plagiarism_inspera\services\display\report_formatter;

/**
 * Tests for the forum display handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \plagiarism_inspera\services\display\forum_handler
 */
final class forum_handler_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Tests the generation of links for a file attachment in a Forum.
     *
     * @covers \plagiarism_inspera\services\display\forum_handler::get_links
     */
    public function test_get_links_attachment(): void {
        global $DB;
        $this->setAdminUser();

        // 1. Setup Data Generator.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $postid = 123;
        $fileid = 456;

        // 2. Mock a Moodle stored_file object to simulate a forum attachment.
        $mockfile = $this->createMock(\stored_file::class);
        $mockfile->method('get_component')->willReturn('mod_forum');
        $mockfile->method('get_itemid')->willReturn($postid); // Forum attachments map itemid to post ID.
        $mockfile->method('get_id')->willReturn($fileid);

        // 3. Insert our plugin's score linking to that specific file and post.
        $record = new \stdClass();
        $record->cm = $cm->id;
        $record->userid = $student->id;
        $record->submissionid = $postid;
        $record->storedfileid = $fileid;
        $record->status = 'finished';
        $record->similarity = 15; // Low risk.
        $record->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $record);

        // 4. Execute the Handler.
        $formatter = new report_formatter();
        $handler = new forum_handler($DB, $formatter);

        $linkarray = [
            'cmid' => $cm->id,
            'userid' => $student->id,
            'file' => $mockfile,
        ];
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // 5. Assertions.
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('15', $html);
    }

    /**
     * Tests the generation of links for inline text in a Forum post.
     *
     * @covers \plagiarism_inspera\services\display\forum_handler::get_links
     */
    public function test_get_links_online_text(): void {
        global $DB;
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $posttext = '<p>This is my original forum discussion post.</p>';

        // 1. Create a fake discussion and post directly in the DB so the handler can query its text.
        $discussionid = $DB->insert_record('forum_discussions', (object)[
            'course' => $course->id,
            'forum' => $forum->id,
            'name' => 'Test Discussion',
            'firstpost' => 0,
            'userid' => $student->id,
            'groupid' => 0,
            'assessed' => 0,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $post = new \stdClass();
        $post->discussion = $discussionid;
        $post->parent = 0;
        $post->userid = $student->id;
        $post->created = time();
        $post->modified = time();
        $post->mailed = 0;
        $post->subject = 'Test Subject';
        $post->message = $posttext;
        $post->messageformat = FORMAT_HTML;
        $post->messagetrust = 0;
        $post->attachment = '';
        $post->totalscore = 0;
        $post->mailnow = 0;
        $post->privatereplyto = 0;
        $postid = $DB->insert_record('forum_posts', $post);

        // 2. Insert our plugin's text record.
        $record = new \stdClass();
        $record->cm = $cm->id;
        $record->userid = $student->id;
        $record->submissionid = $postid;
        $record->storedfileid = null;
        $record->status = 'finished';
        $record->similarity = 88; // High risk.
        $record->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $record);

        // 3. Execute the Handler.
        $formatter = new report_formatter();
        $handler = new forum_handler($DB, $formatter);

        $linkarray = [
            'cmid' => $cm->id,
            'userid' => $student->id,
            'content' => $posttext,
        ];
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // 4. Assertions.
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('88', $html);
        $this->assertStringContainsString('high', $html); // Should trigger the high-risk CSS classes.
    }

    /**
     * Tests that the forum handler strictly ignores text records with matching
     * submissionids if they belong to a different course module context.
     *
     * @covers \plagiarism_inspera\services\display\forum_handler::get_links
     */
    public function test_get_links_ignores_polymorphic_collisions(): void {
        global $DB;
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();

        // Target Module: The Forum we are viewing.
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $forumcm = get_coursemodule_from_instance('forum', $forum->id);

        $posttext = '<p>A seemingly identical text payload.</p>';

        // Create the parent discussion to satisfy foreign key constraints.
        $discussionid = $DB->insert_record('forum_discussions', (object)[
            'course' => $course->id,
            'forum' => $forum->id,
            'name' => 'Collision Test Discussion',
            'firstpost' => 0,
            'userid' => $student->id,
            'groupid' => 0,
            'assessed' => 0,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $post = new \stdClass();
        $post->discussion = $discussionid;
        $post->userid = $student->id;
        $post->created = time();
        $post->modified = time();
        $post->subject = 'Collision Test';
        $post->message = $posttext;
        $postid = $DB->insert_record('forum_posts', $post);

        // Colliding Module: An Assignment in the same course.
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id);

        // CRITICAL: Insert a plugin score linking to the ASSIGN CM instead of the FORUM CM,
        // but sharing the exact same numeric submissionid (postid).
        $collidingrecord = new \stdClass();
        $collidingrecord->cm = $assigncm->id; // Mismatch!
        $collidingrecord->userid = $student->id;
        $collidingrecord->submissionid = $postid; // Exact numeric match!
        $collidingrecord->storedfileid = null;
        $collidingrecord->status = 'finished';
        $collidingrecord->similarity = 99;
        $collidingrecord->timecreated = time();
        $DB->insert_record('plagiarism_inspera_subs', $collidingrecord);

        $formatter = new report_formatter();
        $handler = new forum_handler($DB, $formatter);

        $linkarray = [
            'cmid' => $forumcm->id, // Passing the Forum CM context.
            'userid' => $student->id,
            'content' => $posttext,
        ];
        $plagiarismvalues = ['originality_display_type' => 'similarity'];

        $html = $handler->get_links($linkarray, $plagiarismvalues, true);

        // Because of the strict cm validation inside the online text query,
        // it should ignore the colliding assign record and return an empty string.
        $this->assertEmpty($html);
    }
}
