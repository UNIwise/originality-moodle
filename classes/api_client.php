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
 * API client for the external originality plagiarism service.
 *
 * Handles OAuth2 client-credentials token retrieval and file submission.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality;

/**
 * Client for communicating with the external plagiarism checking service.
 */
class api_client {
    /** @var string Base URL of the external API. */
    private string $apiurl;

    /** @var string OAuth2 client ID. */
    private string $clientid;

    /** @var string OAuth2 client secret. */
    private string $clientsecret;

    /** @var string|null Cached access token. */
    private ?string $accesstoken = null;

    /** @var int Token expiry timestamp. */
    private int $tokenexpiry = 0;

    /**
     * Create the API client from plugin config.
     *
     * @return self
     * @throws \moodle_exception If required config is missing.
     */
    public static function create(): self {
        $config = get_config('plagiarism_originality');
        $apiurl = $config->originality_api_url ?? '';
        $clientid = $config->originality_client_id ?? '';
        $clientsecret = $config->originality_client_secret ?? '';

        if (empty($apiurl) || empty($clientid) || empty($clientsecret)) {
            throw new \moodle_exception('missingconfig', 'plagiarism_originality');
        }

        return new self($apiurl, $clientid, $clientsecret);
    }

    /**
     * Constructor.
     *
     * @param string $apiurl Base URL of the API.
     * @param string $clientid OAuth2 client ID.
     * @param string $clientsecret OAuth2 client secret.
     */
    public function __construct(string $apiurl, string $clientid, string $clientsecret) {
        // Normalize API URL: strip trailing slash.
        $this->apiurl = rtrim($apiurl, '/');
        $this->clientid = $clientid;
        $this->clientsecret = $clientsecret;
    }

