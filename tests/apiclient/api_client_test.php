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

defined('MOODLE_INTERNAL') || die();

global $CFG;

use plagiarism_inspera\apiclient\api_client;

/**
 * Unit tests for the api_client class using partial mocks.
 * Covers token management, payload construction (including groups), and file handling.
 */
class api_client_test extends advanced_testcase {
    /** @var \PHPUnit\Framework\MockObject\MockObject|api_client */
    protected $clientmock;

    /** @var string The expected hash of clientid+instid for token caching */
    protected $expectedhash;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('baseurl', 'https://api.example.com', 'plagiarism_inspera');
        set_config('clientid', 'test_client_id', 'plagiarism_inspera');
        set_config('institutionid', 'test_inst_id', 'plagiarism_inspera');

        // Calculate the hash that the code expects (ClientID + | + InstID)
        $this->expectedhash = md5('test_client_id' . '|' . 'test_inst_id');

        // --- Create PARTIAL Mock ---
        // Tell PHPUnit to mock only these specific protected methods
        $this->clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['_do_post_request', '_do_get_request', '_do_s3_put_request'])
            ->getMock();
    }

    public function test_client_constructor() {
        // Test the real constructor (not mocked) via instantiation
        $realclient = new api_client();
        $this->assertNotNull($realclient);
    }

    public function test_get_token_fetches_new_when_uncached() {
        // --- Setup ---
        unset_config('apitoken', 'plagiarism_inspera');
        // Ensure no old hash exists
        unset_config('apitoken_hash', 'plagiarism_inspera');

        $mocktoken = 'new_token_partial_mock';
        $mock_expires_ms = (time() + 3600) * 1000;
        $tokenresponse = json_encode(['token' => $mocktoken, 'expirationTime' => $mock_expires_ms]);

        // Expect _do_post_request (Fetch Token)
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with($this->stringContains('/token'), $this->anything())
            ->willReturn($tokenresponse);

        // Expect _do_get_request (The report call)
        $this->clientmock->expects($this->once())
            ->method('_do_get_request')
            ->willReturn('{"url":"mock_report_url"}');

        // --- Action ---
        $this->clientmock->get_report_url('doc123');

        // --- Assert ---
        $this->assertEquals($mocktoken, get_config('plagiarism_inspera', 'apitoken'));

        // Check if the hash was saved correctly to validate future cache hits
        $this->assertEquals($this->expectedhash, get_config('plagiarism_inspera', 'apitoken_hash'));
    }

    public function test_get_token_uses_cached_when_valid() {
        // --- Setup ---
        $cachedtoken = 'cached_token_partial_mock';
        $expires = time() + 3600;

        set_config('apitoken', $cachedtoken, 'plagiarism_inspera');
        set_config('apitoken_exp', $expires, 'plagiarism_inspera');
        // CRITICAL: Set the matching hash so the client trusts the cache
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        // Expect _do_post_request is NEVER called (Proof that cache worked)
        $this->clientmock->expects($this->never())
            ->method('_do_post_request');

        // Expect _do_get_request
        $this->clientmock->expects($this->once())
            ->method('_do_get_request')
            ->with(
                $this->stringContains('/mode/view'), // Default mode
                $this->anything()
            )
            ->willReturn('{"url":"report_url_cached"}');

        // --- Action ---
        $this->clientmock->get_report_url('doc123');

        // --- Assert ---
        $this->assertEquals($cachedtoken, get_config('plagiarism_inspera', 'apitoken'));
    }

    public function test_get_report_url_modes() {
        // Setup valid cache to avoid token logic noise
        set_config('apitoken', 'tok', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        // We expect 2 calls to _do_get_request
        $this->clientmock->expects($this->exactly(2))
            ->method('_do_get_request')
            ->withConsecutive(
                [$this->stringContains('/mode/edit'), $this->anything()], // 1st call
                [$this->stringContains('/mode/view'), $this->anything()]  // 2nd call
            )
            ->willReturn('{"url":"http://url"}');

        // 1. Test Edit Mode
        $this->clientmock->get_report_url('doc1', 'edit');

        // 2. Test Invalid/Default Mode (Should fallback to view)
        $this->clientmock->get_report_url('doc2', 'hacker_input');
    }

    public function test_create_submission_payload_construction() {
        // --- Setup ---
        set_config('apitoken', 'payload_test_token', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        // Define settings
        $settings = [
            'originality_enable_ai' => 1,
            'anonymous_submissions' => true,
        ];

        // Prepare Metadata Object (Standard DTO)
        $metadata = new \stdClass();
        $metadata->title = 'Title';
        $metadata->author = 'Author';
        $metadata->email = 'e@mail.com';
        $metadata->doctype = 'type';
        $metadata->assignmentid = 'cmid-999';

        // --- Expectation ---
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function ($payload_json) {
                    $payload = json_decode($payload_json, true);
                    $this->assertIsArray($payload);

                    // Standard checks
                    $this->assertEquals('cmid-999', $payload['assignmentId']);
                    $this->assertTrue($payload['enableAIDetection']);
                    $this->assertArrayHasKey('anonymous_submissions', $payload);
                    $this->assertTrue($payload['anonymous_submissions']);

                    // Ensure Group fields are NOT present or false by default
                    $this->assertFalse($payload['teamSubmission'] ?? false);

                    return true;
                }),
                $this->callback(function ($headers) {
                    $this->assertContains('Authorization: Bearer payload_test_token', $headers);
                    return true;
                })
            )
            ->willReturn('{"documentId":"mockDocId","presignedS3Url":"mockS3Url"}');

        // --- Action ---
        $response = $this->clientmock->create_submission($metadata, $settings);

        // --- Assert ---
        $this->assertEquals('mockDocId', $response->documentId);
    }

    public function test_create_submission_includes_educators_and_student_email_top_level() {
        // --- Setup ---
        set_config('apitoken', 'payload_test_token2', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        $educators = [
            ['id' => 10, 'name' => 'Teacher One', 'email' => 't1@example.com'],
            ['id' => '20', 'name' => 'Teacher Two', 'email' => 't2@example.com'],
            ['id' => null, 'name' => 'No Id', 'email' => 'noid@example.com'], // Invalid, should be skipped
        ];

        // Prepare Metadata Object
        $metadata = new \stdClass();
        $metadata->title = 'My Doc';
        $metadata->author = 'Student Name';
        $metadata->email = 'student@example.com';
        $metadata->doctype = 'text/html';
        $metadata->assignmentid = 'cmid-123';

        // --- Expectation ---
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function ($payload_json) {
                    $payload = json_decode($payload_json, true);

                    // Student email must be at top-level
                    $this->assertEquals('student@example.com', $payload['email']);

                    // Educators must be present as normalized array
                    $this->assertArrayHasKey('educators', $payload);
                    $this->assertCount(2, $payload['educators']); // 3 provided, 1 invalid skipped
                    $this->assertEquals('10', $payload['educators'][0]['id']); // String cast check

                    return true;
                }),
                $this->anything()
            )
            ->willReturn('{"documentId":"mockDocId2","presignedS3Url":"mockS3Url2"}');

        // --- Action ---
        $response = $this->clientmock->create_submission($metadata, [], $educators);

        // --- Assert ---
        $this->assertEquals('mockDocId2', $response->documentId);
    }

    /**
     * Test specifically for Group Submissions.
     * Verifies that 'students' array is populated and 'teamSubmission' is true.
     */
    public function test_create_submission_with_groups() {
        // --- Setup ---
        set_config('apitoken', 'payload_test_token3', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        // Prepare Metadata
        $metadata = new \stdClass();
        $metadata->title = 'Group Doc';
        $metadata->author = 'Group Leader';
        $metadata->email = 'leader@test.com';
        $metadata->doctype = 'text/html';
        $metadata->assignmentid = 'cmid-group';

        // Prepare Students Array
        $students = [
            ['id' => 101, 'name' => 'Member One', 'email' => 'm1@test.com'], // Int ID
            ['id' => '102', 'name' => 'Member Two', 'email' => 'm2@test.com'], // String ID
            ['id' => 103, 'name' => '', 'email' => 'm3@test.com'], // Invalid (Empty Name)
        ];

        // --- Expectation ---
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function ($payload_json) {
                    $payload = json_decode($payload_json, true);

                    // 1. Verify Team Submission Flag
                    $this->assertTrue($payload['teamSubmission'], 'teamSubmission flag should be true');

                    // 2. Verify Students Array
                    $this->assertArrayHasKey('students', $payload);
                    $this->assertCount(2, $payload['students'], 'Should contain 2 valid students');

                    // 3. Verify Normalization
                    $this->assertSame('101', $payload['students'][0]['id']); // Int cast to string
                    $this->assertSame('Member One', $payload['students'][0]['name']);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn('{"documentId":"groupDocId","presignedS3Url":"groupS3Url"}');

        // --- Action ---
        // Pass $students as the 4th argument
        $response = $this->clientmock->create_submission($metadata, [], [], $students);

        $this->assertEquals('groupDocId', $response->documentId);
    }

    public function test_upload_to_presigned_url_failure() {
        // --- Expectation ---
        $this->clientmock->expects($this->once())
            ->method('_do_s3_put_request')
            ->with('https://s3.example.com/failed', 'content', 'type')
            ->willReturn(false);

        // --- Action ---
        $result = $this->clientmock->upload_to_presigned_url('https://s3.example.com/failed', 'content', 'type');

        // --- Assertion ---
        $this->assertFalse($result);
    }

    public function test_upload_to_presigned_url_success() {
        // --- Expectation ---
        $this->clientmock->expects($this->once())
            ->method('_do_s3_put_request')
            ->with('https://s3.example.com/success', 'content', 'type')
            ->willReturn(true);

        // --- Action ---
        $result = $this->clientmock->upload_to_presigned_url('https://s3.example.com/success', 'content', 'type');

        // --- Assertion ---
        $this->assertTrue($result);
    }
}
