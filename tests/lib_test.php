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
 * Library tests for the Plagiarism Inspera plugin.
 *
 * @package     plagiarism_inspera
 * @category   test
 * @copyright   2026 Inspera
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use plagiarism_inspera\apiclient\api_client;
use stdClass;

global $CFG;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * Unit tests for lib.php.
 *
 * @package     plagiarism_inspera
 * @copyright   2026 Inspera
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends advanced_testcase {
    /**
     * Test plagiarism_inspera_send_file handles existing externalid when status is report_requested.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_resets_externalid_on_report_requested(): void {
        global $DB;

        $this->resetAfterTest();

        // 1. Setup data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // 1.a. Create a stored file.
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
        $file = $fs->create_file_from_string($filerecord, 'Test file content');

        // 1.b. Create plugin config for the module.
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm' => $cm->id,
            'name' => 'use_originality',
            'value' => '1',
        ]);

        $oldexternalid = 'old-external-id-123';
        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => $oldexternalid,
            'timecreated' => time(),
            'storedfileid' => $file->get_id(),
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        // 2. Mock API Client.
        $newexternalid = 'new-external-id-456';
        $mocksubmission = (object) [
            'documentId' => $newexternalid,
            'presignedS3Url' => 'https://s3.example.com/upload',
        ];

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission', 'upload_to_presigned_url'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('create_submission')
            ->willReturn($mocksubmission);

        $clientmock->expects($this->once())
            ->method('upload_to_presigned_url')
            ->willReturn(true);

        // 3. Execution.
        $beforecall = time();

        // Capture mtrace output.
        $this->expectOutputRegex('/Created submission.*Uploaded file content/s');

        // Call the global function from our namespaced test.
        \plagiarism_inspera_send_file($record, $clientmock);
        $aftercall = time();

        // 4. Verification.
        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals($newexternalid, $updatedrecord->externalid);
        $this->assertEquals('pending', $updatedrecord->status);
        $this->assertNotEquals($oldexternalid, $updatedrecord->externalid);
        $this->assertNotNull($updatedrecord->timemodified);
        $this->assertGreaterThanOrEqual($beforecall, (int)$updatedrecord->timemodified);
        $this->assertLessThanOrEqual($aftercall, (int)$updatedrecord->timemodified);
    }

    /**
     * Test plagiarism_inspera_send_file clears externalid even if API creation fails.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_clears_externalid_on_api_failure(): void {
        global $DB;

        $this->resetAfterTest();

        // 1. Setup data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $oldexternalid = 'stale-id';
        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => $oldexternalid,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => 'online-text-fixture',
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        // 2. Mock API Client to FAIL.
        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('create_submission')
            ->willThrowException(new \Exception('API Down'));

        // 3. Execution.
        $this->expectOutputRegex('/Error creating submission/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        // 4. Verification.
        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNull($updatedrecord->externalid); // CRITICAL: ID should be cleared.
        $this->assertEquals('error', $updatedrecord->status);
        $this->assertStringContainsString('API Down', $updatedrecord->description);
    }

    /**
     * Test poll keeps status pending when API returns status 2 within the grace period.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_keeps_pending_within_grace_period(): void {
        global $DB;

        $this->resetAfterTest();

        $record = $this->create_pending_submission(time() - 3600);
        $record->description = 'keep-existing-description';
        $DB->update_record('plagiarism_inspera_subs', $record);

        $statusresponse = (object) [
            'status' => 2,
            'message' => 'Temporarily unavailable',
        ];

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willReturn($statusresponse);

        $this->expectOutputRegex('/keeping pending during grace period/s');
        \plagiarism_inspera_poll_file_status($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('pending', $updatedrecord->status);
        $this->assertEquals('keep-existing-description', $updatedrecord->description);
    }

    /**
     * Test poll marks external_error when API returns status 2 after grace period expires.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_sets_error_after_grace_period(): void {
        global $DB;

        $this->resetAfterTest();

        $record = $this->create_pending_submission(time() - 90000);

        $statusresponse = (object) [
            'status' => 2,
            'message' => 'Still failing',
        ];

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willReturn($statusresponse);

        $this->expectOutputRegex('/status 2 after grace period/s');
        \plagiarism_inspera_poll_file_status($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('external_error', $updatedrecord->status);
        $this->assertStringContainsString('Still failing', $updatedrecord->description);
    }

    /**
     * Test poll catches network exceptions and keeps record pending for retry.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_exception_keeps_pending(): void {
        global $DB;

        $this->resetAfterTest();

        $record = $this->create_pending_submission(time() - 1000);
        $record->description = 'unchanged-description';
        $DB->update_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willThrowException(new \Exception('Network timeout'));

        $this->expectOutputRegex('/poll error.*Network timeout/s');
        \plagiarism_inspera_poll_file_status($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('pending', $updatedrecord->status);
        $this->assertEquals('unchanged-description', $updatedrecord->description);
    }

    /**
     * Test cleanup deletes DB record when Moodle file is gone before reaching Inspera.
     *
     * @covers ::plagiarism_inspera_cleanup_orphaned_records
     */
    public function test_plagiarism_inspera_cleanup_deletes_unsent_missing_stored_file_record(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_assign',
            'filearea' => 'submission_files',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'missing-before-inspera.pdf',
            'userid' => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'test');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => $file->get_id(),
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $file->delete();
        $this->assertFalse((bool)$fs->get_file_by_id($record->storedfileid));

        $cleaned = \plagiarism_inspera_cleanup_orphaned_records();

        $this->assertEquals(1, $cleaned);
        $this->assertFalse($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));
    }

    /**
     * Test cleanup marks record as error when Moodle file is gone after reaching Inspera.
     *
     * @covers ::plagiarism_inspera_cleanup_orphaned_records
     */
    public function test_plagiarism_inspera_cleanup_marks_sent_missing_stored_file_as_error(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_assign',
            'filearea' => 'submission_files',
            'itemid' => 2,
            'filepath' => '/',
            'filename' => 'missing-after-inspera.pdf',
            'userid' => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'test');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'pending',
            'externalid' => 'external-doc-123',
            'timecreated' => time(),
            'storedfileid' => $file->get_id(),
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $file->delete();
        $this->assertFalse((bool)$fs->get_file_by_id($record->storedfileid));

        $cleaned = \plagiarism_inspera_cleanup_orphaned_records();

        $this->assertEquals(0, $cleaned);
        $this->assertTrue($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('error', $updated->status);
        $this->assertStringContainsString('Source file deleted', $updated->description);
    }

    /**
     * Test cleanup removes stale online-text file and DB record after 7 days.
     *
     * @covers ::plagiarism_inspera_cleanup_orphaned_records
     */
    public function test_plagiarism_inspera_cleanup_deletes_stale_online_text_file_and_record(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/stale_online_text_' . uniqid('', true) . '.html';
        file_put_contents($filepath, '<p>stale online text</p>');
        $this->assertTrue(file_exists($filepath));

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'identifier' => $filepath,
            'timecreated' => time() - (8 * 86400),
            'storedfileid' => null,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $cleaned = \plagiarism_inspera_cleanup_orphaned_records();

        $this->assertEquals(1, $cleaned);
        $this->assertFalse(file_exists($filepath));
        $this->assertFalse($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));
    }

    /**
     * Test cleanup keeps recent online-text file and DB record within 7 days.
     *
     * @covers ::plagiarism_inspera_cleanup_orphaned_records
     */
    public function test_plagiarism_inspera_cleanup_keeps_recent_online_text_file_and_record(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/recent_online_text_' . uniqid('', true) . '.html';
        file_put_contents($filepath, '<p>recent online text</p>');
        $this->assertTrue(file_exists($filepath));

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'identifier' => $filepath,
            'timecreated' => time() - 3600,
            'storedfileid' => null,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $cleaned = \plagiarism_inspera_cleanup_orphaned_records();

        $this->assertEquals(0, $cleaned);
        $this->assertTrue(file_exists($filepath));
        $this->assertTrue($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));

        @unlink($filepath);
    }

    /**
     * Creates a minimal pending plagiarism submission record for poll tests.
     *
     * @param int $timemodified Unix timestamp used for grace-period checks.
     * @return stdClass
     */
    private function create_pending_submission(int $timemodified): stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'pending',
            'externalid' => 'external-id-' . random_int(1000, 9999),
            'description' => null,
            'timecreated' => time(),
            'timemodified' => $timemodified,
            'storedfileid' => null,
            'identifier' => 'online-text-pending-fixture',
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        return $record;
    }
}
