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

use plagiarism_originality\apiclient\api_client;

/**
 * Unit tests for the api_client class using partial mocks.
 * (Your PHPDocs here)
 */
class plagiarism_originality_api_client_test extends advanced_testcase {

    /** @var \PHPUnit\Framework\MockObject\MockObject|api_client */
    protected $clientmock; // Will hold the partial mock

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('baseurl', 'https://api.example.com', 'plagiarism_originality');
        set_config('clientid', 'test_client_id', 'plagiarism_originality');
        set_config('institutionid', 'test_inst_id', 'plagiarism_originality');

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
        unset_config('apitoken', 'plagiarism_originality');
        // Ensure no old hash exists
        unset_config('apitoken_hash', 'plagiarism_originality');

        $mocktoken = 'new_token_partial_mock';
        $mockexpires_ms = (time() + 3600) * 1000;
        $tokenresponse = json_encode(['token' => $mocktoken, 'expirationTime' => $mockexpires_ms]);

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
        $reportdata = $this->clientmock->get_report_url('doc123');

        // --- Assert ---
        $this->assertEquals($mocktoken, get_config('plagiarism_originality', 'apitoken'));

        // NEW ASSERTION: Check if the hash was saved correctly
        $this->assertEquals($this->expectedhash, get_config('plagiarism_originality', 'apitoken_hash'));
    }

    public function test_get_token_uses_cached_when_valid() {
        // --- Setup ---
        $cachedtoken = 'cached_token_partial_mock';
        $expires = time() + 3600;

        set_config('apitoken', $cachedtoken, 'plagiarism_originality');
        set_config('apitoken_exp', $expires, 'plagiarism_originality');
        // CRITICAL FIX: Set the matching hash
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_originality');

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
        $reportdata = $this->clientmock->get_report_url('doc123');

        // --- Assert ---
        $this->assertEquals($cachedtoken, get_config('plagiarism_originality', 'apitoken'));
    }

    public function test_get_report_url_modes() {
        // Setup valid cache to avoid token logic noise
        set_config('apitoken', 'tok', 'plagiarism_originality');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_originality');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_originality');

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
        set_config('apitoken', 'payload_test_token', 'plagiarism_originality');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_originality');

        // CRITICAL FIX: Add the expected hash so the code trusts the cache
        // Use the same $this->expectedhash we calculated in setUp()
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_originality');

        // Define settings, INCLUDING the new anonymous flag
        $settings = [
            'originality_enable_ai' => 1,
            'anonymous_submissions' => true
        ];
        $expectedAssignmentId = 'cmid-999';

        // --- Expectation ---
        // Expect _do_post_request to be called once for /create/submission
        // (Since token is now cached/valid, the call to /token is skipped)
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function($payloadJson) use ($expectedAssignmentId) {
                    $payload = json_decode($payloadJson, true);
                    $this->assertIsArray($payload);

                    // Standard checks
                    $this->assertEquals($expectedAssignmentId, $payload['assignmentId']);
                    $this->assertTrue($payload['enableAIDetection']);
                    $this->assertArrayHasKey('anonymous_submissions', $payload);
                    $this->assertTrue($payload['anonymous_submissions']);

                    return true;
                }),
                $this->callback(function($headers) {
                    $this->assertContains('Authorization: Bearer payload_test_token', $headers);
                    return true;
                })
            )
            ->willReturn('{"documentId":"mockDocId","presignedS3Url":"mockS3Url"}');

        // --- Action ---
        $response = $this->clientmock->create_submission(
            'Title', 'Author', 'e@mail.com', 'type', $expectedAssignmentId, $settings
        );

        // --- Assert ---
        $this->assertEquals('mockDocId', $response->documentId);
    }


    public function test_create_submission_includes_educators_and_student_email_top_level() {
        // --- Setup ---
        set_config('apitoken', 'payload_test_token2', 'plagiarism_originality');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_originality');

        $studentEmail = 'student@example.com';
        $educators = [
            ['id' => 10, 'name' => 'Teacher One', 'email' => 't1@example.com'],
            ['id' => '20', 'name' => 'Teacher Two', 'email' => 't2@example.com'],
            // This malformed entry should be ignored by normalization
            ['id' => null, 'name' => 'No Id', 'email' => 'noid@example.com'],
        ];

        $expectedAssignmentId = 'cmid-123';

        // Expect _do_post_request to capture the payload
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function($payloadJson) use ($studentEmail, $educators, $expectedAssignmentId) {
                    $payload = json_decode($payloadJson, true);
                    $this->assertIsArray($payload);

                    // Student email must be at top-level and equal to provided email
                    $this->assertArrayHasKey('email', $payload);
                    $this->assertEquals($studentEmail, $payload['email']);

                    // Assignment id should be present
                    $this->assertEquals($expectedAssignmentId, $payload['assignmentId']);

                    // Educators must be present as normalized array
                    $this->assertArrayHasKey('educators', $payload);
                    $this->assertIsArray($payload['educators']);
                    // Two valid educators expected (the malformed one ignored)
                    $this->assertCount(2, $payload['educators']);
                    $this->assertEquals(['id' => '10', 'name' => 'Teacher One', 'email' => 't1@example.com'], $payload['educators'][0]);
                    $this->assertEquals(['id' => '20', 'name' => 'Teacher Two', 'email' => 't2@example.com'], $payload['educators'][1]);
                    return true;
                }),
                $this->callback(function($headers) {
                    $this->assertContains('Authorization: Bearer payload_test_token2', $headers);
                    return true;
                })
            )
            ->willReturn('{"documentId":"mockDocId2","presignedS3Url":"mockS3Url2"}');

        // --- Action ---
        $response = $this->clientmock->create_submission(
            'My Doc', 'Student Name', $studentEmail, 'text/html', $expectedAssignmentId, [], $educators
        );

        // --- Assert ---
        $this->assertEquals('mockDocId2', $response->documentId);
    }


    public function test_upload_to_presigned_url_failure() {
        // --- Expectation ---
        // Expect _do_s3_put_request to be called once and return false
        $this->clientmock->expects($this->once())
            ->method('_do_s3_put_request')
            ->with('https://s3.example.com/failed', 'content', 'type')
            ->willReturn(false); // Force mocked method to return false

        // --- Action ---
        $result = $this->clientmock->upload_to_presigned_url('https://s3.example.com/failed', 'content', 'type');

        // --- Assertion ---
        $this->assertFalse($result);
    }

    public function test_upload_to_presigned_url_success() {
        // --- Expectation ---
        // Expect _do_s3_put_request to be called once and return true
        $this->clientmock->expects($this->once())
            ->method('_do_s3_put_request')
            ->with('https://s3.example.com/success', 'content', 'type')
            ->willReturn(true); // Force mocked method to return true

        // --- Action ---
        $result = $this->clientmock->upload_to_presigned_url('https://s3.example.com/success', 'content', 'type');

        // --- Assertion ---
        $this->assertTrue($result);
    }
}
