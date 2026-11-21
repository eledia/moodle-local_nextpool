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

/**
 * Parser for Nextcloud WebDAV multistatus (PROPFIND) responses.
 *
 * Kept separate from the transport so the XML handling can be unit tested with
 * captured fixture responses and no live server. Taken over from mod_nextpool
 * (v1) and extended with folder listings.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webdav_parser {
    /**
     * Parse a files PROPFIND response into one entry per response node.
     *
     * @param string $xml Raw multistatus XML.
     * @param string $username Nextcloud user whose files root the hrefs point into.
     * @return array<int,array{path:string,isfolder:bool,fileid:string,etag:string,size:int,mimetype:string,mtime:int}>
     *         Paths are decoded and relative to the user's files root, without leading or trailing slash.
     */
    public static function parse_entries(string $xml, string $username): array {
        $doc = self::load($xml);
        if ($doc === null) {
            return [];
        }
        $marker = '/remote.php/dav/files/' . $username . '/';
        $entries = [];
        foreach ($doc->xpath('//*[local-name()="response"]') as $response) {
            $href = self::first_value($response, 'href', false);
            $urlpath = rawurldecode((string) (parse_url($href, PHP_URL_PATH) ?? ''));
            $pos = strpos($urlpath . (str_ends_with($urlpath, '/') ? '' : '/'), $marker);
            if ($pos === false) {
                continue;
            }
            $path = trim(substr($urlpath, $pos + strlen($marker)), '/');
            $props = self::extract_props($response);
            $entries[] = [
                'path' => $path,
                'isfolder' => !empty($response->xpath('.//*[local-name()="resourcetype"]/*[local-name()="collection"]')),
                'fileid' => $props['fileid'],
                'etag' => trim($props['etag'], '"'),
                'size' => (int) $props['size'],
                'mimetype' => $props['mimetype'],
                'mtime' => $props['mtime'],
            ];
        }
        return $entries;
    }

    /**
     * Parse a versions PROPFIND (Depth: 1) response into file_version objects.
     *
     * The collection node itself is skipped; only the individual version nodes
     * are returned, ordered newest first.
     *
     * @param string $xml Raw multistatus XML.
     * @return file_version[]
     */
    public static function parse_versions(string $xml): array {
        $doc = self::load($xml);
        if ($doc === null) {
            return [];
        }
        $versions = [];
        foreach ($doc->xpath('//*[local-name()="response"]') as $response) {
            $href = self::first_value($response, 'href', false);
            // The collection node's href ends with a slash; version nodes do not.
            if ($href === '' || substr($href, -1) === '/') {
                continue;
            }
            $versionkey = rawurldecode(basename($href));
            if ($versionkey === '') {
                continue;
            }
            $props = self::extract_props($response);
            $versions[] = new file_version(
                versionkey: $versionkey,
                mtime: $props['mtime'],
                size: (int) $props['size'],
                mimetype: $props['mimetype'],
                label: $props['label'],
            );
        }
        usort($versions, static fn($a, $b) => $b->mtime <=> $a->mtime);
        return $versions;
    }

    /**
     * Load XML defensively, suppressing libxml warnings.
     *
     * @param string $xml Raw XML.
     * @return \SimpleXMLElement|null
     */
    private static function load(string $xml): ?\SimpleXMLElement {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc === false ? null : $doc;
    }

    /**
     * Extract the properties we care about from a single response node.
     *
     * The local names read here are unambiguous across the DAV, ownCloud and
     * Nextcloud namespaces, so matching by local name is safe.
     *
     * @param \SimpleXMLElement $response A d:response node.
     * @return array{fileid:string,etag:string,size:string,mimetype:string,mtime:int,label:string}
     */
    private static function extract_props(\SimpleXMLElement $response): array {
        $lastmod = self::first_value($response, 'getlastmodified');
        return [
            'fileid'   => self::first_value($response, 'fileid'),
            'etag'     => self::first_value($response, 'getetag'),
            'size'     => self::first_value($response, 'getcontentlength'),
            'mimetype' => self::first_value($response, 'getcontenttype'),
            'mtime'    => $lastmod !== '' ? (int) strtotime($lastmod) : 0,
            'label'    => self::first_value($response, 'version-label'),
        ];
    }

    /**
     * Return the text of the first matching descendant element, by local name.
     *
     * Property values are read from a successful (HTTP 200) propstat block where
     * the server wraps properties in one; otherwise any matching element counts.
     *
     * @param \SimpleXMLElement $node Context node.
     * @param string $localname Local element name.
     * @param bool $inpropstat Prefer values inside a 200 propstat block.
     * @return string Trimmed text, or '' if not present.
     */
    private static function first_value(\SimpleXMLElement $node, string $localname, bool $inpropstat = true): string {
        $matches = [];
        if ($inpropstat) {
            $matches = $node->xpath('.//*[local-name()="propstat"][contains(*[local-name()="status"], "200")]'
                . '//*[local-name()="' . $localname . '"]');
        }
        if (empty($matches)) {
            $matches = $node->xpath('.//*[local-name()="' . $localname . '"]');
        }
        $first = $matches[0] ?? null;
        return $first === null ? '' : trim((string) $first);
    }
}
