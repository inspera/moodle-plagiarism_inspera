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
 * PHPUnit tests for the queue_service class.
 *
 * @package     plagiarism_inspera
 * @category    test
 * @copyright   2025 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\queue_service;

/**
 * Unit tests for queue_service.
 *
 * @package     plagiarism_inspera
 * @copyright   2025 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class queue_service_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test successful file queuing.
     *
     * @covers \plagiarism_inspera\services\queue_service::queue_file
     */
    public function test_queue_file_success(): void {
        global $DB;

        // 1. Setup data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // Enable plugin for this CM.
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm' => $cm->id,
            'name' => 'use_originality',
            'value' => '1',
        ]);

        // Create a fake stored file.
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_assign',
            'filearea' => 'submission_files',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'submission.pdf',
            'userid' => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'Fake PDF content');

        // 2. Execute.
        $service = new queue_service($DB);
        $service->queue_file($cm->id, $user->id, $file);

        // 3. Assert.
        $record = $DB->get_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user->id,
            'storedfileid' => $file->get_id(),
        ]);

        $this->assertNotEmpty($record);
        $this->assertEquals('report_requested', $record->status);
    }

    /**
     * Test that no record is created when the plugin is disabled.
     *
     * @covers \plagiarism_inspera\services\queue_service::queue_file
     */
    public function test_queue_file_disabled(): void {
        global $DB;

        // 1. Setup data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // Explicitly set the plugin to disabled in the config.
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm' => $cm->id,
            'name' => 'use_originality',
            'value' => '0',
        ]);

        // Create a fake stored file.
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_assign',
            'filearea' => 'submission_files',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'submission.pdf',
            'userid' => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'Fake PDF content');

        // 2. Execute.
        $service = new queue_service($DB);
        $service->queue_file($cm->id, $user->id, $file);

        // 3. Assert.
        $count = $DB->count_records('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user->id,
        ]);

        $this->assertEquals(0, $count);
    }

    /**
     * Test successful online text (temp file) queuing.
     *
     * @covers \plagiarism_inspera\services\queue_service::queue_file
     */
    public function test_queue_online_text_success(): void {
        global $DB;

        // 1. Setup data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // Enable plugin for this CM.
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm' => $cm->id,
            'name' => 'use_originality',
            'value' => '1',
        ]);

        // Create a fake object with a filepath property (simulating our temp HTML file).
        $filepath = '/tmp/fake_onlinetext.html';
        $fakefile = (object) [
            'filepath' => $filepath,
        ];

        // 2. Execute.
        $service = new queue_service($DB);
        $service->queue_file($cm->id, $user->id, $fakefile);

        // 3. Assert.
        $record = $DB->get_record('plagiarism_inspera_subs', [
            'cm' => $cm->id,
            'userid' => $user->id,
            'identifier' => $filepath,
        ]);

        $this->assertNotEmpty($record);
        $this->assertEquals('report_requested', $record->status);
        $this->assertNull($record->storedfileid);
    }

    /**
     * Test that existing fatal_error records are not automatically reset to report_requested for files.
     *
     * @covers \plagiarism_inspera\services\queue_service::queue_file
     */
    public function test_queue_file_does_not_retry_fatal_error(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm' => $cm->id,
            'name' => 'use_originality',
            'value' => '1',
        ]);

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_assign',
            'filearea' => 'submission_files',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'submission.pdf',
            'userid' => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'Fake PDF content');

        $existing = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 1,
            'storedfileid' => $file->get_id(),
            'identifier' => null,
            'status' => 'fatal_error',
            'description' => 'terminal test state',
            'externalid' => 'terminal-doc',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $existingid = $DB->insert_record('plagiarism_inspera_subs', $existing);

        $service = new queue_service($DB);
        $service->queue_file($cm->id, $user->id, $file, null, 1);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $existingid], '*', MUST_EXIST);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertEquals('terminal test state', $updated->description);
        $this->assertEquals('terminal-doc', $updated->externalid);
    }
}
