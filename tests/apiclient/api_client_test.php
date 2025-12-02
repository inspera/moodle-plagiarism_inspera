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
        unset_config('apitoken_exp', 'plagiarism_originality');

        $mocktoken = 'new_token_partial_mock';
        $mockexpires_ms = (time() + 3600) * 1000;
        $tokenresponse = json_encode(['token' => $mocktoken, 'expirationTime' => $mockexpires_ms]);

        // Expect _do_post_request to be called once for /token
        $this->clientmock->expects($this->once())
            ->method('_do_post_request')
            ->with($this->stringContains('/token'), $this->anything())
            ->willReturn($tokenresponse);

        // Expect _do_get_request for the subsequent call (e.g., get_report_url)
        $this->clientmock->expects($this->once())
            ->method('_do_get_request')
            ->willReturn('{"url":"mock_report_url"}'); // Need valid JSON for get_report_url

        // --- Action ---
        // Call method on the PARTIAL MOCK
        $reportdata = $this->clientmock->get_report_url('doc123');

        // --- Assert ---
        $this->assertEquals($mocktoken, get_config('plagiarism_originality', 'apitoken'));
        $expectedexpires_sec = floor($mockexpires_ms / 1000);
        $actualexpires_sec = get_config('plagiarism_originality', 'apitoken_exp');
        $this->assertEqualsWithDelta($expectedexpires_sec, $actualexpires_sec, 2);
        $this->assertEquals('mock_report_url', $reportdata->url); // Verify secondary call worked too
    }

    public function test_get_token_uses_cached_when_valid() {
        // --- Setup ---
        $cachedtoken = 'cached_token_partial_mock';
        $expires = time() + 3600;
        set_config('apitoken', $cachedtoken, 'plagiarism_originality');
        set_config('apitoken_exp', $expires, 'plagiarism_originality');

        // Expect _do_post_request is NEVER called
        $this->clientmock->expects($this->never())
            ->method('_do_post_request');

        // Expect _do_get_request is called once (for get_report_url)
        // Check that the correct Authorization header is passed internally
        $this->clientmock->expects($this->once())
            ->method('_do_get_request')
            ->with(
                $this->stringContains('/mode/view'), // Match the URL for get_report_url
                $this->callback(function($headers) use ($cachedtoken) {
                    // Check if the auth header is in the array passed to _do_get_request
                    $expectedHeader = 'Authorization: Bearer ' . $cachedtoken;
                    $this->assertContains($expectedHeader, $headers);
                    return true; // Callback must return true
                })
            )
            ->willReturn('{"url":"report_url_cached"}');

        // --- Action ---
        $reportdata = $this->clientmock->get_report_url('doc123');

        // --- Assert ---
        $this->assertEquals($cachedtoken, get_config('plagiarism_originality', 'apitoken'));
        $this->assertEquals('report_url_cached', $reportdata->url);
    }


    public function test_create_submission_payload_construction() {
        // --- Setup ---
        set_config('apitoken', 'payload_test_token', 'plagiarism_originality');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_originality');

        // Define settings, INCLUDING the new anonymous flag
        $settings = [
            'originality_enable_ai' => 1,
            'anonymous_submissions' => true
        ];
        $expectedAssignmentId = 'cmid-999';

        // --- Expectation ---
        // Expect _do_post_request to be called once for /create/submission
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

                    // Check that the anonymous flag made it into the JSON payload
                    $this->assertArrayHasKey('anonymous_submissions', $payload);
                    $this->assertTrue($payload['anonymous_submissions']);
                    // ---------------------

                    return true;
                }),
                $this->callback(function($headers) { // Also check headers if needed
                    $this->assertContains('Authorization: Bearer payload_test_token', $headers);
                    return true;
                })
            )
            ->willReturn('{"documentId":"mockDocId","presignedS3Url":"mockS3Url"}');

        // --- Action ---
        $response = $this->clientmock->create_submission(
            'Title', 'Author', 'e@mail.com', 'type', $expectedAssignmentId, $settings
        );

        // --- Assert --- (Optional, check return value if needed)
        $this->assertEquals('mockDocId', $response->documentId);
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