    /**
     * Obtain an access token using the OAuth2 client-credentials grant.
     *
     * Tokens are cached persistently via plugin config and reused across requests
     * until they expire (with a 30-second safety margin).
     *
     * @return string The bearer access token.
     * @throws \moodle_exception On authentication failure.
     */
    public function get_access_token(): string {
        // Check in-memory cache first.
        if ($this->accesstoken !== null && time() < ($this->tokenexpiry - 30)) {
            return $this->accesstoken;
        }

        // Check persistent cache (survives across requests).
        $cachedtoken = get_config('plagiarism_originality', 'cached_access_token');
        $cachedexpiry = (int) get_config('plagiarism_originality', 'cached_token_expiry');
        if (!empty($cachedtoken) && time() < ($cachedexpiry - 30)) {
            $this->accesstoken = $cachedtoken;
            $this->tokenexpiry = $cachedexpiry;
            return $this->accesstoken;
        }

        $tokenurl = $this->apiurl . '/v1/oauth/token';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $tokenurl,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERPWD        => $this->clientid . ':' . $this->clientsecret,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        ]);

        $response = curl_exec($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if (!empty($curlerror)) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', 'cURL error: ' . $curlerror);
        }

        $data = json_decode($response, true);

        if ($httpcode !== 200 || empty($data['access_token'])) {
            $error = $data['error_description'] ?? $data['error'] ?? $data['message'] ?? $response;
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', $error);
        }

        $this->accesstoken = $data['access_token'];
        $this->tokenexpiry = time() + ($data['expires_in'] ?? 3600);

        // Persist token across requests.
        set_config('cached_access_token', $this->accesstoken, 'plagiarism_originality');
        set_config('cached_token_expiry', $this->tokenexpiry, 'plagiarism_originality');

        return $this->accesstoken;
    }

    /**
     * Submit a file to the external plagiarism service.
     *
     * @param \stored_file $file The Moodle stored file to submit.
     * @param int $cmid The course module ID for context.
     * @param int $userid The user who submitted the file.
     * @return array The decoded JSON response from the service, expected to contain at least 'id'.
     * @throws \moodle_exception On submission failure.
     */
    public function submit_file(\stored_file $file, int $cmid, int $userid): array {
        $token = $this->get_access_token();
        $submiturl = $this->apiurl . '/v1/documents';

        // Resolve course ID from the course module.
        $cm = get_coursemodule_from_id('', $cmid);
        $courseid = $cm ? (int) $cm->course : 0;

        // Use native curl to ensure multipart form-data is sent correctly
        // and redirects are followed properly (Moodle's curl wrapper blocks FOLLOWLOCATION).
        $tmppath = make_request_directory() . '/' . $file->get_filename();
        $file->copy_content_to($tmppath);

        $context = json_encode([
            'moodle_course_id' => (string) $courseid,
            'moodle_cm_id'     => (string) $cmid,
            'moodle_user_id'   => (string) $userid,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $submiturl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'file'    => new \CURLFile($tmppath, $file->get_mimetype(), $file->get_filename()),
                'index'   => 'false',
                'analyze' => 'true',
                'context' => $context,
            ],
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        @unlink($tmppath);

        if (!empty($curlerror)) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', 'cURL error: ' . $curlerror);
        }

        $data = json_decode($response, true);

        if ($httpcode < 200 || $httpcode >= 300 || $data === null) {
            $error = $this->extract_api_error($data, $response);
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', $error);
        }

        return $data;
    }

    /**
     * Submit text content to the external plagiarism service.
     *
     * @param string $content The text content to check.
     * @param int $cmid The course module ID.
     * @param int $userid The user who submitted the content.
     * @return array The decoded JSON response.
     * @throws \moodle_exception On submission failure.
     */
    public function submit_text(string $content, int $cmid, int $userid): array {
        $token = $this->get_access_token();
        $submiturl = $this->apiurl . '/v1/documents';

        // Resolve course ID from the course module.
        $cm = get_coursemodule_from_id('', $cmid);
        $courseid = $cm ? (int) $cm->course : 0;

        $tmppath = make_request_directory() . '/onlinetext_' . $userid . '_' . $cmid . '.txt';
        file_put_contents($tmppath, $content);

        $context = json_encode([
            'moodle_course_id' => (string) $courseid,
            'moodle_cm_id'     => (string) $cmid,
            'moodle_user_id'   => (string) $userid,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $submiturl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'file'    => new \CURLFile($tmppath, 'text/plain', 'onlinetext.txt'),
                'index'   => 'false',
                'analyze' => 'true',
                'context' => $context,
            ],
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        @unlink($tmppath);

        if (!empty($curlerror)) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', 'cURL error: ' . $curlerror);
        }

        $data = json_decode($response, true);

        if ($httpcode < 200 || $httpcode >= 300 || $data === null) {
            $error = $this->extract_api_error($data, $response);
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', $error);
        }

        return $data;
    }

    /**
     * Search documents by context filter (e.g. course module ID).
     *
     * Returns all documents matching the given context key/value pair.
     *
     * @param string $contextkey The context key to filter by (e.g. 'moodle_cm_id').
     * @param string $contextvalue The value to match.
     * @return array The decoded JSON response (array of documents).
     * @throws \moodle_exception On request failure.
     */
    public function search_documents(string $contextkey, string $contextvalue): array {
        $token = $this->get_access_token();
        $searchurl = $this->apiurl . '/v1/documents/search';

        $body = json_encode([
            'context' => [
                $contextkey => $contextvalue,
            ],
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $searchurl,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => $body,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpcode < 200 || $httpcode >= 300 || $data === null) {
            $error = $this->extract_api_error($data, $response);
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', $error);
        }

        return $data;
    }

    /**
     * Poll the external service for the status/result of a submission.
     *
     * @param string $externalid The external submission ID.
     * @return array The decoded JSON response with status, score, report_url, etc.
     * @throws \moodle_exception On request failure.
     */
    public function get_submission_status(string $externalid): array {
        $token = $this->get_access_token();
        $statusurl = $this->apiurl . '/v1/documents/' . urlencode($externalid);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $statusurl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpcode < 200 || $httpcode >= 300 || $data === null) {
            $error = $this->extract_api_error($data, $response);
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', $error);
        }

        return $data;
    }

    /**
     * Delete a document from the external plagiarism service.
     *
     * @param string $externalid The external document ID.
     * @throws \moodle_exception On request failure.
     */
    public function delete_document(string $externalid): void {
        $token = $this->get_access_token();
        $deleteurl = $this->apiurl . '/v1/documents/' . urlencode($externalid);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $deleteurl,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 200, 204, 404 are all acceptable (404 means already gone).
        if ($httpcode >= 300 && $httpcode !== 404) {
            $data = json_decode($response, true);
            $error = $this->extract_api_error($data, $response);
            throw new \moodle_exception('apierror', 'plagiarism_originality', '', $error);
        }
    }

    /**
     * Extract a human-readable error message from a Wiseflow API error response.
     *
     * @param array|null $data Decoded JSON response, if available.
     * @param string $rawresponse Raw response body as fallback.
     * @return string
     */
    private function extract_api_error(?array $data, string $rawresponse): string {
        if (empty($data)) {
            return $rawresponse;
        }

        $parts = [];
        if (!empty($data['title'])) {
            $parts[] = $data['title'];
        }
        if (!empty($data['detail'])) {
            $parts[] = $data['detail'];
        }
        if (!empty($data['errorDetails']) && is_array($data['errorDetails'])) {
            foreach ($data['errorDetails'] as $detail) {
                $msg = $detail['message'] ?? '';
                $loc = $detail['location'] ?? '';
                $val = isset($detail['value']) ? json_encode($detail['value']) : '';
                $parts[] = trim("$loc: $msg ($val)");
            }
        }

        return !empty($parts) ? implode(' | ', $parts) : $rawresponse;
    }
}
