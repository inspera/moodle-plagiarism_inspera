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

namespace plagiarism_originality\apiclient;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

class api_client {
    private $baseurl;
    private $clientid;
    private $institutionid;

    public function __construct() {
        $this->baseurl       = rtrim(get_config('plagiarism_originality', 'baseurl'), '/');
        $this->clientid      = get_config('plagiarism_originality', 'clientid');
        $this->institutionid = get_config('plagiarism_originality', 'institutionid');
    }

    /**
     * Retrieve and cache a token.
     *
     * @return string
     * @throws \moodle_exception
     */
    private function get_token(): string {
        // Check cached token.
        $cached  = get_config('plagiarism_originality', 'apitoken');
        $expires = get_config('plagiarism_originality', 'apitoken_exp');

        if ($cached && $expires > (time() + 60)) {
            return $cached;
        }

        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');

        $payload = json_encode([
            'clientId'      => $this->clientid,
            'institutionId' => $this->institutionid
        ]);

        $response = $curl->post($this->baseurl . '/token', $payload);
        $data     = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality',
                '', null, 'Token response not JSON: ' . $response);
        }

        if (empty($data->token)) {
            throw new \moodle_exception('apitokenerror', 'plagiarism_originality',
                '', null, 'Failed to get token: ' . $response);
        }

        // Cache token + expiration (convert ms → seconds).
        set_config('apitoken', $data->token, 'plagiarism_originality');
        set_config('apitoken_exp', floor($data->expirationTime / 1000), 'plagiarism_originality');

        return $data->token;
    }

    /**
     * Create a submission (metadata only).
     */
    public function create_submission(
        string $title,
        string $author,
        string $email,
        string $doctype,
        string $assignmentId,
        array $settings = []
    ) {
        $token = $this->get_token();

        $curl = new \curl();
        $curl->setHeader('Authorization: Bearer ' . $token);
        $curl->setHeader('Content-Type: application/json');

        $payload = [
            'documentTitle' => $title,
            'author'        => $author,
            'email'         => $email,
            'docType'       => $doctype,
            'assignmentId'  => $assignmentId,
        ];

        // 🔸 Add institution name/id from plugin configuration.
        $institutionid = get_config('plagiarism_originality', 'institutionid');
        if (!empty($institutionid)) {
            $payload['institutionName'] = $institutionid;
        }

        // Include additional originality settings if available.
        if (!empty($settings['originality_metadata_analysis'])) {
            $payload['metadataAnalysis'] = (bool)$settings['originality_metadata_analysis'];
        }
        if (!empty($settings['originality_archive'])) {
            $payload['archive'] = (bool)$settings['originality_archive'];
        }
        if (!empty($settings['originality_enable_ai'])) {
            $payload['enableAIDetection'] = (bool)$settings['originality_enable_ai'];
        }
        if (!empty($settings['originality_enable_translations'])) {
            $payload['translationsEnabled'] = true;
            $payload['translationLanguages'] = array_filter(
                explode(',', $settings['originality_translations_languages'] ?? '')
            );
        }

        $enablecontextsimilarity = !empty($settings['originality_enable_context_similarity']);
        $payload['enableContextSimilarity'] = $enablecontextsimilarity;
        if ($enablecontextsimilarity) {
            $payload['sentenceThresholds'] = [
                'contextualSimilaritiesThreshold' => (int)$settings['originality_context_threshold']
            ];
        }

        $includesources = [];
        $excludesources = [];

        if (!empty($settings['originality_enable_include_urls'])) {
            $urls = trim($settings['originality_include_urls']);
            if (!empty($urls)) {
                $includesources = array_map('trim', explode(',', $urls));
            }
        }

        if (!empty($settings['originality_enable_exclude_urls'])) {
            $urls = trim($settings['originality_exclude_urls']);
            if (!empty($urls)) {
                $excludesources = array_map('trim', explode(',', $urls));
            }
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

        $response = $curl->post($this->baseurl . '/create/submission', json_encode($payload));
        $data     = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'invalidjson',
                'plagiarism_originality',
                '',
                null,
                'Create submission response not JSON: ' . $response
            );
        }

        if (empty($data->documentId) || empty($data->presignedS3Url)) {
            mtrace("API create_submission failed: " . $response);
        }

        return $data;
    }



    /**
     * Upload the file to presigned S3 URL.
     */
    public function upload_to_presigned_url(string $url, string $content, string $mimetype): bool {
        $curl = new \curl();
        $curl->setHeader('Content-Type: ' . $mimetype);

        mtrace("Uploading file to Originality");
        // Moodle's curl doesn't return much here, but you can capture errors.
        $response = $curl->put($url, $content);

        if ($curl->get_errno()) {
            mtrace("Upload failed: " . $curl->error);
            return false;
        }

        return true;
    }

    /**
     * Poll document status.
     */
    public function check_document_status(string $documentid) {
        $token = $this->get_token();

        $curl = new \curl();
        $curl->setHeader('Authorization: Bearer ' . $token);

        $url      = $this->baseurl . '/document/' . $documentid . '/checkStatus';
        $response = $curl->get($url);
        $data     = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality',
                '', null, 'Check status response not JSON: ' . $response);
        }

        mtrace("Polled status for doc {$documentid}: " . $response);

        return $data;
    }

    public function get_report_url(string $documentId) {
        $token = $this->get_token();

        $curl = new \curl();
        $curl->setHeader('Authorization: Bearer ' . $token);
        $curl->setHeader('Content-Type: application/json');

        $url = $this->baseurl . '/document/' . $documentId . '/mode/view';
        $response = $curl->get($url); // <-- correct

        $data = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'plagiarism_originality',
                '', null, 'Report URL response not JSON: ' . $response);
        }

        return $data; // expects $data->url
    }


}
