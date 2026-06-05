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

global $CFG;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

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
        $sub1id = $workshopgenerator->create_submission($workshop->id, $user1->id, [
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
        $mockqueueservice = $this->createMock(queue_service::class);

        // We expect 3 total calls. Since they have different submission IDs,
        // we use a series of 'withConsecutive' or a broader callback.
        $mockqueueservice->expects($this->exactly(3))
            ->method('queue_file')
            ->with(
                $this->equalTo($cm->id),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->logicalOr(
                    $this->equalTo($sub1id),
                    $this->equalTo($sub2id)
                )
            );

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

        // 1. Mock the queue_service.
        $mockqueueservice = $this->createMock(queue_service::class);
        $mockqueueservice->expects($this->exactly(1))
            ->method('queue_file')
            ->with(
                $this->equalTo($cm->id),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo($sub1id) // Use the real submission ID.
            );

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

    /**
     * Test: Verify that when restricted to 'Only Attachments', online text is ignored.
     */
    public function test_process_phase_switch_respects_files_only_restriction(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('workshop', $workshop->id);
        $workshopgenerator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');

        // Set restriction to Only Files (1).
        $DB->insert_record('plagiarism_inspera_config', (object)[
            'cm' => $cm->id,
            'name' => 'originality_restrictcontent',
            'value' => PLAGIARISM_INSPERA_RESTRICTCONTENTFILES,
        ]);

        $user = $this->getDataGenerator()->create_user();
        $subid = $workshopgenerator->create_submission($workshop->id, $user->id, [
            'content' => 'This text should be ignored',
            'contentformat' => FORMAT_HTML,
        ]);

        // Add an attachment.
        $fs = get_file_storage();
        $fs->create_file_from_string(
            [
                'contextid' => \context_module::instance($cm->id)->id,
                'component' => 'mod_workshop',
                'filearea'  => 'submission_attachment',
                'itemid'    => $subid,
                'filepath'  => '/',
                'filename'  => 'scan_me.pdf',
            ],
            'PDF content'
        );

        // Mock: We expect exactly 1 call (for the file), and 0 calls for the text.
        $mockqueueservice = $this->createMock(queue_service::class);
        $mockqueueservice->expects($this->once())
            ->method('queue_file')
            ->with(
                $this->equalTo($cm->id),
                $this->equalTo($user->id),
                $this->callback(function ($file) {
                    return $file instanceof \stored_file && $file->get_filename() === 'scan_me.pdf';
                }),
                $this->anything(), // For the related user ID (null in this case).
                $this->equalTo($subid) // Use the real submission ID.
            );

        $service = new workshop_service($DB, $mockqueueservice);
        $service->process_phase_switch($workshop->id, $cm->id);
    }

    /**
     * Test: Verify that when restricted to 'Only Online Text', files are ignored.
     */
    public function test_process_phase_switch_respects_text_only_restriction(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('workshop', $workshop->id);
        $workshopgenerator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');

        // Set restriction to Only Text (2).
        $DB->insert_record('plagiarism_inspera_config', (object)[
            'cm' => $cm->id,
            'name' => 'originality_restrictcontent',
            'value' => PLAGIARISM_INSPERA_RESTRICTCONTENTTEXT,
        ]);

        $user = $this->getDataGenerator()->create_user();
        $subid = $workshopgenerator->create_submission($workshop->id, $user->id, [
            'content' => 'This text should be scanned',
            'contentformat' => FORMAT_HTML,
        ]);

        // Add an attachment that should be ignored.
        $fs = get_file_storage();
        $fs->create_file_from_string(
            [
                'contextid' => \context_module::instance($cm->id)->id,
                'component' => 'mod_workshop',
                'filearea'  => 'submission_attachment',
                'itemid'    => $subid,
                'filepath'  => '/',
                'filename'  => 'ignore_me.pdf',
            ],
            'PDF content'
        );

        // Mock: We expect exactly 1 call (for the text/temp-file), NOT the pdf.
        $mockqueueservice = $this->createMock(queue_service::class);
        $mockqueueservice->expects($this->once())
            ->method('queue_file')
            ->with(
                $this->equalTo($cm->id),
                $this->equalTo($user->id),
                $this->callback(function ($file) {
                    if (!is_object($file) || !isset($file->filepath)) {
                        return false;
                    }
                    return strpos($file->filepath, 'plagiarism_inspera') !== false;
                }),
                $this->anything(), // For the related user ID (null in this case).
                $this->equalTo($subid) // Use the real submission ID.
            );

        $service = new workshop_service($DB, $mockqueueservice);
        $service->process_phase_switch($workshop->id, $cm->id);
    }
}
