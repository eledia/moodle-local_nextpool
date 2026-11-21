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

namespace nextpoolsource_nextcloud;

use nextpoolsource_nextcloud\local\webdav_client;

/**
 * In-memory Nextcloud for tests.
 *
 * Answers PROPFIND, SEARCH (by file id), GET, PUT, MKCOL and DELETE from a virtual file
 * tree and records every request, so the real URL building and XML parsing are
 * exercised. File ids stay with a file when it is moved, as in Nextcloud.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_webdav_client extends webdav_client {
    /** @var array<string,string|null> Path => content (null for folders). */
    public array $tree = [];
    /** @var array<int,array{0:string,1:string}> Recorded [method, url]. */
    public array $requests = [];
    /** @var int|null Status to answer every request with (simulates server errors). */
    public ?int $forcestatus = null;
    /** @var int Status for a PUT into a missing folder: 409 by the standard, 404 from Nextcloud 32 (bug231). */
    public int $missingparentstatus = 409;
    /** @var array<string,string> Path => file id. */
    public array $ids = [];
    /** @var array<int,string> Recorded request bodies (SEARCH), in order. */
    public array $searchbodies = [];

    /**
     * Constructor.
     *
     * @param string $username Nextcloud user.
     */
    public function __construct(string $username = 'nextpool') {
        parent::__construct(new \curl(), 'https://cloud.example.org/nc', $username);
    }

    /**
     * Add a file and its parent folders.
     *
     * @param string $path Path relative to the user root.
     * @param string $content Content.
     */
    public function add_file(string $path, string $content): void {
        $parts = explode('/', $path);
        for ($i = 1; $i < count($parts); $i++) {
            $this->tree[implode('/', array_slice($parts, 0, $i))] = null;
        }
        $this->tree[$path] = $content;
        $this->assign_id($path);
    }

    /**
     * Move or rename a file, keeping its file id.
     *
     * @param string $from Current path relative to the user root.
     * @param string $to New path relative to the user root.
     */
    public function move(string $from, string $to): void {
        $id = $this->ids[$from];
        $this->add_file($to, $this->tree[$from]);
        unset($this->tree[$from], $this->ids[$from]);
        $this->ids[$to] = $id;
    }

    /**
     * Give a path a file id unless it has one.
     *
     * @param string $path Path relative to the user root.
     */
    private function assign_id(string $path): void {
        $this->ids[$path] ??= (string) (1000 + count($this->ids));
    }

    /**
     * Path relative to the user root from a files URL.
     *
     * @param string $url URL.
     * @return string
     */
    private function path_from_url(string $url): string {
        $prefix = 'https://cloud.example.org/nc/remote.php/dav/files/' . rawurlencode($this->username) . '/';
        return trim(rawurldecode(substr($url, strlen($prefix))), '/');
    }

    /**
     * Multistatus response node for a path.
     *
     * @param string $path Path relative to the user root.
     * @return string
     */
    private function response_xml(string $path): string {
        $isfolder = $path === '' || $this->tree[$path] === null;
        $href = '/nc/remote.php/dav/files/' . rawurlencode($this->username) . '/'
            . implode('/', array_map('rawurlencode', $path === '' ? [] : explode('/', $path))) . ($isfolder ? '/' : '');
        $props = '<oc:fileid>' . ($this->ids[$path] ?? (crc32($path) % 1000)) . '</oc:fileid>'
            . '<d:resourcetype>' . ($isfolder ? '<d:collection/>' : '') . '</d:resourcetype>'
            . '<d:getetag>"' . ($isfolder ? md5($path) : sha1($this->tree[$path])) . '"</d:getetag>'
            . '<d:getlastmodified>Fri, 02 Oct 2026 10:00:00 GMT</d:getlastmodified>';
        if (!$isfolder) {
            $props .= '<d:getcontentlength>' . strlen($this->tree[$path]) . '</d:getcontentlength>'
                . '<d:getcontenttype>' . mimeinfo('type', $path) . '</d:getcontenttype>';
        }
        return '<d:response><d:href>' . htmlspecialchars($href) . '</d:href><d:propstat><d:prop>' . $props
            . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
    }

    #[\Override]
    protected function send_request(string $method, string $url, array $headers = [], ?string $body = null): array {
        $this->requests[] = [$method, $url];
        if ($this->forcestatus !== null) {
            return ['status' => $this->forcestatus, 'body' => ''];
        }
        if ($method === 'SEARCH') {
            return $this->search((string) $body);
        }
        $path = $this->path_from_url($url);
        if ($method === 'MKCOL') {
            if (array_key_exists($path, $this->tree)) {
                return ['status' => 405, 'body' => ''];
            }
            $this->tree[$path] = null;
            return ['status' => 201, 'body' => ''];
        }
        if ($method === 'DELETE') {
            if (!array_key_exists($path, $this->tree)) {
                return ['status' => 404, 'body' => ''];
            }
            foreach (array_keys($this->tree) as $candidate) {
                if ((string) $candidate === $path || str_starts_with((string) $candidate, $path . '/')) {
                    unset($this->tree[$candidate], $this->ids[$candidate]);
                }
            }
            return ['status' => 204, 'body' => ''];
        }
        if ($path !== '' && !array_key_exists($path, $this->tree)) {
            return ['status' => 404, 'body' => ''];
        }
        $nodes = [$this->response_xml($path)];
        if (($headers['Depth'] ?? '0') === '1') {
            foreach (array_keys($this->tree) as $candidate) {
                $candidate = (string) $candidate;
                $parent = str_contains($candidate, '/') ? substr($candidate, 0, strrpos($candidate, '/')) : '';
                if ($parent === $path && $candidate !== $path) {
                    $nodes[] = $this->response_xml($candidate);
                }
            }
        }
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            . implode('', $nodes) . '</d:multistatus>';
        return ['status' => 207, 'body' => $xml];
    }

    /**
     * Answer a SEARCH for one file id inside a scope folder.
     *
     * @param string $body Search request XML.
     * @return array{status:int,body:string}
     */
    private function search(string $body): array {
        $this->searchbodies[] = $body;
        preg_match('#<d:literal>([^<]*)</d:literal>#', $body, $literal);
        preg_match('#<d:href>/files/[^/<]+/?([^<]*)</d:href>#', $body, $scope);
        $scopepath = trim(rawurldecode($scope[1] ?? ''), '/');
        $nodes = [];
        foreach ($this->ids as $path => $id) {
            $path = (string) $path;
            $inside = $scopepath === '' || str_starts_with($path . '/', $scopepath . '/');
            if ($id === ($literal[1] ?? '') && $inside && $path !== $scopepath) {
                $nodes[] = $this->response_xml($path);
            }
        }
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            . implode('', $nodes) . '</d:multistatus>';
        return ['status' => 207, 'body' => $xml];
    }

    #[\Override]
    protected function transfer(string $method, string $url, ?string $infile, ?string $outfile, string $contenttype = ''): int {
        $this->requests[] = [$method, $url];
        if ($this->forcestatus !== null) {
            return $this->forcestatus;
        }
        $path = $this->path_from_url($url);
        if ($method === 'GET') {
            if (!isset($this->tree[$path])) {
                return 404;
            }
            file_put_contents($outfile, $this->tree[$path]);
            return 200;
        }
        $parent = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : '';
        if ($parent !== '' && !array_key_exists($parent, $this->tree)) {
            return $this->missingparentstatus;
        }
        $exists = isset($this->tree[$path]);
        $this->tree[$path] = file_get_contents($infile);
        $this->assign_id($path);
        return $exists ? 204 : 201;
    }
}
