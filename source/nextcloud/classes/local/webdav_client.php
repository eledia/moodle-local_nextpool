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

namespace nextpoolsource_nextcloud\local;

use local_nextpool\local\source\source_exception;

/**
 * Nextcloud WebDAV client over an authenticated curl connection.
 *
 * Paths are full paths relative to the user's files root; confinement to the
 * backend's base folder happens in {@see \nextpoolsource_nextcloud\source}.
 * Content is streamed to and from files. Tests override {@see send_request()}
 * and {@see transfer()}; everything else runs without a server.
 *
 * NOTE: endpoint layout and OAuth flow follow the Nextcloud documentation and
 * still have to be verified against a real instance (task28, q01).
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webdav_client {
    /** @var string PROPFIND body for file and folder properties. */
    private const PROPFIND_FILES = '<?xml version="1.0"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
        . '<d:prop><oc:fileid/><d:resourcetype/><d:getetag/><d:getlastmodified/>'
        . '<d:getcontentlength/><d:getcontenttype/></d:prop></d:propfind>';

    /** @var string PROPFIND body for versions. */
    private const PROPFIND_VERSIONS = '<?xml version="1.0"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
        . '<d:prop><d:getlastmodified/><d:getcontentlength/><d:getcontenttype/><nc:version-label/></d:prop>'
        . '</d:propfind>';

    /**
     * Constructor.
     *
     * @param \curl $curl Transport; a core OAuth 2 client or a plain curl for app passwords.
     * @param string $serverurl Base URL of the Nextcloud server, without trailing slash.
     * @param string $username The Nextcloud account whose files root is addressed.
     * @param string|null $password App password for Basic Auth, or null when $curl is already authenticated.
     */
    public function __construct(
        /** @var \curl Transport. */
        protected readonly \curl $curl,
        /** @var string Nextcloud base URL. */
        protected readonly string $serverurl,
        /** @var string Nextcloud username. */
        protected readonly string $username,
        /** @var string|null App password, never exposed in errors. */
        protected readonly ?string $password = null,
    ) {
    }

    /**
     * Base URL of the Nextcloud server, without trailing slash.
     *
     * @return string
     */
    public function get_server_url(): string {
        return $this->serverurl;
    }

    /**
     * Properties of a file or folder.
     *
     * @param string $path Path relative to the user files root.
     * @return array|null Entry as returned by {@see webdav_parser::parse_entries()}, or null if missing.
     */
    public function stat(string $path): ?array {
        $response = $this->send_request('PROPFIND', $this->files_url($path), ['Depth' => '0'], self::PROPFIND_FILES);
        if ($response['status'] === 404) {
            return null;
        }
        $this->assert_status($response['status'], [207], $path);
        $entries = webdav_parser::parse_entries($response['body'], $this->username);
        return $entries[0] ?? null;
    }

    /**
     * Find a file or folder by its Nextcloud file id inside a folder (q46, task71).
     *
     * Uses a WebDAV SEARCH for oc:fileid with the folder as scope, so a file outside that folder is not found.
     *
     * @param string $fileid Nextcloud file id (digits).
     * @param string $scope Folder path relative to the user files root ('' for the root).
     * @return array|null Entry as returned by {@see webdav_parser::parse_entries()}, or null if not found.
     */
    public function find_by_fileid(string $fileid, string $scope): ?array {
        if (!ctype_digit($fileid)) {
            return null;
        }
        $encoded = $this->encode_path($scope);
        $href = '/files/' . rawurlencode($this->username) . ($encoded !== '' ? '/' . $encoded : '');
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:basicsearch>'
            . '<d:select><d:prop><oc:fileid/><d:resourcetype/><d:getetag/><d:getlastmodified/>'
            . '<d:getcontentlength/><d:getcontenttype/></d:prop></d:select>'
            . '<d:from><d:scope><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href>'
            . '<d:depth>infinity</d:depth></d:scope></d:from>'
            . '<d:where><d:eq><d:prop><oc:fileid/></d:prop><d:literal>' . $fileid . '</d:literal></d:eq></d:where>'
            . '</d:basicsearch></d:searchrequest>';
        $response = $this->send_request('SEARCH', $this->serverurl . '/remote.php/dav/', [], $body);
        if ($response['status'] === 404) {
            return null;
        }
        $this->assert_status($response['status'], [207], $scope);
        foreach (webdav_parser::parse_entries($response['body'], $this->username) as $entry) {
            if ($entry['fileid'] === $fileid) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Direct children of a folder.
     *
     * @param string $path Folder path relative to the user files root.
     * @return array[] Entries as returned by {@see webdav_parser::parse_entries()}, without the folder itself.
     */
    public function list_folder(string $path): array {
        $response = $this->send_request('PROPFIND', $this->files_url($path, true), ['Depth' => '1'], self::PROPFIND_FILES);
        $this->assert_status($response['status'], [207], $path);
        $self = trim($path, '/');
        return array_values(array_filter(
            webdav_parser::parse_entries($response['body'], $this->username),
            static fn(array $entry): bool => $entry['path'] !== $self
        ));
    }

    /**
     * Download a file into a local file.
     *
     * @param string $path Path relative to the user files root.
     * @param string $targetfile Absolute local path.
     */
    public function download_to_file(string $path, string $targetfile): void {
        $this->assert_status($this->transfer('GET', $this->files_url($path), null, $targetfile), [200], $path);
    }

    /**
     * Upload a local file, creating missing parent folders.
     *
     * @param string $path Path relative to the user files root.
     * @param string $sourcefile Absolute local path.
     */
    public function upload_file(string $path, string $sourcefile): void {
        $mimetype = mimeinfo('type', $path);
        $status = $this->transfer('PUT', $this->files_url($path), $sourcefile, null, $mimetype);
        if ($status === 409 || $status === 404) {
            // Parent folder missing (WebDAV says 409, Nextcloud 32 answers 404, bug231): create it segment by
            // segment and retry once.
            $this->create_folders(dirname(trim($path, '/')));
            $status = $this->transfer('PUT', $this->files_url($path), $sourcefile, null, $mimetype);
        }
        $this->assert_status($status, [200, 201, 204], $path);
    }

    /**
     * Delete a file (M06 direct upload, TD-405).
     *
     * A file that does not exist counts as deleted.
     *
     * @param string $path Path relative to the user files root.
     */
    public function delete_file(string $path): void {
        $response = $this->send_request('DELETE', $this->files_url($path));
        $this->assert_status($response['status'], [200, 204, 404], $path);
    }

    /**
     * Create a folder and its missing parents.
     *
     * @param string $path Folder path relative to the user files root ('.' or '' for the root).
     */
    public function create_folders(string $path): void {
        $current = '';
        foreach (explode('/', trim($path, '/.')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $response = $this->send_request('MKCOL', $this->files_url($current, true));
            // 405: the folder already exists.
            $this->assert_status($response['status'], [201, 405], $current);
        }
    }

    /**
     * Native Nextcloud versions of a file (optional backend versions).
     *
     * @param string $fileid Nextcloud file id.
     * @return file_version[] Newest first.
     */
    public function list_versions(string $fileid): array {
        if ($fileid === '') {
            return [];
        }
        $response = $this->send_request('PROPFIND', $this->versions_url($fileid), ['Depth' => '1'], self::PROPFIND_VERSIONS);
        if ($response['status'] === 404) {
            return [];
        }
        $this->assert_status($response['status'], [207]);
        return webdav_parser::parse_versions($response['body']);
    }

    /**
     * Download a native Nextcloud version into a local file.
     *
     * @param string $fileid Nextcloud file id.
     * @param string $versionkey Version key from {@see list_versions()}.
     * @param string $targetfile Absolute local path.
     */
    public function download_version_to_file(string $fileid, string $versionkey, string $targetfile): void {
        $url = $this->versions_url($fileid) . '/' . rawurlencode($versionkey);
        $this->assert_status($this->transfer('GET', $url, null, $targetfile), [200]);
    }

    /**
     * WebDAV files endpoint URL for a path.
     *
     * @param string $path Path relative to the user files root.
     * @param bool $folder Append a trailing slash.
     * @return string
     */
    protected function files_url(string $path, bool $folder = false): string {
        $encoded = $this->encode_path($path);
        $url = $this->serverurl . '/remote.php/dav/files/' . rawurlencode($this->username) . '/' . $encoded;
        return $folder && $encoded !== '' ? $url . '/' : $url;
    }

    /**
     * WebDAV versions endpoint URL for a file id.
     *
     * @param string $fileid Nextcloud file id.
     * @return string
     */
    protected function versions_url(string $fileid): string {
        return $this->serverurl . '/remote.php/dav/versions/' . rawurlencode($this->username)
            . '/versions/' . rawurlencode($fileid);
    }

    /**
     * URL-encode a path, preserving the slash separators.
     *
     * Dot segments never reach this point: the source normalises every path first.
     *
     * @param string $path Path relative to the user files root.
     * @return string
     */
    protected function encode_path(string $path): string {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '';
        }
        return implode('/', array_map('rawurlencode', explode('/', $trimmed)));
    }

    /**
     * Map an HTTP status to an exception unless it is accepted.
     *
     * @param int $status HTTP status.
     * @param int[] $accepted Accepted status codes.
     * @param string|null $path Path relative to the user files root, named in the error message.
     * @throws source_exception
     */
    protected function assert_status(int $status, array $accepted, ?string $path = null): void {
        if (in_array($status, $accepted, true)) {
            return;
        }
        // Name account and path, so that a wrong user ID or folder name is visible at once (task41).
        // Exception messages are shown as HTML: the path can come from a link or a file name (bug121).
        $a = (object) [
            'user' => s($this->username),
            'path' => s($path === null || $path === '' ? '/' : '/' . trim($path, '/')),
        ];
        throw match (true) {
            $status === 0 => new source_exception('error_sourceunavailable'),
            $status === 401, $status === 403 => new source_exception('error_nextcloudauth', $a),
            $status === 404 && $path !== null => new source_exception('error_nextcloudnotfound', $a),
            $status === 404 => new source_exception('error_notfound'),
            default => new source_exception('error_sourcehttp', $status),
        };
    }

    /**
     * Reset headers and add the Basic Auth header in app-password mode.
     */
    protected function prepare_headers(): void {
        $this->curl->resetHeader();
        if ($this->password !== null && $this->password !== '') {
            $this->curl->setHeader('Authorization: Basic ' . base64_encode($this->username . ':' . $this->password));
        }
    }

    /**
     * Perform a small request whose response body is read into memory (PROPFIND, MKCOL, DELETE).
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL.
     * @param array $headers Extra request headers.
     * @param string|null $body Request body (XML), or null.
     * @return array{status:int,body:string} Status 0 on transport errors.
     */
    protected function send_request(string $method, string $url, array $headers = [], ?string $body = null): array {
        $this->prepare_headers();
        foreach ($headers as $name => $value) {
            $this->curl->setHeader($name . ': ' . $value);
        }
        if ($body !== null) {
            $this->curl->setHeader('Content-Type: application/xml; charset=utf-8');
        }
        // Core curl keeps request() protected; post() with a custom method sends PROPFIND, MKCOL or DELETE.
        $result = $this->curl->post($url, $body ?? '', [
            'CURLOPT_CUSTOMREQUEST' => $method,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);
        if (!empty($this->curl->error)) {
            return ['status' => 0, 'body' => ''];
        }
        return ['status' => (int) ($this->curl->get_info()['http_code'] ?? 0), 'body' => (string) $result];
    }

    /**
     * Stream content: GET into $outfile or PUT from $infile.
     *
     * @param string $method 'GET' or 'PUT'.
     * @param string $url Absolute URL.
     * @param string|null $infile Local file to upload (PUT).
     * @param string|null $outfile Local file to write (GET).
     * @param string $contenttype MIME type of the uploaded content (PUT).
     * @return int HTTP status, 0 on transport errors.
     */
    protected function transfer(string $method, string $url, ?string $infile, ?string $outfile, string $contenttype = ''): int {
        $this->prepare_headers();
        if ($method === 'GET') {
            $result = $this->curl->download_one($url, null, ['filepath' => $outfile, 'CURLOPT_FOLLOWLOCATION' => 0]);
        } else {
            $this->curl->setHeader('Content-Type: ' . ($contenttype !== '' ? $contenttype : 'application/octet-stream'));
            $result = $this->curl->put($url, ['file' => $infile]);
        }
        if (!empty($this->curl->error) || $result === false) {
            return 0;
        }
        $status = (int) ($this->curl->get_info()['http_code'] ?? 0);
        if ($method === 'GET' && $status !== 200 && $outfile !== null && file_exists($outfile)) {
            // An error page must never be taken for content.
            unlink($outfile);
        }
        return $status;
    }
}
