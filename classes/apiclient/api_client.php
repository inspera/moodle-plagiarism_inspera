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
 * API client for the Inspera Originality plagiarism service.
 * Handles authentication (token) and all API endpoints.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\apiclient;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php'); // Only need one require_once

/**
 * API client class for Inspera Originality.
 * Uses internal methods for HTTP requests to facilitate testing via partial mocks.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    private $baseurl;
    private $clientid;
    private $institutionid;

    /**
     * Constructor for the API client.
     * Loads the base configuration from the plugin settings.
     */
    public function __construct() {
        $this->baseurl       = rtrim(get_config('plagiarism_originality', 'baseurl'), '/');
        $this->clientid      = get_config('plagiarism_originality', 'clientid');
        $this->institutionid = get_config('plagiarism_originality', 'institutionid');
    }

    // --- Internal Request Methods ---

    /**
     * Performs a POST request using Moodle's curl helper.
     * This method is intended to be mocked during unit testing.
     *
     * @param string $url The full URL endpoint.
     * @param string $payloadJson The JSON payload string.
     * @param array $headers Optional array of additional HTTP headers (e.g., ['Authorization: Bearer xyz']).
     * @return string The raw response body from the server.
     * @throws \moodle_exception If the curl request fails or returns an error.
     */
    protected function _do_post_request(string $url, string $payloadJson, array $headers = []): string {
        $curl = new \curl(['timeout' => 60]); // Add a reasonable timeout
        $curl->setHeader('Content-Type: application/json');
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }

        $response = $curl->post($url, $payloadJson);
        $errno = $curl->get_errno();

        if ($errno !== 0) {
            // Throw exception on curl error for clear test failures
            $error = $curl->error; // Access the public property
            throw new \moodle_exception('curlerror', 'plagiarism_originality', '', null,
                "Curl error ({$errno}) accessing {$url}: {$error}");
        }
        // Consider checking HTTP status code here if needed (e.g., $curl->get_info()['http_code'])
        return $response;
    }

    /**
     * Performs a GET request using Moodle's curl helper.
     * This method is intended to be mocked during unit testing.
     *
     * @param string $url The full URL endpoint.
     * @param array $headers Optional array of additional HTTP headers.
     * @return string The raw response body from the server.
     * @throws \moodle_exception If the curl request fails or returns an error.
     */
    protected function _do_get_request(string $url, array $headers = []): string {
        $curl = new \curl(['timeout' => 60]);
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }

        $response = $curl->get($url);
        $errno = $curl->get_errno();

        if ($errno !== 0) {
            $error = $curl->error;
            throw new \moodle_exception('curlerror', 'plagiarism_originality', '', null,
                "Curl error ({$errno}) accessing {$url}: {$error}");
        }
        // Consider checking HTTP status code here
        return $response;
    }

    /**
     * Performs a PUT request, specifically for uploading to a presigned S3 URL.
     * This method is intended to be mocked during unit testing.
     *
     * @param string $url The presigned S3 URL.
     * @param string $content The raw file content to upload.
     * @param string $mimetype The mimetype of the file content.
     * @return bool True if the upload appears successful (no curl error), false otherwise.
     */
    protected function _do_s3_put_request(string $url, string $content, string $mimetype): bool {
        $curl = new \curl(['timeout' => 300]); // Longer timeout for uploads
        $curl->setHeader('Content-Type: ' . $mimetype);

        if (!defined('PHPUNIT_TEST')) { mtrace("Uploading file to Originality S3 URL"); }

        // PUT request often doesn't return a body on success
        $curl->put($url, $content);
        $errno = $curl->get_errno();

        if ($errno !== 0) {
            $error = $curl->error;
            if (!defined('PHPUNIT_TEST')) { mtrace("Upload failed ({$errno}): " . $error); }
            return false;
        }
        return true; // Assume success if no curl error
    }

    // --- API Logic Methods ---

    /**
     * Retrieve and cache an API authentication token.
     *
     * @return string The API token.
     * @throws \moodle_exception If fetching or parsing the token fails.
     */
    private function get_token(): string {
        $token = get_config('plagiarism_originality', 'apitoken');
        $expires = (int) get_config('plagiarism_originality', 'apitoken_exp'); // Cast to int

        // Check if token exists and hasn't expired (allow 60s buffer)
        if (!empty($token) && $expires > (time() + 60)) {
            return $token; // Return cached token
        }

        // Fetch a new token if no valid cached token exists
        $payload = json_encode([
            'clientId'      => $this->clientid,
            'institutionId' => $this->institutionid
        ]);

        try {
            // Use the internal method for the actual HTTP call
            $response = $this->_do_post_request($this->baseurl . '/token', $payload);
        } catch (\moodle_exception $e) {
            // Re-throw curl errors with a more specific context if desired, or let them propagate
            throw new \moodle_exception('apitokenerror', 'plagiarism_originality', '', null, 'Failed to connect to token endpoint: ' . $e->getMessage());
        }

        $data = json_decode($response);

        // Validate the response
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality', '', null, 'Token response not valid JSON: ' . $response);
        }
        if (empty($data->token) || !isset($data->expirationTime)) {
            throw new \moodle_exception('apitokenerror', 'plagiarism_originality', '', null, 'API response missing token or expirationTime: ' . $response);
        }

        // Cache the new token
        $newtoken = $data->token;
        $newexpires_sec = floor((float)$data->expirationTime / 1000); // Ensure float before division
        set_config('apitoken', $newtoken, 'plagiarism_originality');
        set_config('apitoken_exp', $newexpires_sec, 'plagiarism_originality');

        return $newtoken; // Return the newly fetched token
    }

    /**
     * Create a submission (metadata only) via the API.
     *
     * @param string $title Document title.
     * @param string $author Author's name.
     * @param string $email Author's email.
     * @param string $doctype File mimetype.
     * @param string $assignmentId Moodle course module ID (cmid).
     * @param array $settings Activity-level plugin settings.
     * @return \stdClass API response object containing documentId and presignedS3Url.
     * @throws \moodle_exception If the API call fails or returns invalid data.
     */
    public function create_submission(
        string $title,
        string $author,
        string $email,
        string $doctype,
        string $assignmentId,
        array $settings = []
    ): \stdClass {
        $token = $this->get_token(); // Ensures we have a valid token
        $headers = ['Authorization: Bearer ' . $token];

        // Build the payload
        $payload = [
            'documentTitle' => $title,
            'author'        => $author,
            'email'         => $email,
            'docType'       => $doctype,
            'assignmentId'  => $assignmentId,
        ];
        if (!empty($this->institutionid)) { // Use property directly
            $payload['institutionName'] = $this->institutionid;
        }
        if (!empty($settings['originality_metadata_analysis'])) { $payload['metadataAnalysis'] = (bool)$settings['originality_metadata_analysis']; }
        if (!empty($settings['originality_archive'])) { $payload['archive'] = (bool)$settings['originality_archive']; }
        if (!empty($settings['originality_enable_ai'])) { $payload['enableAIDetection'] = (bool)$settings['originality_enable_ai']; }
        if (!empty($settings['originality_enable_translations'])) {
            $payload['translationLanguages'] = array_values(array_filter(explode(',', $settings['originality_translation_languages'] ?? ''))); // Ensure clean array
        }
        $enablecontext = !empty($settings['originality_enable_context_similarity']);
        $payload['enableContextSimilarity'] = $enablecontext;
        if ($enablecontext) {
            $payload['sentenceThresholds'] = ['contextualSimilaritiesThreshold' => (int)($settings['originality_context_threshold'] ?? 50)];
        }
        // ... (Include/Exclude URLs logic as before) ...
        $includesources = []; $excludesources = [];
        if (!empty($settings['originality_enable_include_urls']) && !empty(trim($settings['originality_include_urls']))) {
            $includesources = array_values(array_filter(array_map('trim', explode(',', $settings['originality_include_urls']))));
        }
        if (!empty($settings['originality_enable_exclude_urls']) && !empty(trim($settings['originality_exclude_urls']))) {
            $excludesources = array_values(array_filter(array_map('trim', explode(',', $settings['originality_exclude_urls']))));
        }
        if (!empty($includesources) || !empty($excludesources)) {
            $payload['sources'] = [];
            if (!empty($excludesources)) $payload['sources']['excludeSources'] = $excludesources;
            if (!empty($includesources)) $payload['sources']['includeSources'] = $includesources;
        }
        // --- End payload building ---

        try {
            // Use the internal method for the HTTP call
            $response = $this->_do_post_request($this->baseurl . '/create/submission', json_encode($payload), $headers);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', null, 'Failed to create submission: ' . $e->getMessage());
        }

        $data = json_decode($response);

        // Validate response
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality', '', null, 'Create submission response not valid JSON: ' . $response);
        }
        if (empty($data->documentId) || empty($data->presignedS3Url)) {
            if (!defined('PHPUNIT_TEST')) { mtrace("API create_submission failed: " . $response); }
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', null, 'API response missing documentId or presignedS3Url: ' . $response);
        }

        return $data;
    }

    /**
     * Upload the file content to the provided presigned S3 URL.
     *
     * @param string $url The presigned S3 URL.
     * @param string $content The raw file content.
     * @param string $mimetype The file's mimetype.
     * @return bool True on success, false on failure.
     */
    public function upload_to_presigned_url(string $url, string $content, string $mimetype): bool {
        // Use the internal method for the HTTP PUT call
        // No token needed for S3 presigned URLs
        return $this->_do_s3_put_request($url, $content, $mimetype);
    }

    /**
     * Poll the API for the processing status of a document.
     *
     * @param string $documentid The external document ID from the API.
     * @return \stdClass The API status response object.
     * @throws \moodle_exception If the API call fails or returns invalid data.
     */
    public function check_document_status(string $documentid): \stdClass {
        $token = $this->get_token();
        $headers = ['Authorization: Bearer ' . $token];
        $url = $this->baseurl . '/document/' . $documentid . '/checkStatus';

        try {
            // Use the internal method for the HTTP GET call
            $response = $this->_do_get_request($url, $headers);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', null, 'Failed to check document status: ' . $e->getMessage());
        }

        $data = json_decode($response);

        // Validate response
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality', '', null, 'Check status response not valid JSON: ' . $response);
        }
        // Potentially add check for expected status fields if needed
        // if (!isset($data->status)) { ... }

        if (!defined('PHPUNIT_TEST')) { mtrace("Polled status for doc {$documentid}: " . $response); }

        return $data;
    }

    /**
     * Gets a temporary, viewable URL for a processed similarity report from the API.
     *
     * @param string $documentId The external document ID from the API.
     * @return \stdClass The API response object, expecting ->url property.
     * @throws \moodle_exception If the API call fails or returns invalid data.
     */
    public function get_report_url(string $documentId): \stdClass {
        $token = $this->get_token();
        $headers = ['Authorization: Bearer ' . $token];
        $url = $this->baseurl . '/document/' . $documentId . '/mode/view';

        try {
            // Use the internal method for the HTTP GET call
            $response = $this->_do_get_request($url, $headers);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', null, 'Failed to get report URL: ' . $e->getMessage());
        }

        $data = json_decode($response);

        // Validate response
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality', '', null, 'Report URL response not valid JSON: ' . $response);
        }
        if (empty($data->url)) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', null, 'API response missing report URL: ' . $response);
        }

        return $data; // expects $data->url
    }
}
