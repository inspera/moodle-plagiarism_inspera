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
        $filepath = $this->create_online_text_temp_file('<p>online text</p>');

        $oldexternalid = 'stale-id';
        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => $oldexternalid,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
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
        $this->assertFalse(file_exists($filepath), 'Temporary online-text file should be deleted on API failure.');
    }

    /**
     * Test plagiarism_inspera_send_file aborts before API call and deletes queue record when the source file is gone.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_deletes_record_when_stored_file_missing_preflight(): void {
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
            'filename' => 'deleted-before-send.pdf',
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

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/Skipping Inspera submission.*Deleting queue record/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $this->assertFalse($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));
    }

    /**
     * Test plagiarism_inspera_send_file preserves queued row as fatal_error when source file is missing but externalid exists.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_marks_fatal_error_when_missing_stored_file_and_externalid_exists(): void {
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
            'itemid' => 99,
            'filepath' => '/',
            'filename' => 'missing-after-externalid.pdf',
            'userid' => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'test');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => 'external-doc-999',
            'timecreated' => time(),
            'storedfileid' => $file->get_id(),
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);
        $file->delete();

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/Preserving queue record as fatal_error because externalid/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $this->assertTrue($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));
        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('external-doc-999', $updated->externalid);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertStringContainsString('Source file unavailable', $updated->description);
    }

    /**
     * Test plagiarism_inspera_send_file rejects identifier paths outside the safe temp base.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_rejects_identifier_outside_safe_base(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => '/tmp/outside-inspera-temp.html',
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/SECURITY FATAL: Unauthorized directory or traversal attempt/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertEquals('Security violation: Invalid file path detected.', $updated->description);
    }

    /**
     * Test plagiarism_inspera_send_file marks fatal_error when activity metadata cannot be resolved.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_marks_fatal_error_when_activity_metadata_missing(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $filepath = $this->create_online_text_temp_file('<p>metadata ghost test</p>');

        $record = (object) [
            'cm' => $cm->id + 999999, // Deliberately invalid CM id to force metadata resolution failure.
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/GHOST DETECTED: Failed to resolve activity\/course metadata/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertSame('Ghost submission: activity or course metadata could not be resolved.', $updated->description);
        $this->assertFalse(file_exists($filepath), 'Ghost temp file should be deleted on metadata resolution failure.');
    }

    /**
     * Test plagiarism_inspera_send_file marks fatal_error when parent submission is deleted even if temp file exists.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_marks_fatal_error_for_deleted_parent_submission(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $filepath = $this->create_online_text_temp_file('<p>stale temp file</p>');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 999999, // Deliberately non-existent assign_submission row.
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/GHOST DETECTED: Parent record/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertStringContainsString('Ghost submission', $updated->description);
        $this->assertFalse(file_exists($filepath), 'Ghost temp file should be deleted.');
    }

    /**
     * Test plagiarism_inspera_send_file does not unlink unsafe identifier paths during ghost cleanup.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_keeps_unsafe_identifier_when_parent_deleted(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $outsidetempdir = make_temp_directory('inspera_outside_fixture');
        $outsidefilepath = $outsidetempdir . '/outside_' . uniqid('', true) . '.html';
        file_put_contents($outsidefilepath, '<p>outside path</p>');
        $this->assertTrue(file_exists($outsidefilepath));

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 999999, // Deliberately non-existent assign_submission row.
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $outsidefilepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/GHOST DETECTED: Parent record.*Security block: Skipped orphaned temporary file cleanup/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertStringContainsString('Ghost submission', $updated->description);
        $this->assertTrue(file_exists($outsidefilepath), 'Unsafe identifier path must not be deleted.');

        @unlink($outsidefilepath);
    }

    /**
     * Test plagiarism_inspera_send_file marks fatal_error for quiz temp identifiers when source data is gone.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_marks_fatal_error_for_missing_quiz_source(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/quiz_' . $cm->id . '_' . $user->id . '_999999.html';
        file_put_contents($filepath, '<p>stale quiz temp file</p>');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/GHOST DETECTED: Online-text source no longer exists/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertNotFalse($updated);
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertStringContainsString('Ghost submission', $updated->description);
        $this->assertFalse(file_exists($filepath), 'Stale quiz temp file should be deleted.');
    }

    /**
     * Test plagiarism_inspera_send_file deletes an empty online-text temp file and queue record.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_deletes_empty_online_text_temp_file(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $filepath = $this->create_online_text_temp_file('');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('create_submission');

        $this->expectOutputRegex('/no content available to upload/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $this->assertFalse($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));
        $this->assertFalse(file_exists($filepath), 'Empty online-text temp file should be deleted.');
    }

    /**
     * Test plagiarism_inspera_send_file deletes online-text temp file when upload returns false.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_deletes_temp_file_on_upload_false(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $filepath = $this->create_online_text_temp_file('<p>upload-failure cleanup test</p>');

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission', 'upload_to_presigned_url'])
            ->getMock();
        $clientmock->expects($this->once())
            ->method('create_submission')
            ->willReturn((object) [
                'documentId' => 'doc-upload-fail-1',
                'presignedS3Url' => 'https://s3.example.com/upload',
            ]);
        $clientmock->expects($this->once())
            ->method('upload_to_presigned_url')
            ->willReturn(false);

        $this->expectOutputRegex('/Upload to presigned URL returned failure|Failed to upload file content/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('error', $updatedrecord->status);
        $this->assertEquals('Upload to presigned URL returned failure', $updatedrecord->description);
        $this->assertFalse(file_exists($filepath), 'Online-text temp file should be deleted on upload false.');
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
     * Test poll marks fatal_error when API returns status 2 after grace period expires.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_sets_fatal_error_after_grace_period(): void {
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
        $this->assertEquals('fatal_error', $updatedrecord->status);
        $this->assertStringContainsString('Still failing', $updatedrecord->description);
    }

    /**
     * Test status code list includes fatal_error.
     *
     * @covers ::plagiarism_inspera_statuscodes
     */
    public function test_plagiarism_inspera_statuscodes_includes_fatal_error(): void {
        $statuses = \plagiarism_inspera_statuscodes();
        $this->assertArrayHasKey('fatal_error', $statuses);
        $this->assertNotEmpty($statuses['fatal_error']);
    }

    /**
     * Test errors-only helper status list and keyed map stay aligned.
     *
     * @covers ::plagiarism_inspera_errors_only_statuses
     * @covers ::plagiarism_inspera_errors_only_status_map
     */
    public function test_plagiarism_inspera_errors_only_status_helpers_are_consistent(): void {
        $statuses = \plagiarism_inspera_errors_only_statuses();
        $statusmap = \plagiarism_inspera_errors_only_status_map();

        $this->assertSame(['error', 'external_error', 'fatal_error'], $statuses);
        $this->assertSame(array_fill_keys($statuses, true), $statusmap);
        $this->assertArrayHasKey('fatal_error', $statusmap);
    }

    /**
     * Test helper extracts status values from supported filter-rule payload shapes.
     *
     * @covers ::plagiarism_inspera_extract_status_rule_value
     */
    public function test_plagiarism_inspera_extract_status_rule_value_supported_shapes(): void {
        $this->assertSame('error', \plagiarism_inspera_extract_status_rule_value(['value' => 'error']));
        $this->assertSame('external_error', \plagiarism_inspera_extract_status_rule_value([0 => 2, 1 => 'external_error']));
        $this->assertSame('fatal_error', \plagiarism_inspera_extract_status_rule_value((object)['value' => 'fatal_error']));
        $this->assertSame('error', \plagiarism_inspera_extract_status_rule_value('error'));
    }

    /**
     * Test helper returns null for unsupported filter-rule payloads.
     *
     * @covers ::plagiarism_inspera_extract_status_rule_value
     */
    public function test_plagiarism_inspera_extract_status_rule_value_unsupported_shapes(): void {
        $this->assertNull(\plagiarism_inspera_extract_status_rule_value([]));
        $this->assertNull(\plagiarism_inspera_extract_status_rule_value((object)[]));
        $this->assertNull(\plagiarism_inspera_extract_status_rule_value(['foo' => 'bar']));
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
     * Test cleanup marks record as fatal_error when Moodle file is gone after reaching Inspera.
     *
     * @covers ::plagiarism_inspera_cleanup_orphaned_records
     */
    public function test_plagiarism_inspera_cleanup_marks_sent_missing_stored_file_as_fatal_error(): void {
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
        // Now asserts fatal_error instead of error.
        $this->assertEquals('fatal_error', $updated->status);
        $this->assertStringContainsString('Source file deleted', $updated->description);
    }

    /**
     * Test cleanup removes stale online-text file and DB record marked as fatal_error after 7 days.
     *
     * @covers ::plagiarism_inspera_cleanup_orphaned_records
     */
    public function test_plagiarism_inspera_cleanup_deletes_stale_fatal_error_temp_files(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/stale_fatal_error_' . uniqid('', true) . '.html';
        file_put_contents($filepath, '<p>stale fatal error text</p>');
        $this->assertTrue(file_exists($filepath));

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => 'fatal_error',
            'externalid' => null,
            'identifier' => $filepath,
            'timecreated' => time() - (8 * 86400),
            'storedfileid' => null,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        $cleaned = \plagiarism_inspera_cleanup_orphaned_records();

        $this->assertEquals(1, $cleaned);
        $this->assertFalse(file_exists($filepath), 'Stale fatal_error temp file should be swept.');
        $this->assertFalse($DB->record_exists('plagiarism_inspera_subs', ['id' => $record->id]));
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

    /**
     * Test plagiarism_inspera_send_file dynamically fetches educators for Quizzes (Non-Editing Teachers included)
     * and strictly filters out suspended users.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_fetches_dynamic_active_educators(): void {
        global $DB;

        $this->resetAfterTest();

        // 1. Setup data (Course, Users, and a Quiz module).
        $course = $this->getDataGenerator()->create_course();

        // Explicitly grab role IDs to ensure Moodle enrols them correctly in tests.
        $editingteacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);

        // Create an active editing teacher.
        $editingteacher = $this->getDataGenerator()->create_user(['firstname' => 'Active', 'lastname' => 'Editor']);
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, $editingteacherrole);

        // Create an active non-editing teacher.
        $noneditingteacher = $this->getDataGenerator()->create_user(['firstname' => 'Active', 'lastname' => 'Grader']);
        $this->getDataGenerator()->enrol_user($noneditingteacher->id, $course->id, $teacherrole);

        // Create a SUSPENDED editing teacher.
        $suspendedteacher = $this->getDataGenerator()->create_user(['firstname' => 'Suspended', 'lastname' => 'Teacher']);
        $this->getDataGenerator()->enrol_user(
            $suspendedteacher->id,
            $course->id,
            $editingteacherrole,
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );

        // Create the student submitting the file.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole);

        // Create a Quiz activity.
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $filepath = $this->create_online_text_temp_file('<p>educator payload test</p>');

        // 2. Create the submission record for the test.
        $record = (object) [
            'cm' => $cm->id,
            'userid' => $student->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        // 3. Mock the API client.
        $capturededucators = []; // Variable to extract the array outside the mock.
        $capturedmetadata = null;

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('create_submission')
            ->willReturnCallback(function (
                $metadata,
                $settings,
                $educators,
                $students
            ) use (
                &$capturededucators,
                &$capturedmetadata
            ) {
                // Save the educators array by reference, then safely abort.
                $capturedmetadata = $metadata;
                $capturededucators = $educators;
                throw new \Exception('Payload inspected successfully.');
            });

        // 4. Execute the function.
        $this->expectOutputRegex('/Payload inspected successfully/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        // 5. RUN ASSERTIONS OUTSIDE THE CATCH BLOCK!
        $this->assertIsArray($capturededucators);
        $this->assertInstanceOf(\stdClass::class, $capturedmetadata);

        $educatorids = array_map(function ($e) {
            return (int)$e['id'];
        }, $capturededucators);

        $this->assertSame((string)$cm->id, $capturedmetadata->assignmentid);
        $this->assertSame((string)$quiz->name, $capturedmetadata->assignmentname);
        $this->assertSame((string)$course->id, $capturedmetadata->subjectid);
        $this->assertSame((string)$course->shortname, $capturedmetadata->subjectname);

        // Assert the Active Editing Teacher is in the payload.
        $this->assertContains((int)$editingteacher->id, $educatorids, 'Active Editing Teacher must be included.');

        // Assert the Active Non-Editing Teacher is in the payload (Dynamic capability success).
        $this->assertContains(
            (int)$noneditingteacher->id,
            $educatorids,
            'Active Non-Editing Teacher must be included for quizzes.'
        );

        // Assert the Suspended Teacher is NOT in the payload (onlyactive = true success).
        $this->assertNotContains(
            (int)$suspendedteacher->id,
            $educatorids,
            'Suspended teachers must be excluded from the payload.'
        );

        @unlink($filepath);
    }

    /**
     * Test plagiarism_inspera_send_file sends an empty educators list and logs a notice
     * when an unmapped module (e.g., forum) is processed.
     *
     * @covers ::plagiarism_inspera_send_file
     */
    public function test_plagiarism_inspera_send_file_sends_empty_educators_for_unmapped_modules(): void {
        global $DB;

        $this->resetAfterTest();

        // 1. Setup data (Course, Users, and a TRULY UNMAPPED module like 'page').
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Create an editing teacher.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // FIX: Create a 'page' activity instead of a 'forum' activity.
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $filepath = $this->create_online_text_temp_file('<p>unmapped module test</p>');

        // 2. Create the submission record.
        $record = (object) [
            'cm' => $cm->id,
            'userid' => $student->id,
            'submissionid' => 0,
            'status' => 'report_requested',
            'externalid' => null,
            'timecreated' => time(),
            'storedfileid' => null,
            'identifier' => $filepath,
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        // 3. Mock the API client.
        $capturededucators = null;

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['create_submission'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('create_submission')
            ->willReturnCallback(function ($metadata, $settings, $educators, $students) use (&$capturededucators) {
                $capturededucators = $educators;
                throw new \Exception('Payload inspected successfully.');
            });

        // 4. Execute the function.
        $this->expectOutputRegex('/Notice: No grading capability mapped for module \'page\'.*Payload inspected successfully/s');
        \plagiarism_inspera_send_file($record, $clientmock);

        // 5. Assert the educators array is strictly empty.
        $this->assertIsArray($capturededucators);
        $this->assertEmpty($capturededucators, 'Educators array must be empty for unmapped modules.');

        @unlink($filepath);
    }

    /**
     * Test poll handles successful processing (status 1) and maps originality data.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_handles_success(): void {
        global $DB;

        $this->resetAfterTest();

        $record = $this->create_pending_submission(time() - 3600);

        $statusresponse = (object) [
            'status' => 1,
            'similarity' => 12,
            'originality_percentage' => 88,
            'translationSimilarity' => 5,
            'ai_index' => 2,
        ];

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willReturn($statusresponse);

        // Suppress mtrace output if it exists in status 1, though standard code might not output here.
        ob_start();
        \plagiarism_inspera_poll_file_status($record, $clientmock);
        ob_end_clean();

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('finished', $updatedrecord->status);
        $this->assertEquals(12, $updatedrecord->similarity);
        $this->assertEquals(88, $updatedrecord->originality_score);
        $this->assertEquals(5, $updatedrecord->translation_similarity);
        $this->assertEquals(2, $updatedrecord->ai_index);
    }

    /**
     * Test poll keeps record pending when API returns 0 or -1 within the 48h limit.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_keeps_pending_for_queued_within_limit(): void {
        global $DB;

        $this->resetAfterTest();

        // Created 12 hours ago (Within 48h limit).
        $record = $this->create_pending_submission(time() - (12 * 3600));

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willReturn((object) ['status' => 0]);

        ob_start();
        \plagiarism_inspera_poll_file_status($record, $clientmock);
        ob_end_clean();

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('pending', $updatedrecord->status);
    }

    /**
     * Test poll sets error when API returns 0 or -1 AFTER the 48h limit.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_sets_error_for_queued_after_limit(): void {
        global $DB;

        $this->resetAfterTest();

        // Fix: Move the clock back 50 hours (180000 seconds) to exceed the 48-hour circuit breaker.
        $record = $this->create_pending_submission(time() - 180000);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willReturn((object) ['status' => -1]);

        // Regex should match the 48h circuit-breaker message.
        $this->expectOutputRegex('/stuck in state -1 \(processing\) for over 48h\. Marked as error/s');
        \plagiarism_inspera_poll_file_status($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('error', $updatedrecord->status);
        $this->assertStringContainsString('API timeout: Stuck in processing state for over 48 hours', $updatedrecord->description);
    }

    /**
     * Test poll sets error when an exception is thrown AFTER the 48h limit.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_sets_error_on_exception_after_limit(): void {
        global $DB;

        $this->resetAfterTest();

        // Created 50 hours ago (Exceeds 2 * DAYSECS).
        $record = $this->create_pending_submission(time() - 180000);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willThrowException(new \Exception('Persistent network failure'));

        $this->expectOutputRegex('/Aborting after 48 hours\. Marked as error/s');
        \plagiarism_inspera_poll_file_status($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('error', $updatedrecord->status);
        $this->assertStringContainsString('Polling failed for 48 hours', $updatedrecord->description);
        $this->assertStringContainsString('Persistent network failure', $updatedrecord->description);
    }

    /**
     * Test poll handles unknown status codes by mapping to external_error.
     *
     * @covers ::plagiarism_inspera_poll_file_status
     */
    public function test_plagiarism_inspera_poll_file_status_sets_external_error_for_unknown_status(): void {
        global $DB;

        $this->resetAfterTest();

        $record = $this->create_pending_submission(time() - 3600);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willReturn((object) ['status' => 99, 'message' => 'New unmapped API state']);

        $this->expectOutputRegex('/returned error status/s');
        \plagiarism_inspera_poll_file_status($record, $clientmock);

        $updatedrecord = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id]);
        $this->assertEquals('external_error', $updatedrecord->status);
        $this->assertEquals('New unmapped API state', $updatedrecord->description);
    }

    /**
     * Creates an online-text temporary file under plagiarism_inspera temp directory.
     *
     * @param string $content HTML content to write.
     * @return string
     */
    private function create_online_text_temp_file(string $content): string {
        $tempdir = make_temp_directory('plagiarism_inspera');
        $filepath = $tempdir . '/test_online_text_' . uniqid('', true) . '.html';
        file_put_contents($filepath, $content);
        return $filepath;
    }
}
