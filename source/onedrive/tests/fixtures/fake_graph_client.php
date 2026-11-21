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

namespace nextpoolsource_onedrive;

use nextpoolsource_onedrive\local\graph_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * In-memory Microsoft 365 tenant with one site and its drives, for tests.
 *
 * Replaces only {@see graph_client::http()}, so token handling, paging, error
 * mapping, redirects and upload sessions run as against Graph. Item ids stay
 * with an item when it is renamed or moved, cTags change with the content only.
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_graph_client extends graph_client {
    /** @var string Drive used by default. */
    public const DRIVE = 'b!drive1';
    /** @var string Site id. */
    public const SITE = 'contoso.sharepoint.com,11111111-1111-1111-1111-111111111111,22222222-2222-2222-2222-222222222222';

    /** @var array<string,array> Item id => [name, parent, content (null for folders), ctag, drive]. */
    public array $items = [];
    /** @var array<string,string> Drive id => name. */
    public array $drives = [self::DRIVE => 'Documents', 'b!drive2' => 'Training'];
    /** @var array[] Recorded requests: method, url, headers. */
    public array $requests = [];
    /** @var array<string,array{status:int,headers?:array}> URL substring => forced response. */
    public array $force = [];
    /** @var string The secret Microsoft accepts. */
    public string $validsecret = 'secret';
    /** @var int Page size of collections, to exercise @odata.nextLink. */
    public int $pagesize = 2;
    /** @var array<string,array{path:string,data:string}> Upload sessions. */
    private array $sessions = [];
    /** @var int Item counter. */
    private int $counter = 0;
    /** @var int Upload chunks to answer with a server error before accepting them. */
    public int $failchunks = 0;
    /** @var int Tokens issued. */
    public int $tokens = 0;

    /**
     * Constructor.
     *
     * @param string $secret Client secret the client sends.
     * @param int $chunksize Upload session chunk size.
     */
    public function __construct(string $secret = 'secret', int $chunksize = 320 * 1024) {
        parent::__construct(new \curl(), 'contoso.onmicrosoft.com', '6bb5e8d9-efa9-455a-8548-3ac82d948080', $secret);
        $this->chunksize = $chunksize;
        foreach (array_keys($this->drives) as $drive) {
            $this->items['root-' . $drive] = ['name' => 'root', 'parent' => null, 'content' => null, 'ctag' => 'c0',
                'drive' => $drive];
        }
    }

    /**
     * Add a file, creating its folders.
     *
     * @param string $path Full path in the drive.
     * @param string $content Content.
     * @param string $drive Drive id.
     * @return string Item id.
     */
    public function add_file(string $path, string $content, string $drive = self::DRIVE): string {
        $parent = $this->ensure_folder(dirname($path) === '.' ? '' : dirname($path), $drive);
        $id = $this->find_child($parent, basename($path));
        if ($id === null) {
            $id = 'ITEM' . (++$this->counter);
            $this->items[$id] = ['name' => basename($path), 'parent' => $parent, 'content' => $content,
                'ctag' => 'c' . sha1($content), 'drive' => $drive];
        } else {
            $this->items[$id]['content'] = $content;
            $this->items[$id]['ctag'] = 'c' . sha1($content . $this->counter++);
        }
        return $id;
    }

    /**
     * Move or rename an item, keeping its id and cTag.
     *
     * @param string $id Item id.
     * @param string $topath New full path.
     */
    public function move(string $id, string $topath): void {
        $drive = $this->items[$id]['drive'];
        $this->items[$id]['parent'] = $this->ensure_folder(dirname($topath) === '.' ? '' : dirname($topath), $drive);
        $this->items[$id]['name'] = basename($topath);
    }

    /**
     * Create a folder and its parents.
     *
     * @param string $path Full folder path ('' for the root).
     * @param string $drive Drive id.
     * @return string Folder id.
     */
    public function ensure_folder(string $path, string $drive = self::DRIVE): string {
        $id = 'root-' . $drive;
        foreach (array_filter(explode('/', $path), 'strlen') as $segment) {
            $child = $this->find_child($id, $segment);
            if ($child === null) {
                $child = 'ITEM' . (++$this->counter);
                $this->items[$child] = ['name' => $segment, 'parent' => $id, 'content' => null, 'ctag' => 'f', 'drive' => $drive];
            }
            $id = $child;
        }
        return $id;
    }

    /**
     * Remove an item and everything below it.
     *
     * @param string $id Item id.
     */
    private function remove(string $id): void {
        foreach ($this->items as $childid => $item) {
            if ($item['parent'] === $id) {
                $this->remove((string) $childid);
            }
        }
        unset($this->items[$id]);
    }

    /**
     * Child of a folder by name.
     *
     * @param string $parent Folder id.
     * @param string $name Name.
     * @return string|null
     */
    private function find_child(string $parent, string $name): ?string {
        foreach ($this->items as $id => $item) {
            if ($item['parent'] === $parent && $item['name'] === $name) {
                return (string) $id;
            }
        }
        return null;
    }

    /**
     * Full path of an item.
     *
     * @param string $id Item id.
     * @return string
     */
    private function path_of(string $id): string {
        $names = [];
        while ($this->items[$id]['parent'] !== null) {
            array_unshift($names, $this->items[$id]['name']);
            $id = $this->items[$id]['parent'];
        }
        return implode('/', $names);
    }

    /**
     * Item by full path.
     *
     * @param string $path Full path.
     * @param string $drive Drive id.
     * @return string|null
     */
    private function id_of(string $path, string $drive): ?string {
        $id = 'root-' . $drive;
        foreach (array_filter(explode('/', $path), 'strlen') as $segment) {
            $id = $this->find_child($id, $segment);
            if ($id === null) {
                return null;
            }
        }
        return $id;
    }

    /**
     * Drive item JSON.
     *
     * @param string $id Item id.
     * @return array
     */
    public function item_json(string $id): array {
        $item = $this->items[$id];
        $json = ['id' => $id, 'name' => $item['name'], 'cTag' => $item['ctag'], 'lastModifiedDateTime' => '2026-11-14T10:00:00Z'];
        $path = $this->path_of($id);
        $json['webUrl'] = 'https://contoso.sharepoint.com/sites/Training/Shared%20Documents'
            . ($path === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $path))));
        if ($item['parent'] === null) {
            $json['root'] = new \stdClass();
            $json['parentReference'] = ['driveId' => $item['drive']];
        } else {
            $parentpath = $this->path_of($item['parent']);
            $json['parentReference'] = ['driveId' => $item['drive'],
                'path' => '/drives/' . $item['drive'] . '/root:' . ($parentpath === '' ? '' : '/' . $parentpath)];
        }
        if ($item['content'] === null) {
            $json['folder'] = ['childCount' => 0];
        } else {
            $json['file'] = ['mimeType' => mimeinfo('type', $item['name'])];
            $json['size'] = strlen($item['content']);
        }
        return $json;
    }

    /**
     * JSON response.
     *
     * @param int $status Status.
     * @param array $json Body.
     * @return array
     */
    private static function json(int $status, array $json = []): array {
        return ['status' => $status, 'body' => json_encode($json), 'headers' => []];
    }

    #[\Override]
    protected function http(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $infile = null,
        ?string $outfile = null
    ): array {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        foreach ($this->force as $pattern => $forced) {
            if (str_contains($url, $pattern)) {
                return ['status' => $forced['status'], 'body' => json_encode($forced['body'] ?? []),
                    'headers' => $forced['headers'] ?? []];
            }
        }
        if (str_starts_with($url, self::LOGIN)) {
            parse_str((string) $body, $form);
            if (($form['client_secret'] ?? '') !== $this->validsecret) {
                return self::json(401, ['error' => 'invalid_client',
                    'error_description' => 'AADSTS7000215: Invalid client secret.']);
            }
            $this->tokens++;
            return self::json(200, ['access_token' => 'token' . $this->tokens, 'expires_in' => 3599]);
        }
        if (str_starts_with($url, 'https://download.example/')) {
            if (isset($headers['Authorization'])) {
                return self::json(400);
            }
            file_put_contents($outfile, $this->items[substr($url, strlen('https://download.example/'))]['content']);
            return ['status' => 200, 'body' => '', 'headers' => []];
        }
        if (str_starts_with($url, 'https://upload.example/')) {
            return $this->session_request($method, $url, $headers, $infile);
        }
        if (($headers['Authorization'] ?? '') !== 'Bearer token' . $this->tokens) {
            return self::json(401);
        }
        $path = rawurldecode(substr((string) parse_url($url, PHP_URL_PATH), strlen('/v1.0')));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (preg_match('#^/sites/(.+?)(/drives)?$#', $path, $m)) {
            if ($m[1] !== self::SITE && $m[1] !== 'contoso.sharepoint.com:/sites/Training') {
                return self::json(404);
            }
            if (empty($m[2])) {
                return self::json(200, ['id' => self::SITE, 'displayName' => 'Training']);
            }
            $value = [];
            foreach ($this->drives as $id => $name) {
                $library = $name === 'Documents' ? 'Shared Documents' : $name;
                $value[] = ['id' => $id, 'name' => $name,
                    'webUrl' => 'https://contoso.sharepoint.com/sites/Training/' . rawurlencode($library)];
            }
            return self::json(200, ['value' => $value]);
        }
        if (!preg_match('#^/drives/([^/]+)(.*)$#', $path, $m) || !isset($this->drives[$m[1]])) {
            return self::json(404);
        }
        [$drive, $rest] = [$m[1], $m[2]];
        if ($rest === '') {
            return self::json(200, ['id' => $drive, 'name' => $this->drives[$drive]]);
        }
        $suffix = '';
        if (preg_match('#^/items/([^/]+)$#', $rest, $m)) {
            $id = isset($this->items[$m[1]]) && $this->items[$m[1]]['drive'] === $drive ? $m[1] : null;
        } else if (preg_match('#^/root(?::/(.*?):)?(/children|/content|/createUploadSession)?$#', $rest, $m)) {
            $itempath = $m[1] ?? '';
            $suffix = $m[2] ?? '';
            $id = $this->id_of($itempath, $drive);
            if ($method === 'PUT' && $suffix === '/content') {
                $id = $this->add_file($itempath, file_get_contents($infile), $drive);
                return self::json(201, $this->item_json($id));
            }
            if ($method === 'POST' && $suffix === '/createUploadSession') {
                $session = 'https://upload.example/session' . count($this->sessions);
                $this->sessions[$session] = ['path' => $itempath, 'data' => '', 'drive' => $drive];
                return self::json(200, ['uploadUrl' => $session]);
            }
        } else {
            return self::json(400);
        }
        if ($id === null) {
            return self::json(404);
        }
        if ($method === 'DELETE') {
            $this->remove($id);
            return self::json(204);
        }
        if ($suffix === '/content') {
            if ($outfile !== null) {
                file_put_contents($outfile, '<html>redirect</html>');
                unlink($outfile);
            }
            return ['status' => 302, 'body' => '', 'headers' => ['location' => 'https://download.example/' . $id]];
        }
        if ($suffix === '/children') {
            $children = [];
            foreach ($this->items as $childid => $item) {
                if ($item['parent'] === $id) {
                    $children[] = $this->item_json((string) $childid);
                }
            }
            $skip = (int) ($query['skip'] ?? 0);
            $page = ['value' => array_slice($children, $skip, $this->pagesize)];
            if ($skip + $this->pagesize < count($children)) {
                $page['@odata.nextLink'] = self::GRAPH . '/drives/' . $drive . $rest . '?skip=' . ($skip + $this->pagesize);
            }
            return self::json(200, $page);
        }
        return self::json(200, $this->item_json($id));
    }

    /**
     * Answer a request to an upload session.
     *
     * @param string $method Method.
     * @param string $url Session URL.
     * @param array $headers Headers.
     * @param string|null $infile Chunk.
     * @return array
     */
    private function session_request(string $method, string $url, array $headers, ?string $infile): array {
        if (!isset($this->sessions[$url]) || isset($headers['Authorization'])) {
            return self::json(404);
        }
        $session = &$this->sessions[$url];
        if ($method === 'DELETE') {
            unset($this->sessions[$url]);
            return self::json(204);
        }
        if ($method === 'GET') {
            return self::json(200, ['nextExpectedRanges' => [strlen($session['data']) . '-']]);
        }
        if ($this->failchunks > 0) {
            $this->failchunks--;
            return self::json(500);
        }
        preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $headers['Content-Range'] ?? '', $range);
        $chunk = file_get_contents($infile);
        if ((int) $range[1] !== strlen($session['data']) || strlen($chunk) !== (int) $range[2] - (int) $range[1] + 1) {
            return self::json(416);
        }
        $session['data'] .= $chunk;
        if (strlen($session['data']) < (int) $range[3]) {
            return self::json(202, ['nextExpectedRanges' => [strlen($session['data']) . '-' . ($range[3] - 1)]]);
        }
        $id = $this->add_file($session['path'], $session['data'], $session['drive']);
        unset($this->sessions[$url]);
        return self::json(201, $this->item_json($id));
    }
}
