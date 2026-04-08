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
 * PHPUnit tests for the workshop_service class.
 *
 * @package     plagiarism_inspera
 * @category    test
 * @copyright   2025 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use plagiarism_inspera\services\workshop_service;
use plagiarism_inspera\services\queue_service;

/**
 * Unit tests for workshop_service.
 *
 * @covers \plagiarism_inspera\services\workshop_service
 */
final class workshop_service_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Test that phase switch enumerates all submissions and their attachments.
     */
    public function test_process_phase_switch_queues_all_submissions(): void {
        global $DB;

        // 1. Setup course and workshop.
        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('workshop', $workshop->id);
        $workshopgenerator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');

        // 2. Setup Student 1 (Online text only).
        $user1 = $this->getDataGenerator()->create_user();
        $workshopgenerator->create_submission($workshop->id, $user1->id, [
            'content' => 'Online text from user 1',
            'contentformat' => FORMAT_HTML,
        ]);

        // 3. Setup Student 2 (Online text AND an attachment).
        $user2 = $this->getDataGenerator()->create_user();
        $sub2id = $workshopgenerator->create_submission($workshop->id, $user2->id, [
            'content' => 'Online text from user 2',
            'contentformat' => FORMAT_HTML,
        ]);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_workshop',
            'filearea'  => 'submission_attachment',
            'itemid'    => $sub2id,
            'filepath'  => '/',
            'filename'  => 'assignment.pdf',
        ], 'Fake PDF content');

        // 4. Mock the queue_service.
        // We expect exactly 3 calls: (User 1 Text) + (User 2 Text) + (User 2 File).
        $mockqueueservice = $this->createMock(queue_service::class);
        $mockqueueservice->expects($this->exactly(3))
            ->method('queue_file');

        // 5. Execute.
        $service = new workshop_service($DB, $mockqueueservice);
        $service->process_phase_switch($workshop->id, $cm->id);
    }

    /**
     * Test that a late submission accurately targets a single record.
     */
    public function test_process_late_submission_queues_single_submission(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('workshop', $workshop->id);
        $workshopgenerator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');

        // Setup a single submission.
        $user1 = $this->getDataGenerator()->create_user();
        $sub1id = $workshopgenerator->create_submission($workshop->id, $user1->id, [
            'content' => 'Late online text',
            'contentformat' => FORMAT_HTML,
        ]);

        // 1. Mock the queue_service. We expect exactly 1 call.
        $mockqueueservice = $this->createMock(queue_service::class);
        $mockqueueservice->expects($this->exactly(1))
            ->method('queue_file');

        // 2. Execute.
        $service = new workshop_service($DB, $mockqueueservice);
        $service->process_late_submission($workshop->id, $cm->id, $sub1id);
    }

    /**
     * Test that invalid submission IDs fail gracefully.
     */
    public function test_process_late_submission_invalid_id_returns_early(): void {
        global $DB;

        // Mock the queue_service. We expect NO calls.
        $mockqueueservice = $this->createMock(queue_service::class);
        $mockqueueservice->expects($this->never())
            ->method('queue_file');

        $service = new workshop_service($DB, $mockqueueservice);
        // Pass non-existent IDs.
        $service->process_late_submission(9999, 9999, 99999);
    }
}


