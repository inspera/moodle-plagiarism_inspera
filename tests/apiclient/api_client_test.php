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
 * API Client tests for the Plagiarism Inspera plugin.
 *
 * @package     plagiarism_inspera
 * @copyright   2026 Inspera
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use plagiarism_inspera\apiclient\api_client;

/**
 * Unit tests for the api_client class.
 *
 * @package     plagiarism_inspera
 * @copyright   2026 Inspera
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_client_test extends \advanced_testcase {
    /** @var \PHPUnit\Framework\MockObject\MockObject|api_client */
    protected $clientmock;

    /** @var string The expected hash of clientid+instid for token caching */
    protected $expectedhash;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('baseurl', 'https://api.example.com', 'plagiarism_inspera');
        set_config('clientid', 'test_client_id', 'plagiarism_inspera');
        set_config('institutionid', 'test_inst_id', 'plagiarism_inspera');

        // Calculate the hash that the code expects.
        $this->expectedhash = md5('test_client_id' . '|' . 'test_inst_id');

        $this->clientmock = $this->getMockBuilder(api_client::class)
            ->onlyMethods(['do_post_request', 'do_get_request', 'do_s3_put_request'])
            ->getMock();

        // This bypasses visibility checks entirely.
        $property = new \ReflectionProperty(api_client::class, 'isvalidating');
        $property->setAccessible(true);
        $property->setValue($this->clientmock, false);
    }

    /**
     * Test the client constructor.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::__construct
     */
    public function test_client_constructor(): void {
        $realclient = new api_client();
        $this->assertNotNull($realclient);
    }

    /**
     * Test token fetching logic.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::get_report_url
     */
    public function test_get_token_fetches_new_when_uncached(): void {
        unset_config('apitoken', 'plagiarism_inspera');
        unset_config('apitoken_hash', 'plagiarism_inspera');

        $mocktoken = 'new_token_partial_mock';
        $mockexpiresms = (time() + 3600) * 1000;
        $tokenresponse = json_encode(['token' => $mocktoken, 'expirationTime' => $mockexpiresms]);

        $this->clientmock->expects($this->once())
            ->method('do_post_request')
            ->with($this->stringContains('/token'), $this->anything())
            ->willReturn($tokenresponse);

        $this->clientmock->expects($this->once())
            ->method('do_get_request')
            ->willReturn('{"url":"mock_report_url"}');

        $this->clientmock->get_report_url('doc123');
        $this->assertEquals($mocktoken, get_config('plagiarism_inspera', 'apitoken'));
        $this->assertEquals($this->expectedhash, get_config('plagiarism_inspera', 'apitoken_hash'));
    }

    /**
     * Test token cache logic.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::get_report_url
     */
    public function test_get_token_uses_cached_when_valid(): void {
        $cachedtoken = 'cached_token_partial_mock';
        $expires = time() + 3600;

        set_config('apitoken', $cachedtoken, 'plagiarism_inspera');
        set_config('apitoken_exp', $expires, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        $this->clientmock->expects($this->never())
            ->method('do_post_request');

        $this->clientmock->expects($this->once())
            ->method('do_get_request')
            ->with($this->stringContains('/mode/view'), $this->anything())
            ->willReturn('{"url":"report_url_cached"}');

        $this->clientmock->get_report_url('doc123');
        $this->assertEquals($cachedtoken, get_config('plagiarism_inspera', 'apitoken'));
    }

    /**
     * Test report URL modes.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::get_report_url
     */
    public function test_get_report_url_modes(): void {
        set_config('apitoken', 'tok', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        $this->clientmock->expects($this->exactly(2))
            ->method('do_get_request')
            ->withConsecutive(
                [$this->stringContains('/mode/edit'), $this->anything()],
                [$this->stringContains('/mode/view'), $this->anything()]
            )
            ->willReturn('{"url":"http://url"}');

        $this->clientmock->get_report_url('doc1', 'edit');
        $this->clientmock->get_report_url('doc2', 'hacker_input');
    }

    /**
     * Test submission payload construction.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::create_submission
     */
    public function test_create_submission_payload_construction(): void {
        set_config('apitoken', 'payload_test_token', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        $settings = ['originality_enable_ai' => 1, 'anonymous_submissions' => true];
        $metadata = (object) [
            'title' => 'Title',
            'author' => 'Author',
            'email' => 'e@mail.com',
            'doctype' => 'type',
            'assignmentid' => 'cmid-999',
        ];

        $this->clientmock->expects($this->once())
            ->method('do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function ($payloadjson) {
                    $payload = json_decode($payloadjson, true);
                    $this->assertIsArray($payload);
                    $this->assertEquals('cmid-999', $payload['assignmentId']);
                    $this->assertTrue($payload['enableAIDetection']);
                    return true;
                }),
                $this->callback(function ($headers) {
                    $this->assertContains('Authorization: Bearer payload_test_token', $headers);
                    return true;
                })
            )
            ->willReturn('{"documentId":"mockDocId","presignedS3Url":"mockS3Url"}');

        $response = $this->clientmock->create_submission($metadata, $settings);
        $this->assertEquals('mockDocId', $response->documentId);
    }

    /**
     * Test educator inclusion in payload.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::create_submission
     */
    public function test_create_submission_includes_educators_and_student_email_top_level(): void {
        set_config('apitoken', 'payload_test_token2', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        $educators = [
            ['id' => 10, 'name' => 'Teacher One', 'email' => 't1@example.com'],
            ['id' => '20', 'name' => 'Teacher Two', 'email' => 't2@example.com'],
            ['id' => null, 'name' => 'No Id', 'email' => 'noid@example.com'],
        ];

        $metadata = (object) [
            'title' => 'My Doc',
            'author' => 'Student Name',
            'email' => 'student@example.com',
            'doctype' => 'text/html',
            'assignmentid' => 'cmid-123',
        ];

        $this->clientmock->expects($this->once())
            ->method('do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function ($payloadjson) {
                    $payload = json_decode($payloadjson, true);
                    $this->assertEquals('student@example.com', $payload['email']);
                    $this->assertCount(2, $payload['educators']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn('{"documentId":"mockDocId2","presignedS3Url":"mockS3Url2"}');

        $response = $this->clientmock->create_submission($metadata, [], $educators);
        $this->assertEquals('mockDocId2', $response->documentId);
    }

    /**
     * Test group submission logic.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::create_submission
     */
    public function test_create_submission_with_groups(): void {
        set_config('apitoken', 'payload_test_token3', 'plagiarism_inspera');
        set_config('apitoken_exp', time() + 3600, 'plagiarism_inspera');
        set_config('apitoken_hash', $this->expectedhash, 'plagiarism_inspera');

        $metadata = (object) [
            'title' => 'Group Doc',
            'author' => 'Group Leader',
            'email' => 'leader@test.com',
            'doctype' => 'text/html',
            'assignmentid' => 'cmid-group',
        ];

        $students = [
            ['id' => 101, 'name' => 'Member One', 'email' => 'm1@test.com'],
            ['id' => '102', 'name' => 'Member Two', 'email' => 'm2@test.com'],
            ['id' => 103, 'name' => '', 'email' => 'm3@test.com'],
        ];

        $this->clientmock->expects($this->once())
            ->method('do_post_request')
            ->with(
                $this->stringContains('/create/submission'),
                $this->callback(function ($payloadjson) {
                    $payload = json_decode($payloadjson, true);
                    $this->assertTrue($payload['teamSubmission']);
                    $this->assertCount(2, $payload['students']);
                    $this->assertSame('101', $payload['students'][0]['id']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn('{"documentId":"groupDocId","presignedS3Url":"groupS3Url"}');

        $response = $this->clientmock->create_submission($metadata, [], [], $students);
        $this->assertEquals('groupDocId', $response->documentId);
    }

    /**
     * Test upload failure.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::upload_to_presigned_url
     */
    public function test_upload_to_presigned_url_failure(): void {
        $this->clientmock->expects($this->once())
            ->method('do_s3_put_request')
            ->with('https://s3.example.com/failed', 'content', 'type')
            ->willReturn(false);

        $result = $this->clientmock->upload_to_presigned_url('https://s3.example.com/failed', 'content', 'type');
        $this->assertFalse($result);
    }

    /**
     * Test upload success.
     *
     * @covers \plagiarism_inspera\apiclient\api_client::upload_to_presigned_url
     */
    public function test_upload_to_presigned_url_success(): void {
        $this->clientmock->expects($this->once())
            ->method('do_s3_put_request')
            ->with('https://s3.example.com/success', 'content', 'type')
            ->willReturn(true);

        $result = $this->clientmock->upload_to_presigned_url('https://s3.example.com/success', 'content', 'type');
        $this->assertTrue($result);
    }
}
