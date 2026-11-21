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

use local_nextpool\local\source\source_exception;
use nextpoolsource_onedrive\local\graph_client;
use nextpoolsource_onedrive\tests\recording_curl;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/recording_curl.php');

/**
 * Graph's real transport path through the public Moodle curl API, without network requests (task148).
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(graph_client::class)]
final class graph_client_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A client with an OAuth response followed by the supplied API responses.
     *
     * @param recording_curl $curl Recorded transport.
     * @param array $responses API responses.
     * @return graph_client
     */
    private function client(recording_curl $curl, array $responses): graph_client {
        $curl->responses = array_merge([['status' => 200, 'body' => '{"access_token":"token-one","expires_in":3600}']], $responses);
        return new graph_client($curl, 'example.test', 'application-id', 'secret-value');
    }

    /**
     * OAuth uses the body, Graph uses the bearer header, and automatic redirects are disabled.
     */
    public function test_authenticated_request_uses_real_curl_methods(): void {
        $curl = new recording_curl();
        $client = $this->client($curl, [['status' => 200, 'body' => '{"id":"file-one"}']]);
        $this->assertSame(['id' => 'file-one'], $client->get('/drives/one/items/two'));
        $this->assertCount(2, $curl->requests);
        $token = $curl->requests[0];
        $this->assertStringContainsString('/oauth2/v2.0/token', $token['url']);
        parse_str($token['options']['CURLOPT_POSTFIELDS'], $body);
        $this->assertSame('secret-value', $body['client_secret']);
        $this->assertSame('client_credentials', $body['grant_type']);
        $this->assertFalse((bool) array_filter($token['headers'], static fn($h) => str_starts_with($h, 'Authorization:')));
        $api = $curl->requests[1];
        $this->assertContains('Authorization: Bearer token-one', $api['headers']);
        foreach ($curl->requests as $request) {
            $this->assertSame(0, $request['options']['CURLOPT_FOLLOWLOCATION']);
            $this->assertStringNotContainsString('secret-value', $request['url']);
        }
    }

    /**
     * A preauthenticated download redirect never receives the Graph bearer token; its body streams to disk.
     */
    public function test_download_redirect_resets_authorization_and_streams(): void {
        $curl = new recording_curl();
        $client = $this->client($curl, [
            ['status' => 302, 'body' => 'redirect body', 'headers' => ['Location' => ['https://download.example.test/file']]],
            ['status' => 200, 'body' => 'actual package'],
        ]);
        $target = make_request_directory() . '/package';
        $client->download('/drives/one/items/two', $target);
        $this->assertSame('actual package', file_get_contents($target));
        $this->assertTrue($curl->requests[1]['download']);
        $this->assertTrue($curl->requests[2]['download']);
        $this->assertSame('https://download.example.test/file', $curl->requests[2]['url']);
        $headers = $curl->requests[2]['headers'];
        $this->assertFalse((bool) array_filter($headers, static fn($h) => str_starts_with($h, 'Authorization:')));
    }

    /**
     * An error page is removed instead of being mistaken for a successfully downloaded version.
     */
    public function test_failed_download_removes_error_body(): void {
        $curl = new recording_curl();
        $client = $this->client($curl, [['status' => 403, 'body' => '<html>Forbidden</html>']]);
        $target = make_request_directory() . '/package';
        try {
            $client->download('/drives/one/items/two', $target);
            $this->fail('A forbidden download must fail.');
        } catch (source_exception $e) {
            $this->assertSame('error_forbidden', $e->errorcode);
        }
        $this->assertFileDoesNotExist($target);
    }

    /**
     * A small upload streams from a file without adding Basic authentication beside the bearer header.
     */
    public function test_upload_streams_without_basic_auth(): void {
        $curl = new recording_curl();
        $client = $this->client($curl, [['status' => 201, 'body' => '{"id":"uploaded"}']]);
        $source = make_request_directory() . '/upload';
        file_put_contents($source, str_repeat('x', 2048));
        $this->assertSame(['id' => 'uploaded'], $client->upload('/drives/one/root:/file:', $source));
        $request = $curl->requests[1];
        $this->assertSame(1, $request['options']['CURLOPT_PUT']);
        $this->assertSame(2048, $request['options']['CURLOPT_INFILESIZE']);
        $this->assertArrayHasKey('CURLOPT_INFILE', $request['options']);
        $this->assertArrayNotHasKey('CURLOPT_POSTFIELDS', $request['options']);
        $this->assertEmpty($request['options']['CURLOPT_USERPWD'] ?? null);
        $this->assertContains('Authorization: Bearer token-one', $request['headers']);
    }

    /**
     * A transport failure remains an unavailable storage location, without exposing credentials.
     */
    public function test_transport_error_is_classified_without_secret(): void {
        $curl = new recording_curl();
        $client = $this->client($curl, [['status' => 0, 'body' => '', 'error' => 'transport error']]);
        try {
            $client->get('/drives/one/items/two');
            $this->fail('A transport error must fail.');
        } catch (source_exception $e) {
            $this->assertSame('error_sourceunavailable', $e->errorcode);
            $this->assertStringNotContainsString('secret-value', $e->getMessage() . ' ' . $e->debuginfo);
        }
    }
}
