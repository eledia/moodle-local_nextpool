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
use nextpoolsource_nextcloud\tests\recording_curl;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/recording_curl.php');

/**
 * Tests for the WebDAV transport of the Nextcloud backend against core curl (bug25, task28).
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(webdav_client::class)]
final class webdav_client_test extends \basic_testcase {
    /**
     * PROPFIND goes through the public curl API with method, depth, body and Basic auth.
     */
    public function test_propfind_through_public_curl_api(): void {
        $curl = new recording_curl();
        $curl->status = 404;
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');

        $this->assertNull($client->stat('Nextpool/Handout.pdf'));
        $this->assertCount(1, $curl->requests);
        $request = $curl->requests[0];
        $this->assertSame('https://cloud.example.org/remote.php/dav/files/nextpool/Nextpool/Handout.pdf', $request['url']);
        $this->assertSame('PROPFIND', $request['options']['CURLOPT_CUSTOMREQUEST']);
        $this->assertStringContainsString('propfind', $request['options']['CURLOPT_POSTFIELDS']);
        $this->assertSame(0, $request['options']['CURLOPT_FOLLOWLOCATION']);
        $this->assertContains('Depth: 0', $request['headers']);
        $this->assertContains('Authorization: Basic ' . base64_encode('nextpool:secret'), $request['headers']);
    }

    /**
     * Errors name the account and the path, so a wrong user ID or folder name shows at once (task41).
     */
    public function test_errors_name_account_and_path(): void {
        $curl = new recording_curl();
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');

        $curl->status = 404;
        try {
            $client->list_folder('Nextpool');
            $this->fail('A missing folder must throw.');
        } catch (\local_nextpool\local\source\source_exception $e) {
            $this->assertSame('error_nextcloudnotfound', $e->errorcode);
            $this->assertStringContainsString('"/Nextpool"', $e->getMessage());
            $this->assertStringContainsString('"nextpool"', $e->getMessage());
        }

        $curl->status = 401;
        try {
            $client->list_folder('nextpool');
            $this->fail('A rejected login must throw.');
        } catch (\local_nextpool\local\source\source_exception $e) {
            $this->assertSame('error_nextcloudauth', $e->errorcode);
            $this->assertStringContainsString('"nextpool"', $e->getMessage());
        }
    }

    /**
     * Account and path in an error are escaped: pages show the message as HTML and the path can come from a link (bug121).
     */
    public function test_errors_escape_account_and_path(): void {
        $curl = new recording_curl();
        $client = new webdav_client($curl, 'https://cloud.example.org', 'R&D', 'secret');
        foreach ([404 => 'error_nextcloudnotfound', 403 => 'error_nextcloudauth'] as $status => $errorcode) {
            $curl->status = $status;
            try {
                $client->list_folder('Kurse/<img src=x onerror=alert(1)>');
                $this->fail('An error status must throw.');
            } catch (\local_nextpool\local\source\source_exception $e) {
                $this->assertSame($errorcode, $e->errorcode);
                $this->assertStringNotContainsString('<img', $e->getMessage());
                $this->assertStringContainsString('"/Kurse/&lt;img src=x onerror=alert(1)&gt;"', $e->getMessage());
                $this->assertStringContainsString('"R&amp;D"', $e->getMessage());
            }
        }
    }

    /**
     * The file id search is a WebDAV SEARCH limited to the scope folder (task71).
     */
    public function test_find_by_fileid_request(): void {
        $curl = new recording_curl();
        $curl->body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            . '<d:response><d:href>/remote.php/dav/files/nextpool/Kurse/A/Neu.pdf</d:href><d:propstat><d:prop>'
            . '<oc:fileid>42</oc:fileid><d:resourcetype/><d:getetag>"e1"</d:getetag></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');

        $found = $client->find_by_fileid('42', 'Kurse/A');
        $this->assertSame('Kurse/A/Neu.pdf', $found['path']);
        $this->assertSame('e1', $found['etag']);
        $request = $curl->requests[0];
        $this->assertSame('https://cloud.example.org/remote.php/dav/', $request['url']);
        $this->assertSame('SEARCH', $request['options']['CURLOPT_CUSTOMREQUEST']);
        $body = $request['options']['CURLOPT_POSTFIELDS'];
        $this->assertStringContainsString('<d:href>/files/nextpool/Kurse/A</d:href>', $body);
        $this->assertStringContainsString('<d:literal>42</d:literal>', $body);
        $this->assertStringContainsString('<d:depth>infinity</d:depth>', $body);

        $this->assertNull($client->find_by_fileid('43', 'Kurse/A'), 'Another id in the answer is no match.');
        $this->assertNull($client->find_by_fileid('4<2', 'Kurse/A'));
        $this->assertCount(2, $curl->requests, 'A non-numeric id is never sent.');
    }

    /**
     * MKCOL is sent per missing segment, without a body.
     */
    public function test_mkcol(): void {
        $curl = new recording_curl();
        $curl->status = 201;
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');

        $client->create_folders('Nextpool/Kurs');
        $this->assertSame(['MKCOL', 'MKCOL'], array_map(fn($r) => $r['options']['CURLOPT_CUSTOMREQUEST'], $curl->requests));
        $this->assertStringEndsWith('/Nextpool/Kurs/', $curl->requests[1]['url']);
        $this->assertSame('', $curl->requests[1]['options']['CURLOPT_POSTFIELDS']);
    }

    /**
     * Downloads stream into the target file instead of returning the body (task18, limit05).
     */
    public function test_download_streams_into_file(): void {
        $curl = new recording_curl();
        $curl->status = 200;
        $curl->body = str_repeat('x', 4096);
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');
        $target = make_request_directory() . '/content';

        $client->download_to_file('Nextpool/Video.mp4', $target);
        $client->download_version_to_file('42', '1700000000', $target . '.v');

        foreach ($curl->requests as $request) {
            $this->assertTrue($request['tofile'], 'GET must write into an open file handle.');
            $this->assertArrayNotHasKey('CURLOPT_RETURNTRANSFER', $request['options']);
            $this->assertSame(0, $request['options']['CURLOPT_FOLLOWLOCATION']);
        }
        $this->assertSame('https://cloud.example.org/remote.php/dav/files/nextpool/Nextpool/Video.mp4', $curl->requests[0]['url']);
        $this->assertSame(
            'https://cloud.example.org/remote.php/dav/versions/nextpool/versions/42/1700000000',
            $curl->requests[1]['url']
        );
        $this->assertSame($curl->body, file_get_contents($target));
        $this->assertSame($curl->body, file_get_contents($target . '.v'));
    }

    /**
     * An error page written into the target file is removed, never taken for content (task18).
     */
    public function test_failed_download_leaves_no_file(): void {
        $curl = new recording_curl();
        $curl->status = 404;
        $curl->body = '<html>Not found</html>';
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');
        $target = make_request_directory() . '/content';

        try {
            $client->download_to_file('Nextpool/gone.pdf', $target);
            $this->fail('A missing file must throw.');
        } catch (\local_nextpool\local\source\source_exception $e) {
            $this->assertSame('error_nextcloudnotfound', $e->errorcode);
        }
        $this->assertFileDoesNotExist($target);
    }

    /**
     * Uploads stream from the source file instead of sending it as a string body (task18).
     */
    public function test_upload_streams_from_file(): void {
        $curl = new recording_curl();
        $curl->status = 201;
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'secret');
        $source = make_request_directory() . '/Handout.pdf';
        file_put_contents($source, str_repeat('p', 2048));

        $client->upload_file('Nextpool/Handout.pdf', $source);

        $this->assertCount(1, $curl->requests);
        $options = $curl->requests[0]['options'];
        $this->assertSame(1, $options['CURLOPT_PUT']);
        $this->assertArrayHasKey('CURLOPT_INFILE', $options);
        $this->assertSame(2048, $options['CURLOPT_INFILESIZE']);
        $this->assertArrayNotHasKey('CURLOPT_POSTFIELDS', $options);
        $this->assertContains('Content-Type: application/pdf', $curl->requests[0]['headers']);
    }

    /**
     * In app-password mode every request carries exactly one Basic Auth header (task23).
     */
    public function test_basic_auth_on_every_request(): void {
        $curl = new recording_curl();
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'app-secret');
        $source = make_request_directory() . '/a.txt';
        file_put_contents($source, 'a');
        $expected = 'Authorization: Basic ' . base64_encode('nextpool:app-secret');

        $curl->status = 207;
        $client->list_folder('Nextpool');
        $client->list_versions('42');
        $curl->status = 201;
        $client->create_folders('Nextpool');
        $client->upload_file('Nextpool/a.txt', $source);
        $curl->status = 200;
        $client->download_to_file('Nextpool/a.txt', $source);

        $this->assertCount(5, $curl->requests);
        foreach ($curl->requests as $request) {
            $auth = array_values(array_filter($request['headers'], fn($h) => stripos($h, 'Authorization:') === 0));
            $this->assertSame([$expected], $auth, $request['url']);
        }
    }

    /**
     * Without an app password (OAuth 2 mode) the client adds no Basic Auth header (task23).
     */
    public function test_no_basic_auth_without_app_password(): void {
        $curl = new recording_curl();
        $curl->status = 404;
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool');

        $client->stat('Nextpool/a.txt');
        $curl->status = 200;
        $client->download_to_file('Nextpool/a.txt', make_request_directory() . '/a.txt');

        foreach ($curl->requests as $request) {
            $this->assertEmpty(array_filter($request['headers'], fn($h) => stripos($h, 'Authorization:') === 0));
        }
    }

    /**
     * Errors never contain the app password (task23).
     */
    public function test_errors_hide_app_password(): void {
        $curl = new recording_curl();
        $client = new webdav_client($curl, 'https://cloud.example.org', 'nextpool', 'app-secret');
        foreach ([401, 404, 500, 0] as $status) {
            $curl->status = $status;
            try {
                $client->list_folder('Nextpool');
                $this->fail('Status ' . $status . ' must throw.');
            } catch (\local_nextpool\local\source\source_exception $e) {
                $this->assertStringNotContainsString('app-secret', $e->getMessage() . ' ' . $e->debuginfo);
                $this->assertStringNotContainsString(base64_encode('nextpool:app-secret'), $e->getMessage());
            }
        }
    }
}
