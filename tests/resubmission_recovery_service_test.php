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
 * PHPUnit tests for resubmission_recovery_service.
 *
 * @package     plagiarism_inspera
 * @category    test
 * @copyright   2026 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\apiclient\api_client;
use plagiarism_inspera\services\resubmission_recovery_service;

/**
 * Unit tests for manual pre-flight resubmission recovery.
 */
final class resubmission_recovery_service_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test resubmit_single recovers finished document without wiping externalid.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_single
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::mark_as_recovered
     */
    public function test_resubmit_single_recovers_finished_document_without_wiping_externalid(): void {
        global $DB;

        $oldtimecreated = time() - 1000;
        // Record starts with originality = 'high'.
        $record = $this->create_submission_record('error', 'doc-123', $oldtimecreated);

        $statusresponse = (object) [
            'status' => 1,
            'similarity' => 32,
            'originality_percentage' => 68,
            'originality' => 'low', // Added the text field to the mock.
            'translationSimilarity' => 7,
            'Ai_index' => '2',
            'characterReplacement' => 3,
            'hiddenText' => 4,
            'imageAsText' => 5,
        ];

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();
        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->with('doc-123')
            ->willReturn($statusresponse);

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertEquals('recovered', $outcome);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertEquals('finished', $updated->status);
        $this->assertEquals('doc-123', $updated->externalid);
        $this->assertEquals(32, (float)$updated->similarity);
        $this->assertEquals(68, (float)$updated->originality_score);
        $this->assertEquals('low', $updated->originality); // Assert the text field synced correctly.
        $this->assertEquals(7, (float)$updated->translation_similarity);
        $this->assertEquals('2', (string)$updated->ai_index);
        $this->assertEquals(3, (int)$updated->character_replacement);
        $this->assertEquals(4, (int)$updated->hidden_text);
        $this->assertEquals(5, (int)$updated->image_as_text);
        $this->assertEquals($oldtimecreated, (int)$updated->timecreated);
        $this->assertStringContainsString('Recovered via manual pre-flight', (string)$updated->description);
    }

    /**
     * Test resubmit_single queues fresh start when status is queued.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_single
     */
    public function test_resubmit_single_queues_fresh_start_when_status_is_queued(): void {
        global $DB;

        $oldtimecreated = time() - 2000;
        $record = $this->create_submission_record('error', 'doc-queued', $oldtimecreated);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();
        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->with('doc-queued')
            ->willReturn((object) ['status' => 0]);

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertEquals('queued', $outcome);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertEquals('report_requested', $updated->status);
        $this->assertNull($updated->externalid);
        $this->assertNull($updated->similarity);
        $this->assertNull($updated->originality_score);
        $this->assertNull($updated->originality);
        $this->assertNull($updated->translation_similarity);
        $this->assertNull($updated->ai_index);
        $this->assertNull($updated->character_replacement);
        $this->assertNull($updated->hidden_text);
        $this->assertNull($updated->image_as_text);
        $this->assertGreaterThan($oldtimecreated, (int)$updated->timecreated);
        $this->assertStringContainsString('Queued for fresh submission', (string)$updated->description);
    }

    /**
     * Test resubmit_single aborts (does not queue) when API throws exception to prevent data loss.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_single
     */
    public function test_resubmit_single_aborts_when_api_throws_exception(): void {
        global $DB;

        $record = $this->create_submission_record('error', 'doc-throws');

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();
        $clientmock->expects($this->once())
            ->method('check_document_status')
            ->willThrowException(new \moodle_exception('apierror', 'plagiarism_inspera'));

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertDebuggingCalled();

        // Assert that we get an API error and the record is left untouched.
        $this->assertEquals('api_error', $outcome);

        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertEquals('error', $updated->status); // Status unchanged.
        $this->assertEquals('doc-throws', $updated->externalid); // External ID preserved!
    }

    /**
     * Test resubmit_single queues when there is no externalid (no API poll should be attempted).
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_single
     */
    public function test_resubmit_single_queues_when_no_externalid_without_api_call(): void {
        global $DB;

        $record = $this->create_submission_record('error', null);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('check_document_status');

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertEquals('queued', $outcome);
    }

    /**
     * Test resubmit_single rejects unsupported status like external_error.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_single
     */
    public function test_resubmit_single_rejects_unsupported_status(): void {
        global $DB;

        $record = $this->create_submission_record('external_error', 'doc-nope');

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();
        $clientmock->expects($this->never())
            ->method('check_document_status');

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertEquals('not_eligible', $outcome);
        $updated = $DB->get_record('plagiarism_inspera_subs', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertEquals('external_error', $updated->status);
        $this->assertEquals('doc-nope', $updated->externalid);
    }

    /**
     * Test resubmit_single accepts 'report_requested' if stuck for more than 10 minutes.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::is_eligible
     */
    public function test_resubmit_single_accepts_stale_report_requested(): void {
        global $DB;

        $record = $this->create_submission_record('report_requested', null);

        // Force timemodified to 11 minutes ago (660 seconds).
        $DB->set_field('plagiarism_inspera_subs', 'timemodified', time() - 660, ['id' => $record->id]);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        // No externalid, so no API call should happen. It should drop straight to queue.
        $clientmock->expects($this->never())->method('check_document_status');

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertEquals('queued', $outcome);
    }

    /**
     * Test resubmit_single rejects 'report_requested' if it has been less than 10 minutes.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::is_eligible
     */
    public function test_resubmit_single_rejects_recent_report_requested(): void {
        global $DB;

        $record = $this->create_submission_record('report_requested', null);

        // Force timemodified to 5 minutes ago (300 seconds).
        $DB->set_field('plagiarism_inspera_subs', 'timemodified', time() - 300, ['id' => $record->id]);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();
        $clientmock->expects($this->never())->method('check_document_status');

        $service = new resubmission_recovery_service($DB);
        $outcome = $service->resubmit_single((int)$record->id, $clientmock);

        $this->assertEquals('not_eligible', $outcome);
    }

    /**
     * Test resubmit_bulk updates status and externalid for selected records,
     * and correctly tallies recovered, queued, and skipped (including API errors) counts.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_bulk
     */
    public function test_resubmit_bulk_reports_recovered_queued_and_skipped_counts(): void {
        global $DB;

        $recoverable = $this->create_submission_record('error', 'doc-bulk-1');
        $queueable = $this->create_submission_record('error', 'doc-bulk-2');
        $apierror = $this->create_submission_record('error', 'doc-bulk-throw'); // New record for testing API failure.
        $ineligible = $this->create_submission_record('fatal_error', 'doc-bulk-3');

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        // The ineligible record won't trigger an API call, so we expect exactly 3 calls.
        $clientmock->expects($this->exactly(3))
            ->method('check_document_status')
            ->willReturnCallback(function ($externalid) {
                if ($externalid === 'doc-bulk-1') {
                    return (object) ['status' => 1, 'similarity' => 10, 'originality_percentage' => 90];
                }
                if ($externalid === 'doc-bulk-2') {
                    return (object) ['status' => -1];
                }
                if ($externalid === 'doc-bulk-throw') {
                    throw new \moodle_exception('apierror', 'plagiarism_inspera');
                }
                return null;
            });

        $service = new resubmission_recovery_service($DB);
        $result = $service->resubmit_bulk(
            [(int)$recoverable->id, (int)$queueable->id, (int)$apierror->id, (int)$ineligible->id],
            $clientmock
        );

        // Tell Moodle's test framework that we expected the API failure to trigger a debugging message.
        $this->assertDebuggingCalled();

        // Assert the counts add up perfectly (4 selected = 1 recovered + 1 queued + 2 skipped).
        $this->assertEquals(4, (int)$result->selected);
        $this->assertEquals(1, (int)$result->recovered);
        $this->assertEquals(1, (int)$result->queued);
        $this->assertEquals(2, (int)$result->skipped);

        // Verify recoverable worked.
        $updatedrecoverable = $DB->get_record('plagiarism_inspera_subs', ['id' => $recoverable->id], '*', MUST_EXIST);
        $this->assertEquals('finished', $updatedrecoverable->status);

        // Verify queueable worked.
        $updatedqueueable = $DB->get_record('plagiarism_inspera_subs', ['id' => $queueable->id], '*', MUST_EXIST);
        $this->assertEquals('report_requested', $updatedqueueable->status);

        // Verify the API error record was safely aborted and left completely untouched.
        $updatedapierror = $DB->get_record('plagiarism_inspera_subs', ['id' => $apierror->id], '*', MUST_EXIST);
        $this->assertEquals('error', $updatedapierror->status);
        $this->assertEquals('doc-bulk-throw', $updatedapierror->externalid);

        // Verify ineligible record was skipped untouched.
        $updatedineligible = $DB->get_record('plagiarism_inspera_subs', ['id' => $ineligible->id], '*', MUST_EXIST);
        $this->assertEquals('fatal_error', $updatedineligible->status);
    }

    /**
     * Creates a minimal submission record for recovery tests.
     *
     * @param string $status
     * @param string|null $externalid
     * @param int|null $timecreated
     * @return \stdClass
     */
    private function create_submission_record(string $status, ?string $externalid, ?int $timecreated = null): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $record = (object) [
            'cm' => $cm->id,
            'userid' => $user->id,
            'submissionid' => 0,
            'status' => $status,
            'externalid' => $externalid,
            'similarity' => 12.5,
            'originality_score' => 87.5,
            'translation_similarity' => 2.5,
            'ai_index' => '1',
            'originality' => 'high',
            'character_replacement' => 1,
            'hidden_text' => 1,
            'image_as_text' => 1,
            'description' => 'initial',
            'timecreated' => $timecreated ?? (time() - 500),
            'timemodified' => time() - 100,
            'storedfileid' => null,
            'identifier' => 'resubmit-test-' . random_int(1000, 9999),
        ];
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);

        return $record;
    }

    /**
     * Test resubmit_single returns not_found when the record does not exist.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_single
     */
    public function test_resubmit_single_returns_not_found(): void {
        global $DB;

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $service = new resubmission_recovery_service($DB);

        // Pass a non-existent ID.
        $outcome = $service->resubmit_single(999999, $clientmock);

        $this->assertEquals('not_found', $outcome);
    }

    /**
     * Test resubmit_record processes an existing stdClass object directly.
     * @covers \plagiarism_inspera\services\resubmission_recovery_service::resubmit_record
     */
    public function test_resubmit_record_processes_existing_object(): void {
        global $DB;

        $record = $this->create_submission_record('error', null);

        $clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['check_document_status'])
            ->getMock();

        $service = new resubmission_recovery_service($DB);

        // Pass the object directly instead of the ID.
        $outcome = $service->resubmit_record($record, $clientmock);

        $this->assertEquals('queued', $outcome);
    }
}
