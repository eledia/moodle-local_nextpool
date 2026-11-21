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

use nextpoolsource_nextcloud\local\webdav_parser;

/**
 * Tests for the Nextcloud WebDAV multistatus parser.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(webdav_parser::class)]
final class webdav_parser_test extends \basic_testcase {
    /**
     * A file response is parsed with decoded path and properties.
     */
    public function test_parse_file_entry(): void {
        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
 <d:response>
  <d:href>/remote.php/dav/files/admin/Documents/Hand%20out.pdf</d:href>
  <d:propstat>
   <d:prop>
    <oc:fileid>12345</oc:fileid>
    <d:resourcetype/>
    <d:getetag>&quot;abc123&quot;</d:getetag>
    <d:getlastmodified>Wed, 01 Jul 2026 10:00:00 GMT</d:getlastmodified>
    <d:getcontentlength>2048</d:getcontentlength>
    <d:getcontenttype>application/pdf</d:getcontenttype>
   </d:prop>
   <d:status>HTTP/1.1 200 OK</d:status>
  </d:propstat>
 </d:response>
</d:multistatus>';

        $entries = webdav_parser::parse_entries($xml, 'admin');
        $this->assertCount(1, $entries);
        $this->assertSame([
            'path' => 'Documents/Hand out.pdf',
            'isfolder' => false,
            'fileid' => '12345',
            'etag' => 'abc123',
            'size' => 2048,
            'mimetype' => 'application/pdf',
            'mtime' => strtotime('Wed, 01 Jul 2026 10:00:00 GMT'),
        ], $entries[0]);
    }

    /**
     * A folder listing from a Nextcloud in a subdirectory, with absolute hrefs and a 404 propstat.
     */
    public function test_parse_listing(): void {
        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
 <d:response>
  <d:href>https://cloud.example.org/nc/remote.php/dav/files/j%C3%BCrgen/Catalogue/</d:href>
  <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype><d:getetag>"f1"</d:getetag></d:prop>
   <d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  <d:propstat><d:prop><d:getcontentlength/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>
 </d:response>
 <d:response>
  <d:href>/nc/remote.php/dav/files/j%C3%BCrgen/Catalogue/Sub/</d:href>
  <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>
   <d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
 <d:response>
  <d:href>/nc/remote.php/dav/files/j%C3%BCrgen/Catalogue/a.txt</d:href>
  <d:propstat><d:prop><d:resourcetype/><d:getcontentlength>3</d:getcontentlength></d:prop>
   <d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
 <d:response>
  <d:href>/remote.php/dav/files/someoneelse/x.txt</d:href>
  <d:propstat><d:prop><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
</d:multistatus>';

        $entries = webdav_parser::parse_entries($xml, 'jürgen');
        $this->assertSame(['Catalogue', 'Catalogue/Sub', 'Catalogue/a.txt'], array_column($entries, 'path'));
        $this->assertSame([true, true, false], array_column($entries, 'isfolder'));
        $this->assertSame('f1', $entries[0]['etag']);
        $this->assertSame(3, $entries[2]['size']);
    }

    /**
     * Invalid or empty XML yields no entries rather than an error.
     */
    public function test_parse_invalid(): void {
        $this->assertSame([], webdav_parser::parse_entries('', 'x'));
        $this->assertSame([], webdav_parser::parse_entries('not xml at all <<<', 'x'));
        $this->assertSame([], webdav_parser::parse_versions('<<<'));
    }

    /**
     * A versions response is parsed, skipping the collection node, newest first.
     */
    public function test_parse_versions(): void {
        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
 <d:response>
  <d:href>/remote.php/dav/versions/admin/versions/12345/</d:href>
  <d:propstat><d:prop/><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
 <d:response>
  <d:href>/remote.php/dav/versions/admin/versions/12345/1719734400</d:href>
  <d:propstat><d:prop>
    <d:getlastmodified>Sun, 30 Jun 2024 08:00:00 GMT</d:getlastmodified>
    <d:getcontentlength>1000</d:getcontentlength>
   </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
 <d:response>
  <d:href>/remote.php/dav/versions/admin/versions/12345/1719820800</d:href>
  <d:propstat><d:prop>
    <d:getlastmodified>Mon, 01 Jul 2024 08:00:00 GMT</d:getlastmodified>
    <d:getcontentlength>2000</d:getcontentlength>
    <nc:version-label>Reviewed draft</nc:version-label>
   </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
 </d:response>
</d:multistatus>';

        $versions = webdav_parser::parse_versions($xml);
        $this->assertCount(2, $versions);
        $this->assertSame('1719820800', $versions[0]->versionkey);
        $this->assertSame('Reviewed draft', $versions[0]->label);
        $this->assertSame(2000, $versions[0]->size);
        $this->assertSame('1719734400', $versions[1]->versionkey);
        $this->assertSame('', $versions[1]->label);
    }
}
