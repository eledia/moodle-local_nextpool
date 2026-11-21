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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/fake_graph_client.php');
require_once(__DIR__ . '/fixtures/testable_source.php');

/**
 * Tests for the OneDrive/SharePoint storage backend against a fake Graph (task72, test50, test51).
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(graph_client::class)]
final class source_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    #[\Override]
    protected function tearDown(): void {
        testable_source::$fake = null;
        parent::tearDown();
    }

    /**
     * A storage location on the fake tenant.
     *
     * @param fake_graph_client $client Fake Graph.
     * @param array $config Configuration besides the app credentials.
     * @return source
     */
    private function make_source(fake_graph_client $client, array $config = []): source {
        testable_source::$fake = $client;
        $record = (object) ['id' => 1, 'plugin' => 'onedrive', 'name' => 'Library', 'enabled' => 1];
        return new testable_source($record, $config + [
            'tenantid' => 'contoso.onmicrosoft.com',
            'clientid' => '6bb5e8d9-efa9-455a-8548-3ac82d948080',
            'clientsecret' => 'secret',
            'driveid' => fake_graph_client::DRIVE,
            'basefolder' => 'Kurse/A',
        ]);
    }

    /**
     * Graph requests sent so far, as "METHOD path" without host and query.
     *
     * @param fake_graph_client $client Fake Graph.
     * @return string[]
     */
    private static function calls(fake_graph_client $client): array {
        return array_map(static fn($r) => $r['method'] . ' ' . parse_url($r['url'], PHP_URL_HOST)
            . rawurldecode((string) parse_url($r['url'], PHP_URL_PATH)), $client->requests);
    }

    /**
     * Stat, list and read inside the base folder; revision is the cTag, the id the driveItem id.
     */
    public function test_read_operations(): void {
        $client = new fake_graph_client();
        $id = $client->add_file('Kurse/A/Handout.pdf', 'pdf');
        $client->add_file('Kurse/A/b.txt', 'b');
        $client->add_file('Kurse/A/c.txt', 'c');
        $client->ensure_folder('Kurse/A/Video');
        $client->add_file('Kurse/B/other.txt', 'x');
        $source = $this->make_source($client);

        $entry = $source->stat('Handout.pdf');
        $this->assertSame('Handout.pdf', $entry->path);
        $this->assertFalse($entry->isfolder);
        $this->assertSame(3, $entry->size);
        $this->assertSame('application/pdf', $entry->mimetype);
        $this->assertSame($client->items[$id]['ctag'], $entry->revision);
        $this->assertSame($id, $entry->externalid);
        $this->assertTrue($source->stat('')->isfolder);
        $this->assertNull($source->stat('missing.pdf'));

        // Four children over two pages (@odata.nextLink), folders first.
        $names = array_map(static fn($e) => $e->path, $source->list_entries());
        $this->assertSame(['Video', 'b.txt', 'c.txt', 'Handout.pdf'], $names);

        $target = make_request_directory() . '/out';
        $source->read('Handout.pdf', $target);
        $this->assertSame('pdf', file_get_contents($target));
        $download = end($client->requests);
        $this->assertStringStartsWith('https://download.example/', $download['url']);
        $this->assertArrayNotHasKey('Authorization', $download['headers'], 'The redirect target never gets the token.');
        $this->assertSame(1, $client->tokens, 'One token for all requests.');
    }

    /**
     * The web link is the webUrl of the drive item; missing paths have none (F08.AC10, task84).
     */
    public function test_get_web_url(): void {
        $client = new fake_graph_client();
        $client->add_file('Kurse/A/Hand out.pdf', 'pdf');
        $source = $this->make_source($client);

        $this->assertTrue($source->supports_web_url());
        $this->assertSame(
            'https://contoso.sharepoint.com/sites/Training/Shared%20Documents/Kurse/A/Hand%20out.pdf',
            $source->get_web_url('Hand out.pdf')
        );
        $this->assertSame('https://contoso.sharepoint.com/sites/Training/Shared%20Documents/Kurse/A', $source->get_web_url(''));
        $this->assertNull($source->get_web_url('missing.pdf'));
        $this->expectException(source_exception::class);
        $source->get_web_url('../geheim.xlsx');
    }

    /**
     * Paths that leave the base folder never reach Graph (F03O.AC03).
     */
    public function test_confinement(): void {
        $client = new fake_graph_client();
        $client->add_file('Kurse/geheim.xlsx', 'secret');
        $source = $this->make_source($client);
        foreach (['../geheim.xlsx', 'a/../../geheim.xlsx'] as $path) {
            try {
                $source->read($path, make_request_directory() . '/out');
                $this->fail('Expected exception for ' . $path);
            } catch (source_exception $e) {
                $this->assertSame('error_invalidpath', $e->errorcode);
            }
        }
        $this->assertEmpty($client->requests);
    }

    /**
     * Small files go with one PUT, large ones through an upload session in chunks without the token (F03O.AC10).
     */
    public function test_write_small_and_large(): void {
        $client = new fake_graph_client();
        $source = $this->make_source($client);
        $file = make_request_directory() . '/in';

        file_put_contents($file, 'small');
        $entry = $source->write('Neu/klein.txt', $file);
        $this->assertSame('Neu/klein.txt', $entry->path);
        $this->assertContains('PUT graph.microsoft.com/v1.0/drives/' . fake_graph_client::DRIVE
            . '/root:/Kurse/A/Neu/klein.txt:/content', self::calls($client));

        // Just over the single-PUT limit; chunks of 320 KiB as in production, only fewer of them.
        $content = random_string(64) . str_repeat(random_bytes(1024), 4 * 1024 + 7);
        file_put_contents($file, $content);
        $client->failchunks = 1;
        $entry = $source->write('video.mp4', $file);
        $this->assertSame('video.mp4', $entry->path);
        $stored = $client->items[$entry->externalid]['content'];
        $this->assertSame(sha1($content), sha1($stored), 'Identical content after a resumed upload.');
        $chunks = array_filter($client->requests, static fn($r) => str_starts_with($r['url'], 'https://upload.example/'));
        foreach ($chunks as $chunk) {
            $this->assertArrayNotHasKey('Authorization', $chunk['headers']);
        }
        $this->assertGreaterThan(13, count($chunks));
    }

    /**
     * Deleting removes a file by its driveItem id, ignores missing files and refuses folders (TD-405).
     */
    public function test_delete(): void {
        $client = new fake_graph_client();
        $id = $client->add_file('Kurse/A/kurs-2/7-package.zip', 'zip');
        $keep = $client->add_file('Kurse/A/kurs-2/keep.txt', 'x');
        $source = $this->make_source($client);

        $source->delete('kurs-2/7-package.zip');
        $this->assertArrayNotHasKey($id, $client->items);
        $this->assertArrayHasKey($keep, $client->items);
        $delete = 'DELETE graph.microsoft.com/v1.0/drives/' . fake_graph_client::DRIVE . '/items/' . $id;
        $this->assertContains($delete, self::calls($client));

        // Already gone: nothing to do, no error.
        $source->delete('kurs-2/7-package.zip');

        try {
            $source->delete('kurs-2');
            $this->fail('A folder must not be deleted.');
        } catch (source_exception $e) {
            $this->assertSame('error_notafile', $e->errorcode);
        }
        $this->assertArrayHasKey($keep, $client->items);

        $client->force = ['/items/' . $keep => ['status' => 403]];
        try {
            $source->delete('kurs-2/keep.txt');
            $this->fail('Expected exception.');
        } catch (source_exception $e) {
            $this->assertSame('error_forbidden', $e->errorcode);
        }
    }

    /**
     * A file is found by id after a rename or move in the base folder, not outside it (q46, F03O.AC05, AC15).
     */
    public function test_find_by_externalid(): void {
        $client = new fake_graph_client();
        $id = $client->add_file('Kurse/A/Handout.pdf', 'pdf');
        $source = $this->make_source($client);
        $ctag = $source->stat('Handout.pdf')->revision;

        $client->move($id, 'Kurse/A/Archiv/Handout 2026.pdf');
        $found = $source->find_by_externalid($id);
        $this->assertSame('Archiv/Handout 2026.pdf', $found->path);
        $this->assertSame($ctag, $found->revision, 'Moving does not change the cTag.');

        $client->move($id, 'Kurse/B/Handout.pdf');
        $this->assertNull($source->find_by_externalid($id));
        $this->assertNull($source->find_by_externalid('ITEM999'));
        $this->assertNull($source->find_by_externalid('../x'));

        $other = $client->add_file('Kurse/A/x.pdf', 'x', 'b!drive2');
        $this->assertNull($source->find_by_externalid($other), 'Items of another drive are not found.');
    }

    /**
     * Errors name their cause; throttling is remembered and skips requests until Retry-After (F03O.AC02, AC07).
     */
    public function test_errors(): void {
        $client = new fake_graph_client();
        $client->ensure_folder('Kurse/A');
        $source = $this->make_source($client);

        $client->force = ['/children' => ['status' => 403]];
        $this->assert_error('error_forbidden', fn() => $source->list_entries());

        $client->force = ['/root:/Kurse/A/Nix:/children' => ['status' => 404]];
        $this->assert_error('error_onedrivenotfound', fn() => $source->list_entries('Nix'));

        $client->force = ['/children' => ['status' => 429, 'headers' => ['retry-after' => '120']]];
        $this->assert_error('error_throttled', fn() => $source->list_entries());
        $client->force = [];
        $count = count($client->requests);
        $this->assert_error('error_throttled', fn() => $source->list_entries());
        $this->assertCount($count, $client->requests, 'While throttled, no request goes out.');

        \cache::make('nextpoolsource_onedrive', 'backoff')->purge();
        $this->assertSame([], $source->list_entries());

        $client->force = ['/children' => ['status' => 0]];
        $this->assert_error('error_sourceunavailable', fn() => $source->list_entries());
    }

    /**
     * Paths in errors are escaped: pages show the message as HTML and the path can come from a link (bug121).
     */
    public function test_errors_escape_paths(): void {
        $client = new fake_graph_client();
        $client->ensure_folder('Kurse/A');
        $source = $this->make_source($client);
        $client->force = ['/children' => ['status' => 404]];
        try {
            $source->list_entries('<img src=x onerror=alert(1)> & Co');
            $this->fail('A missing folder must throw.');
        } catch (source_exception $e) {
            $this->assertSame('error_onedrivenotfound', $e->errorcode);
            $this->assertStringNotContainsString('<img', $e->getMessage());
            $this->assertStringContainsString('"/Kurse/A/&lt;img src=x onerror=alert(1)&gt; &amp; Co"', $e->getMessage());
        }

        $client->force = [];
        try {
            $this->make_source($client, ['basefolder' => '<b>Kurse</b> & B'])->test_connection();
            $this->fail('A missing base folder must throw.');
        } catch (source_exception $e) {
            $this->assertSame('error_basefoldernotfound', $e->errorcode);
            $this->assertStringContainsString('"/&lt;b&gt;Kurse&lt;/b&gt; &amp; B"', $e->getMessage());
        }
    }

    /**
     * Wrong credentials and a 401 with a cached token (F03O.AC02, AC08).
     */
    public function test_credentials(): void {
        $client = new fake_graph_client('wrong');
        $source = $this->make_source($client);
        $this->assert_error('error_credentials', fn() => $source->stat('a.txt'));

        // A new secret is used at once; the old token is not.
        $client = new fake_graph_client('secret');
        $client->add_file('Kurse/A/a.txt', 'a');
        $source = $this->make_source($client);
        $this->assertNotNull($source->stat('a.txt'));
        $this->assertSame(1, $client->tokens);

        // Token revoked or expired early: one new token, then the request succeeds.
        $client->tokens++;
        $this->assertNotNull($source->stat('a.txt'));
        $this->assertSame(3, $client->tokens);
    }

    /**
     * The connection test names the missing drive or base folder (F03O.AC01, AC02).
     */
    public function test_test_connection(): void {
        $client = new fake_graph_client();
        $this->assert_error('error_basefoldernotfound', fn() => $this->make_source($client)->test_connection());
        $client->add_file('Kurse/A', 'file, not folder');
        $this->assert_error('error_notafolder', fn() => $this->make_source($client)->test_connection());
        $this->assert_error(
            'error_drivenotfound',
            fn() => $this->make_source($client, ['driveid' => 'b!gone'])->test_connection()
        );
        $client->ensure_folder('Kurse/B');
        $this->make_source($client, ['basefolder' => 'Kurse/B'])->test_connection();
    }

    /**
     * Saving checks the connection and puts the cause at the field (F03O.AC02).
     */
    public function test_validate_config(): void {
        $client = new fake_graph_client();
        $client->ensure_folder('Kurse/A');
        testable_source::$fake = $client;
        $data = [
            'tenantid' => 'contoso.onmicrosoft.com',
            'clientid' => '6bb5e8d9-efa9-455a-8548-3ac82d948080',
            'clientsecret' => 'secret',
            'secretexpires' => time() + YEARSECS,
            'locationurl' => 'https://contoso.sharepoint.com/sites/Training',
            'driveid' => fake_graph_client::DRIVE,
            'basefolder' => 'Kurse/A',
        ];
        $this->assertSame([], testable_source::validate_config($data, []));

        $errors = testable_source::validate_config(['tenantid' => 'a b', 'clientid' => 'x', 'driveid' => ''] + $data, []);
        $this->assertSame(['tenantid', 'clientid', 'driveid'], array_keys($errors));

        $this->assertArrayHasKey('basefolder', testable_source::validate_config(['basefolder' => 'Fehlt'] + $data, []));
        $this->assertArrayHasKey('driveid', testable_source::validate_config(['driveid' => 'b!gone'] + $data, []));
        $client->force = ['/drives/' . rawurlencode(fake_graph_client::DRIVE) => ['status' => 403]];
        $this->assertArrayHasKey('locationurl', testable_source::validate_config($data, []));
        $client->force = [];
        testable_source::$fake = new fake_graph_client('wrong');
        $this->assertArrayHasKey('clientsecret', testable_source::validate_config($data, []));

        // Editing without a new secret keeps the stored one, unless the app changed.
        testable_source::$fake = $client;
        $this->assertSame([], testable_source::validate_config(['clientsecret' => ''] + $data, $data));
        $errors = testable_source::validate_config(
            ['clientsecret' => '', 'clientid' => '00000000-0000-0000-0000-000000000000'] + $data,
            $data
        );
        $this->assertSame(['clientsecret'], array_keys($errors));
    }

    /**
     * Browser addresses map to a site reference and an optional library path (F03O.AC17).
     *
     * @param string $url Address.
     * @param string $siteref Expected site reference.
     * @param string $library Expected library path.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('location_provider')]
    public function test_parse_location(string $url, string $siteref, string $library): void {
        $this->assertSame([$siteref, $library], source::parse_location($url));
    }

    /**
     * Addresses as copied from the browser.
     *
     * @return array
     */
    public static function location_provider(): array {
        return [
            'site' => ['https://contoso.sharepoint.com/sites/Training', 'contoso.sharepoint.com:/sites/Training', ''],
            'site home' => ['https://contoso.sharepoint.com/sites/Training/SitePages/Home.aspx',
                'contoso.sharepoint.com:/sites/Training', ''],
            'library view' => ['https://contoso.sharepoint.com/sites/Training/Shared%20Documents/Forms/AllItems.aspx',
                'contoso.sharepoint.com:/sites/Training', '/sites/Training/Shared Documents'],
            'teams' => ['https://contoso.sharepoint.com/teams/Sales/Freigegebene%20Dokumente',
                'contoso.sharepoint.com:/teams/Sales', '/teams/Sales/Freigegebene Dokumente'],
            'personal' => ['https://contoso-my.sharepoint.com/personal/jane_contoso_com/_layouts/15/onedrive.aspx',
                'contoso-my.sharepoint.com:/personal/jane_contoso_com', ''],
            'root site' => ['https://contoso.sharepoint.com/', 'contoso.sharepoint.com', ''],
            'graph site id' => [fake_graph_client::SITE, fake_graph_client::SITE, ''],
        ];
    }

    /**
     * Addresses that are not a site, library or OneDrive are rejected with a hint.
     */
    public function test_parse_location_rejects(): void {
        $this->assert_error('error_locationurl', fn() => source::parse_location('http://contoso.sharepoint.com/sites/A'));
        $this->assert_error('error_locationurl', fn() => source::parse_location('https://example.org/sites/A'));
        $this->assert_error('error_sharinglink', fn() => source::parse_location('https://contoso.sharepoint.com/:f:/s/A/xyz'));
    }

    /**
     * A site address offers all its libraries, a library address only that one (F03O.AC17).
     */
    public function test_resolve_location(): void {
        $client = new fake_graph_client();
        testable_source::$fake = $client;
        $config = ['tenantid' => 'contoso.onmicrosoft.com', 'clientid' => '6bb5e8d9-efa9-455a-8548-3ac82d948080',
            'clientsecret' => 'secret'];

        $all = testable_source::resolve_location($config + ['locationurl' => 'https://contoso.sharepoint.com/sites/Training']);
        $this->assertSame([fake_graph_client::DRIVE => 'Training – Documents', 'b!drive2' => 'Training – Training'], $all);

        $one = testable_source::resolve_location($config + ['locationurl' =>
            'https://contoso.sharepoint.com/sites/Training/Shared%20Documents/Kurse/Forms/AllItems.aspx']);
        $this->assertSame([fake_graph_client::DRIVE], array_keys($one));

        $this->assert_error('error_sitenotfound', fn() => testable_source::resolve_location(
            $config + ['locationurl' => 'https://contoso.sharepoint.com/sites/Other']
        ));
        $client->force = ['/sites/' => ['status' => 403]];
        $this->assert_error('error_forbidden', fn() => testable_source::resolve_location(
            $config + ['locationurl' => 'https://contoso.sharepoint.com/sites/Training']
        ));
    }

    /**
     * Library names come from SharePoint, outside Moodle: they reach the menu as text only (bug123).
     */
    public function test_library_names_escaped(): void {
        global $PAGE;
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/sources.php');
        $client = new fake_graph_client();
        $client->drives = [fake_graph_client::DRIVE => '<img src=x onerror=alert(1)>Documents'];
        testable_source::$fake = $client;
        $config = ['tenantid' => 'contoso.onmicrosoft.com', 'clientid' => '6bb5e8d9-efa9-455a-8548-3ac82d948080',
            'clientsecret' => 'secret', 'locationurl' => 'https://contoso.sharepoint.com/sites/Training'];
        $choices = testable_source::resolve_location($config);
        $this->assertSame([fake_graph_client::DRIVE => 'Training – Documents'], $choices);

        // A name with entities and an ampersand shows as typed, escaped once.
        $form = new \local_nextpool\form\source_form(null, [
            'plugin' => 'onedrive',
            'class' => testable_source::class,
            'existing' => [],
        ]);
        $form->set_data(['driveid' => 'b!lab', 'drivename' => 'R&D &lt;Lab&gt;']);
        $html = $form->render();
        $this->assertStringContainsString('>R&amp;D &amp;lt;Lab&amp;gt;</option>', $html);
    }

    /**
     * The status check warns 30 days before the app secret expires, with name and date (F03O.AC13, test54).
     */
    public function test_secret_expiry_warning(): void {
        $client = new fake_graph_client();
        $client->ensure_folder('Kurse/A');
        $now = time();
        $source = $this->make_source($client, ['secretexpires' => $now + 31 * DAYSECS, 'drivename' => 'Training – Documents']);
        $this->assertSame([], $source->get_status_warnings());
        $date = userdate($now + 31 * DAYSECS, get_string('strftimedate', 'core_langconfig'));
        $expected = 'Training – Documents · ' . get_string('secretexpireson', 'nextpoolsource_onedrive', $date);
        $this->assertSame($expected, $source->get_summary());

        $soon = $now + 29 * DAYSECS;
        $warnings = $this->make_source($client, ['secretexpires' => $soon])->get_status_warnings();
        $date = userdate($soon, get_string('strftimedate', 'core_langconfig'));
        $this->assertSame([get_string('warning_secretexpires', 'nextpoolsource_onedrive', $date)], $warnings);

        $past = $this->make_source($client, ['secretexpires' => $now - DAYSECS])->get_status_warnings();
        $this->assertStringContainsString('expired', $past[0]);

        // The system status check reports the location by name with the date, as a warning while it is reachable.
        $this->setAdminUser();
        \local_nextpool\local\source\manager::create('onedrive', 'Training library', ['basefolder' => 'Kurse/A']);
        $soonsource = $this->make_source($client, ['secretexpires' => $soon]);
        $check = new class ($soonsource) extends \local_nextpool\check\sources {
            /**
             * Constructor.
             *
             * @param source $fake Storage location to check.
             */
            public function __construct(
                /** @var source Storage location to check. */
                private source $fake,
            ) {
            }

            #[\Override]
            protected function load_source(int $id): \local_nextpool\local\source\base {
                return $this->fake;
            }
        };
        $result = $check->get_result();
        $this->assertSame(\core\check\result::WARNING, $result->get_status());
        $this->assertSame(get_string('checksources_warning_one', 'local_nextpool'), $result->get_summary());
        $this->assertStringContainsString('Training library', $result->get_details());
        $this->assertStringContainsString($date, $result->get_details());

        // Unreachable and expiring: the error wins, the warning stays in the details.
        $client->force = ['/drives/' => ['status' => 404]];
        $result = $check->get_result();
        $this->assertSame(\core\check\result::CRITICAL, $result->get_status());
        $this->assertStringContainsString($date, $result->get_details());
    }

    /**
     * Assert that a call throws a source exception with an error code.
     *
     * @param string $errorcode Expected code.
     * @param callable $call Call.
     */
    private function assert_error(string $errorcode, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $errorcode);
        } catch (source_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }
    /**
     * Bearer tokens are encrypted in the application cache and reused by another client (bug139).
     */
    public function test_token_cache_is_encrypted(): void {
        $client = new fake_graph_client();
        $client->add_file('Kurse/A/a.txt', 'a');
        $this->make_source($client)->stat('a.txt');
        $key = sha1('contoso.onmicrosoft.com|6bb5e8d9-efa9-455a-8548-3ac82d948080|secret');
        $cached = \cache::make('nextpoolsource_onedrive', 'tokens')->get($key);
        $this->assertArrayNotHasKey('token', $cached);
        $this->assertArrayHasKey('encryptedtoken', $cached);
        $plain = \core\encryption::decrypt($cached['encryptedtoken']);
        $this->assertStringNotContainsString($plain, $cached['encryptedtoken']);
        $this->assertStringNotContainsString($plain, json_encode($cached));
        $second = new fake_graph_client();
        $second->tokens = 1; // Same tenant's already-issued token, on a new client object.
        $second->add_file('Kurse/A/a.txt', 'a');
        $this->make_source($second)->stat('a.txt');
        $this->assertSame(1, $second->tokens, 'An encrypted cached token is reused without an identity request.');
        foreach ($second->requests as $request) {
            $this->assertStringNotContainsString('/oauth2/v2.0/token', $request['url']);
        }
    }
}
