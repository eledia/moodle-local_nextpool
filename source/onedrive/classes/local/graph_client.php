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

namespace nextpoolsource_onedrive\local;

use local_nextpool\local\source\source_exception;

/**
 * Narrow Microsoft Graph v1.0 client for drives, signed in as an app (adr20, TD-202).
 *
 * The app gets its token with the client credentials grant and only reaches the
 * SharePoint sites and personal OneDrives the customer IT shared with it
 * (Sites.Selected). Tokens are cached per set of credentials until shortly before
 * they expire; a 401 fetches a new one once. A 429 or 503 stores the wait time from
 * Retry-After, and every call fails fast until then, so polling skips the location.
 * Content always moves through files: downloads follow the pre-authenticated
 * redirect without the token, uploads above 4 MiB use an upload session.
 *
 * Requests go through Moodle's curl class, so proxy and blocked-host settings apply.
 * Tests replace {@see http()} and nothing else.
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class graph_client {
    /** @var string Graph base URL. */
    public const GRAPH = 'https://graph.microsoft.com/v1.0';
    /** @var string Microsoft identity platform. */
    public const LOGIN = 'https://login.microsoftonline.com';
    /** @var int Largest file uploaded with a single PUT. */
    public const SIMPLE_UPLOAD_LIMIT = 4 * 1024 * 1024;
    /** @var int Seconds before expiry a cached token is no longer used. */
    private const TOKEN_MARGIN = 300;
    /** @var int Wait time if Microsoft throttles without saying how long. */
    private const DEFAULT_BACKOFF = 60;
    /** @var int Attempts per upload chunk. */
    private const CHUNK_ATTEMPTS = 3;

    /** @var int Upload session chunk size; a multiple of 320 KiB as Graph requires (32 × 320 KiB = 10 MiB). */
    protected int $chunksize = 32 * 320 * 1024;

    /** @var string|null Token of this request. */
    private ?string $token = null;

    /**
     * Constructor.
     *
     * @param \curl $curl Transport.
     * @param string $tenantid Directory (tenant) id or primary domain.
     * @param string $clientid Application (client) id.
     * @param string $clientsecret Client secret; never part of an error or log message.
     */
    public function __construct(
        /** @var \curl Transport. */
        protected readonly \curl $curl,
        /** @var string Tenant. */
        protected readonly string $tenantid,
        /** @var string Application id. */
        protected readonly string $clientid,
        /** @var string Client secret. */
        private readonly string $clientsecret,
    ) {
    }

    /**
     * A Graph resource as decoded JSON.
     *
     * @param string $path Path below {@see GRAPH}, starting with a slash, including the query.
     * @return array|null Null on 404.
     * @throws source_exception On other errors.
     */
    public function get(string $path): ?array {
        $response = $this->api('GET', self::GRAPH . $path);
        if ($response['status'] === 404) {
            return null;
        }
        $this->assert_status($response, [200]);
        return self::decode($response['body']);
    }

    /**
     * All items of a collection, following @odata.nextLink.
     *
     * @param string $path Path below {@see GRAPH}, starting with a slash, including the query.
     * @return array[] The items of 'value'.
     * @throws source_exception On errors, including 404.
     */
    public function get_all(string $path): array {
        $items = [];
        $url = self::GRAPH . $path;
        while ($url !== '') {
            $response = $this->api('GET', $url);
            $this->assert_status($response, [200]);
            $page = self::decode($response['body']);
            array_push($items, ...array_values((array) ($page['value'] ?? [])));
            $url = (string) ($page['@odata.nextLink'] ?? '');
            // Never send the token anywhere but to Graph.
            if ($url !== '' && !str_starts_with($url, self::GRAPH . '/')) {
                throw new source_exception('error_sourcehttp', 'nextLink');
            }
        }
        return $items;
    }

    /**
     * Download the content of a drive item into a local file.
     *
     * Graph answers /content with a redirect to a pre-authenticated URL; it is followed without the token.
     *
     * @param string $itempath Item path below {@see GRAPH}, e.g. /drives/{id}/root:/a.pdf: (without /content).
     * @param string $targetfile Absolute local path.
     * @throws source_exception On errors.
     */
    public function download(string $itempath, string $targetfile): void {
        $response = $this->api('GET', self::GRAPH . $itempath . '/content', [], null, null, $targetfile);
        if ($response['status'] === 200) {
            return;
        }
        if (!in_array($response['status'], [301, 302, 303, 307], true)) {
            $this->assert_status($response, [200]);
        }
        $location = (string) ($response['headers']['location'] ?? '');
        if (!preg_match('#^https://[^/]+/#i', $location)) {
            throw new source_exception('error_sourcehttp', $response['status']);
        }
        $response = $this->http('GET', $location, [], null, null, $targetfile);
        $this->assert_status($response, [200]);
    }

    /**
     * Upload a local file to a drive path, replacing an existing file and creating missing folders.
     *
     * @param string $itempath Item path below {@see GRAPH}, e.g. /drives/{id}/root:/Kurse/a.pdf:.
     * @param string $sourcefile Absolute local path.
     * @return array The written drive item.
     * @throws source_exception On errors.
     */
    public function upload(string $itempath, string $sourcefile): array {
        $size = (int) filesize($sourcefile);
        if ($size <= self::SIMPLE_UPLOAD_LIMIT) {
            $response = $this->api('PUT', self::GRAPH . $itempath . '/content', [
                'Content-Type' => 'application/octet-stream',
            ], null, $sourcefile);
            $this->assert_status($response, [200, 201]);
            return self::decode($response['body']);
        }

        $response = $this->api('POST', self::GRAPH . $itempath . '/createUploadSession', [
            'Content-Type' => 'application/json',
        ], json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]));
        $this->assert_status($response, [200]);
        $uploadurl = (string) (self::decode($response['body'])['uploadUrl'] ?? '');
        if (!preg_match('#^https://[^/]+/#i', $uploadurl)) {
            throw new source_exception('error_sourcehttp', $response['status']);
        }
        try {
            return $this->upload_chunks($uploadurl, $sourcefile, $size);
        } catch (\Throwable $e) {
            // Release the incomplete upload on Microsoft's side.
            $this->http('DELETE', $uploadurl);
            throw $e;
        }
    }

    /**
     * Delete a drive item (M06 direct upload, TD-405).
     *
     * An item that does not exist counts as deleted.
     *
     * @param string $itempath Item path below {@see GRAPH}, e.g. /drives/{id}/items/{itemid}.
     * @throws source_exception On other errors.
     */
    public function delete(string $itempath): void {
        $response = $this->api('DELETE', self::GRAPH . $itempath);
        $this->assert_status($response, [204, 404]);
    }

    /**
     * Send a file in chunks to an upload session, resuming at the range Graph expects next.
     *
     * The session URL is pre-authenticated, so the chunks go without the token.
     *
     * @param string $uploadurl Upload session URL.
     * @param string $sourcefile Absolute local path.
     * @param int $size File size in bytes.
     * @return array The written drive item.
     * @throws source_exception On errors.
     */
    protected function upload_chunks(string $uploadurl, string $sourcefile, int $size): array {
        $chunkfile = make_request_directory() . '/chunk';
        $offset = 0;
        $attempts = 0;
        while (true) {
            $length = min($this->chunksize, $size - $offset);
            $in = fopen($sourcefile, 'rb');
            $out = fopen($chunkfile, 'wb');
            stream_copy_to_stream($in, $out, $length, $offset);
            fclose($in);
            fclose($out);
            $response = $this->http('PUT', $uploadurl, [
                'Content-Range' => 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size,
            ], null, $chunkfile);
            if (in_array($response['status'], [200, 201], true)) {
                return self::decode($response['body']);
            }
            if ($response['status'] === 202) {
                $offset = self::next_expected_offset(self::decode($response['body']), $offset + $length);
                $attempts = 0;
                continue;
            }
            if (++$attempts >= self::CHUNK_ATTEMPTS || ($response['status'] >= 400 && $response['status'] < 500)) {
                $this->assert_status($response, [200, 201, 202]);
            }
            // Transport error or server error: ask the session where to go on.
            $status = $this->http('GET', $uploadurl);
            if ($status['status'] === 200) {
                $offset = self::next_expected_offset(self::decode($status['body']), $offset);
            }
        }
    }

    /**
     * Start of the first range an upload session expects next.
     *
     * @param array $session Upload session JSON.
     * @param int $default Offset if the session does not say.
     * @return int
     */
    private static function next_expected_offset(array $session, int $default): int {
        $range = (string) (($session['nextExpectedRanges'] ?? [])[0] ?? '');
        return preg_match('/^(\d+)-/', $range, $m) ? (int) $m[1] : $default;
    }

    /**
     * Check the app credentials by fetching a new token.
     *
     * @throws source_exception If the tenant or the credentials are rejected.
     */
    public function check_credentials(): void {
        $this->token = null;
        $this->get_token(true);
    }

    /**
     * Cache key of these credentials; changes when a new secret is entered (F03O.AC08).
     *
     * @return string
     */
    protected function credentials_key(): string {
        return sha1($this->tenantid . '|' . $this->clientid . '|' . $this->clientsecret);
    }

    /**
     * Cache key of the app in this tenant, for throttling (Microsoft throttles per app and tenant).
     *
     * @return string
     */
    protected function app_key(): string {
        return sha1($this->tenantid . '|' . $this->clientid);
    }

    /**
     * Seconds left until throttling ends, 0 if not throttled.
     *
     * @return int
     */
    public function get_backoff(): int {
        $until = (int) \cache::make('nextpoolsource_onedrive', 'backoff')->get($this->app_key());
        return max(0, $until - time());
    }

    /**
     * An access token for Graph, from the cache or the identity platform.
     *
     * @param bool $refresh Ignore the cached token.
     * @return string
     * @throws source_exception If the tenant or the credentials are rejected.
     */
    protected function get_token(bool $refresh = false): string {
        $cache = \cache::make('nextpoolsource_onedrive', 'tokens');
        $key = $this->credentials_key();
        if (!$refresh && $this->token !== null) {
            return $this->token;
        }
        $cached = $refresh ? false : $cache->get($key);
        if (
            is_array($cached) && isset($cached['encryptedtoken'])
                && (int) ($cached['expires'] ?? 0) > time() + self::TOKEN_MARGIN
        ) {
            try {
                return $this->token = \core\encryption::decrypt($cached['encryptedtoken']);
            } catch (\moodle_exception $e) {
                // A rotated key or stale cache must not break the storage location; obtain a fresh token below.
                $cache->delete($key);
            }
        }
        $response = $this->http('POST', self::LOGIN . '/' . rawurlencode($this->tenantid) . '/oauth2/v2.0/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $this->clientid,
            'client_secret' => $this->clientsecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&'));
        $body = self::decode($response['body']);
        if ($response['status'] === 200 && !empty($body['access_token'])) {
            $this->token = (string) $body['access_token'];
            $cache->set($key, ['encryptedtoken' => \core\encryption::encrypt($this->token),
                'expires' => time() + (int) ($body['expires_in'] ?? 3599)]);
            return $this->token;
        }
        $cache->delete($key);
        if ($response['status'] === 0) {
            throw new source_exception('error_sourceunavailable');
        }
        $this->check_throttling($response);
        // AADSTS90002/900023: tenant unknown or malformed; anything else (wrong id, wrong or expired secret)
        // concerns the app credentials.
        if (preg_match('/AADSTS(90002|900023)\b/', (string) ($body['error_description'] ?? ''))) {
            throw new source_exception('error_tenant', null, 'nextpoolsource_onedrive');
        }
        throw new source_exception('error_credentials', null, 'nextpoolsource_onedrive');
    }

    /**
     * Send an authenticated Graph request; fetches a new token once on 401.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute Graph URL.
     * @param array $headers Extra headers.
     * @param string|null $body Request body.
     * @param string|null $infile Local file to send as body.
     * @param string|null $outfile Local file to write the body into.
     * @return array{status:int,body:string,headers:array<string,string>}
     * @throws source_exception While throttled.
     */
    protected function api(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $infile = null,
        ?string $outfile = null
    ): array {
        $wait = $this->get_backoff();
        if ($wait > 0) {
            throw new source_exception('error_throttled', $wait, 'nextpoolsource_onedrive');
        }
        $auth = ['Authorization' => 'Bearer ' . $this->get_token()];
        $response = $this->http($method, $url, $auth + $headers, $body, $infile, $outfile);
        if ($response['status'] === 401) {
            $auth = ['Authorization' => 'Bearer ' . $this->get_token(true)];
            $response = $this->http($method, $url, $auth + $headers, $body, $infile, $outfile);
        }
        $this->check_throttling($response);
        return $response;
    }

    /**
     * Remember a 429 or 503 with its Retry-After and fail.
     *
     * @param array $response Response.
     * @throws source_exception If throttled.
     */
    private function check_throttling(array $response): void {
        if (!in_array($response['status'], [429, 503], true)) {
            return;
        }
        $retry = trim((string) ($response['headers']['retry-after'] ?? ''));
        $wait = ctype_digit($retry) ? (int) $retry : self::DEFAULT_BACKOFF;
        $wait = max(1, min($wait, DAYSECS));
        \cache::make('nextpoolsource_onedrive', 'backoff')->set($this->app_key(), time() + $wait);
        throw new source_exception('error_throttled', $wait, 'nextpoolsource_onedrive');
    }

    /**
     * Map an HTTP status to an exception unless it is accepted.
     *
     * @param array $response Response.
     * @param int[] $accepted Accepted status codes.
     * @throws source_exception
     */
    protected function assert_status(array $response, array $accepted): void {
        $status = $response['status'];
        if (in_array($status, $accepted, true)) {
            return;
        }
        throw match (true) {
            $status === 0 => new source_exception('error_sourceunavailable'),
            $status === 401 => new source_exception('error_credentials', null, 'nextpoolsource_onedrive'),
            $status === 403 => new source_exception('error_forbidden', null, 'nextpoolsource_onedrive'),
            $status === 404 => new source_exception('error_notfound'),
            $status === 507 => new source_exception('error_quota', null, 'nextpoolsource_onedrive'),
            default => new source_exception('error_sourcehttp', $status),
        };
    }

    /**
     * Decode a JSON body.
     *
     * @param string $body Body.
     * @return array [] if it is not a JSON object.
     */
    protected static function decode(string $body): array {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Send one HTTP request through Moodle's curl class.
     *
     * Redirects are never followed automatically, so the token cannot leak to another host.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL.
     * @param array $headers Headers, name => value.
     * @param string|null $body Request body.
     * @param string|null $infile Local file to send as body (PUT).
     * @param string|null $outfile Local file to write a GET body into.
     * @return array{status:int,body:string,headers:array<string,string>} Status 0 on transport errors;
     *     header names in lower case.
     */
    protected function http(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $infile = null,
        ?string $outfile = null
    ): array {
        $this->curl->resetHeader();
        foreach ($headers as $name => $value) {
            $this->curl->setHeader($name . ': ' . $value);
        }
        $options = ['CURLOPT_FOLLOWLOCATION' => 0];
        if ($outfile !== null) {
            $result = $this->curl->download_one($url, null, ['filepath' => $outfile] + $options);
        } else if ($infile !== null) {
            // No anonymous Basic Auth next to the bearer token.
            $result = $this->curl->put($url, ['file' => $infile], $options + ['CURLOPT_USERPWD' => false]);
        } else if ($method === 'GET') {
            $result = $this->curl->get($url, [], $options);
        } else {
            $result = $this->curl->post($url, $body ?? '', $options + ['CURLOPT_CUSTOMREQUEST' => $method]);
        }
        if (!empty($this->curl->error) || $result === false || $result === null) {
            return ['status' => 0, 'body' => '', 'headers' => []];
        }
        $status = (int) ($this->curl->get_info()['http_code'] ?? 0);
        $responseheaders = [];
        foreach ((array) $this->curl->getResponse() as $name => $value) {
            $responseheaders[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }
        if ($outfile !== null && $status !== 200 && file_exists($outfile)) {
            // An error page or redirect body must never be taken for content.
            unlink($outfile);
        }
        return ['status' => $status, 'body' => $outfile === null ? (string) $result : '', 'headers' => $responseheaders];
    }
}
