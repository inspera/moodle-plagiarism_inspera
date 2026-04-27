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
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\apiclient;

/**
 * API client class for Inspera Originality.
 * Uses internal methods for HTTP requests to facilitate testing via partial mocks.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** @var string The base URL for the API. */
    private $baseurl;

    /** @var string The client ID. */
    private $clientid;

    /** @var string The institution ID. */
    private $institutionid;

    /** @var bool Whether the client was instantiated with temporary/unsaved settings. */
    protected $isvalidating = false;

    /**
     * Constructor for the API client.
     *
     * @param \stdClass|null $config Optional config override.
     */
    public function __construct(\stdClass $config = null) {
        if ($config) {
            // VALIDATION MODE.
            $this->baseurl       = rtrim($config->baseurl ?? '', '/');
            $this->clientid      = $config->clientid ?? '';
            $this->institutionid = $config->institutionid ?? '';
            $this->isvalidating = true;
        } else {
            // STANDARD MODE.
            $this->baseurl       = rtrim(get_config('plagiarism_inspera', 'baseurl'), '/');
            $this->clientid      = get_config('plagiarism_inspera', 'clientid');
            $this->institutionid = get_config('plagiarism_inspera', 'institutionid');
        }
    }

    /**
     * Tests the API connection using the current configuration.
     *
     * @return bool True if connection successful.
     * @throws \moodle_exception If connection fails.
     */
    public function test_connection(): bool {
        $this->get_token();
        return true;
    }

    // Internal Request Methods.

    /**
     * Performs a POST request using Moodle's curl helper.
     * This method is intended to be mocked during unit testing.
     *
     * @param string $url The full URL endpoint.
     * @param string $payloadjson The JSON payload string.
     * @param array $headers Optional array of additional HTTP headers.
     * @return string The raw response body from the server.
     * @throws \moodle_exception If the curl request fails or returns an error.
     */
    protected function do_post_request(string $url, string $payloadjson, array $headers = []): string {
        if (!defined('PHPUNIT_TEST')) {
            mtrace("------------------------------------------------");
            mtrace("DEBUG: API POST Request to: [{$url}]");
            // Check if Authorization header is present (masked).
            $authstatus = 'Missing';
            foreach ($headers as $h) {
                if (strpos($h, 'Authorization:') === 0) {
                    $authstatus = 'Present (Bearer ...)';
                }
            }
            mtrace("DEBUG: Auth Header: {$authstatus}");
            mtrace("DEBUG: Payload: " . substr($payloadjson, 0, 500) . (strlen($payloadjson) > 500 ? '...' : ''));
        }

        // Pass 'ignoresecurity' to the CONSTRUCTOR, not the post() method.
        $curl = new \curl(['ignoresecurity' => true, 'timeout' => 60]);

        $curl->setHeader('Content-Type: application/json');
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }

        $response = $curl->post($url, $payloadjson);
        $info = $curl->get_info();          // 1. Get the transfer info.
        $httpcode = $info['http_code'] ?? 0; // 2. Extract the code.
        $errno = $curl->get_errno();

        // DEBUG RESPONSE.
        if (!defined('PHPUNIT_TEST')) {
            mtrace("DEBUG: HTTP Status Code: [{$httpcode}]");
            if ($httpcode >= 400 || $errno !== 0) {
                mtrace("DEBUG: Error Response: " . substr($response, 0, 1000));
            }
            mtrace("------------------------------------------------");
        }

        // 1. Connection Errors (DNS, Timeout).
        if ($errno !== 0) {
            $error = $curl->error;
            throw new \moodle_exception(
                'curlerror',
                'plagiarism_inspera',
                '',
                null,
                "Curl connection error ({$errno}) accessing {$url}: {$error}"
            );
        }

        // 2. API Logic Errors (401 Unauthorized, 403 Forbidden, 500 Server Error).
        if ($httpcode >= 400) {
            throw new \moodle_exception(
                'apierror',
                'plagiarism_inspera',
                '',
                null,
                "API returned HTTP {$httpcode}: " . $response
            );
        }

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
    protected function do_get_request(string $url, array $headers = []): string {
        // Pass 'ignoresecurity' to the CONSTRUCTOR.
        $curl = new \curl(['ignoresecurity' => true, 'timeout' => 60]);
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }

        $response = $curl->get($url);

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        $errno = $curl->get_errno();

        if ($errno !== 0) {
            $error = $curl->error;
            throw new \moodle_exception(
                'curlerror',
                'plagiarism_inspera',
                '',
                null,
                "Curl error ({$errno}) accessing {$url}: {$error}"
            );
        }

        if ($httpcode >= 400) {
            throw new \moodle_exception(
                'apierror',
                'plagiarism_inspera',
                '',
                null,
                "API returned HTTP {$httpcode} on GET: " . $response
            );
        }

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
    protected function do_s3_put_request(string $url, string $content, string $mimetype): bool {
        // Pass 'ignoresecurity' to the CONSTRUCTOR.
        $curl = new \curl(['ignoresecurity' => true, 'timeout' => 300]);
        $curl->setHeader('Content-Type: ' . $mimetype);

        if (!defined('PHPUNIT_TEST')) {
            mtrace("Uploading file to Originality S3 URL");
        }

        $curl->put($url, $content);
        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        $errno = $curl->get_errno();

        if ($errno !== 0) {
            $error = $curl->error;
            if (!defined('PHPUNIT_TEST')) {
                mtrace("Upload failed (Curl {$errno}): " . $error);
            }
            return false;
        }

        // S3 returns 200 or 201 on success. 400+ is a failure.
        if ($httpcode >= 400) {
            if (!defined('PHPUNIT_TEST')) {
                mtrace("Upload failed (HTTP {$httpcode})");
            }
            return false;
        }

        return true;
    }

    // API Logic Methods.

    /**
     * Retrieve and cache an API authentication token.
     *
     * @return string The API token.
     * @throws \moodle_exception If fetching or parsing the token fails.
     */
    private function get_token(): string {

        // Generate a signature of the current credentials.
        // If the admin changes the ClientID/InstID, this hash changes, invalidating the old cache.
        $currentcredhash = md5($this->clientid . '|' . $this->institutionid);

        // 1. CACHE CHECK
        if (!$this->isvalidating) {
            $token = get_config('plagiarism_inspera', 'apitoken');
            $expires = (int) get_config('plagiarism_inspera', 'apitoken_exp');
            $cachedhash = get_config('plagiarism_inspera', 'apitoken_hash');

            // Logic: Token must be valid, not expiring soon, AND belong to these credentials.
            if (
                !empty($token) &&
                $expires > (time() + 60) &&
                $cachedhash === $currentcredhash
            ) {
                return $token;
            }
        }

        // 2. FETCH NEW TOKEN.
        $payload = json_encode([
            'clientId'      => $this->clientid,
            'institutionId' => $this->institutionid,
        ]);

        try {
            $response = $this->do_post_request($this->baseurl . '/token', $payload);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception(
                'apitokenerror',
                'plagiarism_inspera',
                '',
                null,
                'Failed to connect to token endpoint: ' . $e->getMessage()
            );
        }

        $data = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'invalidjson',
                'plagiarism_inspera',
                '',
                null,
                'Token response not valid JSON: ' . $response
            );
        }

        if (empty($data->token)) {
            // Extract readable message if available.
            $apimessage = $data->message ?? 'Unknown error';
            throw new \moodle_exception('apitokenerror', 'plagiarism_inspera', '', $apimessage);
        }

        // 3. SAVE CACHE (Only if not validating).
        if (!$this->isvalidating && isset($data->expirationTime)) {
            set_config('apitoken', $data->token, 'plagiarism_inspera');
            set_config('apitoken_exp', floor($data->expirationTime / 1000), 'plagiarism_inspera');

            // Save the hash of the credentials that generated this token.
            set_config('apitoken_hash', $currentcredhash, 'plagiarism_inspera');
        }

        return $data->token;
    }

    /**
     * Create a submission (metadata only) via the API.
     *
     * @param \stdClass $metadata Object containing: title, author, email, doctype, assignmentid
     * @param array $settings Activity-level plugin settings.
     * @param array $educators List of teachers/educators.
     * @param array $students List of students (for Group Submissions).
     * @return \stdClass API response object containing documentId and presignedS3Url.
     * @throws \moodle_exception If the API call fails or returns invalid data.
     */
    public function create_submission(
        \stdClass $metadata,
        array $settings = [],
        array $educators = [],
        array $students = []
    ): \stdClass {
        $token = $this->get_token();
        $headers = ['Authorization: Bearer ' . $token];

        // Build the payload extracting data from the $metadata object.
        $payload = [
            'documentTitle' => $metadata->title,
            'author'        => $metadata->author,
            'email'         => $metadata->email,
            'docType'       => $metadata->doctype,
            'assignmentId'  => $metadata->assignmentid,
            // Set to true if students array is provided (Group Submission), otherwise false.
            'teamSubmission'     => !empty($students),
        ];

        // 1. Process Educators.
        if (!empty($educators) && is_array($educators)) {
            $normalizededucators = [];
            foreach ($educators as $ed) {
                if (!is_array($ed)) {
                    continue;
                }
                $id = isset($ed['id']) ? (string)$ed['id'] : null;
                $name = $ed['name'] ?? null;
                $mail = $ed['email'] ?? null;
                if ($id !== null && !empty($name) && !empty($mail)) {
                    $normalizededucators[] = ['id' => $id, 'name' => $name, 'email' => $mail];
                }
            }
            if (!empty($normalizededucators)) {
                $payload['educators'] = $normalizededucators;
            }
        }

        // 2. Process Students (Group Submission).
        if (!empty($students) && is_array($students)) {
            $normalizedstudents = [];
            foreach ($students as $st) {
                if (!is_array($st)) {
                    continue;
                }

                // Extract fields (handling potential integer IDs by casting to string).
                $id = isset($st['id']) ? (string)$st['id'] : null;
                $name = $st['name'] ?? null;
                $mail = $st['email'] ?? null;

                // Only add if we have the required fields.
                if ($id !== null && !empty($name) && !empty($mail)) {
                    $normalizedstudents[] = ['id' => $id, 'name' => $name, 'email' => $mail];
                }
            }

            // Add to payload if valid students exist.
            if (!empty($normalizedstudents)) {
                $payload['students'] = $normalizedstudents;
            }
        }

        // 3. Settings & Flags.
        if (!empty($settings['anonymous_submissions'])) {
            $payload['anonymous_submissions'] = true;
        }

        if (!empty($this->institutionid)) {
            $payload['institutionName'] = $this->institutionid;
        }
        if (!empty($settings['originality_metadata_analysis'])) {
            $payload['metadataAnalysis'] = (bool)$settings['originality_metadata_analysis'];
        }
        if (!empty($settings['originality_archive'])) {
            $payload['archive'] = (bool)$settings['originality_archive'];
        }
        if (!empty($settings['originality_enable_ai'])) {
            $payload['enableAIDetection'] = (bool)$settings['originality_enable_ai'];
        }

        // Exclude Citations (Uses isset to explicitly send true or false).
        if (isset($settings['originality_excludecitations'])) {
            $payload['excludeCitations'] = (bool)$settings['originality_excludecitations'];
        }

        if (!empty($settings['originality_enable_translations'])) {
            $payload['translationsEnabled'] = true;
            // Clean up array structure for API.
            $payload['translatedLanguage'] = array_values(
                array_filter(
                    explode(',', $settings['originality_translation_languages'] ?? '')
                )
            );
        }
        $enablecontext = !empty($settings['originality_enable_context_similarity']);
        $payload['enableContextSimilarity'] = $enablecontext;
        if ($enablecontext) {
            $payload['sentenceThresholds'] = [
                'contextualSimilaritiesThreshold' => (int)($settings['originality_context_threshold'] ?? 50),
            ];
        }

        // Exclude Source Threshold.
        if (!empty($settings['originality_enable_exclude_source_criteria'])) {
            if (isset($settings['originality_exclude_source_threshold'])) {
                $payload['sourcesThreshold'] = (int)$settings['originality_exclude_source_threshold'];
            }
        }

        // Whitelist Characters.
        if (
            !empty(
                $settings['originality_enable_whitelist_characters']
            ) &&
            !empty(
                trim($settings['originality_whitelist_characters'] ?? '')
            )
        ) {
            $payload['whitelistCharacters'] = array_values(
                array_filter(
                    array_map('trim', explode(',', $settings['originality_whitelist_characters']))
                )
            );
        }

        // 4. Sources (Include/Exclude).
        $includesources = [];
        $excludesources = [];
        if (!empty($settings['originality_enable_include_urls']) && !empty(trim($settings['originality_include_urls']))) {
            $includesources = array_values(
                array_filter(
                    array_map('trim', explode(',', $settings['originality_include_urls']))
                )
            );
        }
        if (!empty($settings['originality_enable_exclude_urls']) && !empty(trim($settings['originality_exclude_urls']))) {
            $excludesources = array_values(
                array_filter(
                    array_map('trim', explode(',', $settings['originality_exclude_urls']))
                )
            );
        }
        if (!empty($includesources) || !empty($excludesources)) {
            $payload['sources'] = [];
            if (!empty($excludesources)) {
                $payload['sources']['excludeSources'] = $excludesources;
            }
            if (!empty($includesources)) {
                $payload['sources']['includeSources'] = $includesources;
            }
        }

        try {
            $response = $this->do_post_request($this->baseurl . '/create/submission', json_encode($payload), $headers);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception(
                'apierror',
                'plagiarism_inspera',
                '',
                null,
                'Failed to create submission: ' . $e->getMessage()
            );
        }

        $data = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'invalidjson',
                'plagiarism_inspera',
                '',
                null,
                'Create submission response not valid JSON: ' . $response
            );
        }
        if (empty($data->documentId) || empty($data->presignedS3Url)) {
            if (!defined('PHPUNIT_TEST')) {
                mtrace("API create_submission failed: " . $response);
            }
            // Extract readable message if possible.
            $apimessage = $data->message ?? 'API response missing documentId or presignedS3Url';
            throw new \moodle_exception('apierror', 'plagiarism_inspera', '', null, $apimessage);
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
        return $this->do_s3_put_request($url, $content, $mimetype);
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
            $response = $this->do_get_request($url, $headers);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception(
                'apierror',
                'plagiarism_inspera',
                '',
                null,
                'Failed to check document status: ' . $e->getMessage()
            );
        }

        $data = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'invalidjson',
                'plagiarism_inspera',
                '',
                null,
                'Check status response not valid JSON: ' . $response
            );
        }

        if (!defined('PHPUNIT_TEST')) {
            mtrace("Polled status for doc {$documentid}: " . $response);
        }

        return $data;
    }

    /**
     * Gets a temporary URL for the report, specifying the view mode.
     *
     * @param string $documentid The external document ID.
     * @param string $mode 'view' for students, 'edit' for teachers/admins.
     * @return \stdClass API response object.
     */
    public function get_report_url(string $documentid, string $mode = 'view'): \stdClass {
        // Security: Whitelist allowed modes.
        if ($mode !== 'edit') {
            $mode = 'view';
        }

        $token = $this->get_token();
        $headers = ['Authorization: Bearer ' . $token];
        $url = $this->baseurl . '/document/' . $documentid . '/mode/' . $mode;

        try {
            $response = $this->do_get_request($url, $headers);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception(
                'apierror',
                'plagiarism_inspera',
                '',
                null,
                'Failed to get report URL: ' . $e->getMessage()
            );
        }

        $data = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'invalidjson',
                'plagiarism_inspera',
                '',
                null,
                'Report URL response not valid JSON: ' . $response
            );
        }
        if (empty($data->url)) {
            $apimessage = $data->message ?? 'API response missing report URL';
            throw new \moodle_exception('apierror', 'plagiarism_inspera', '', null, $apimessage);
        }

        return $data;
    }
}
