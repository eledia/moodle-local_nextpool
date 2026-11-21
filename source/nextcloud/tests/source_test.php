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

use local_nextpool\local\source\base;
use local_nextpool\local\source\manager;
use local_nextpool\local\source\source_exception;
use nextpoolsource_nextcloud\local\webdav_client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_webdav_client.php');

/**
 * Tests for the Nextcloud backend against an in-memory WebDAV server.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(webdav_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(manager::class)]
final class source_test extends \advanced_testcase {
    /**
     * A source wired to a fake client.
     *
     * @param fake_webdav_client $client Fake server.
     * @param array $config Configuration.
     * @return source
     */
    private function make_source(fake_webdav_client $client, array $config = []): source {
        $record = (object) ['id' => 1, 'plugin' => 'nextcloud', 'name' => 'Cloud', 'enabled' => 1];
        return new class ($record, $config, $client) extends source {
            /**
             * Constructor.
             *
             * @param \stdClass $record Record.
             * @param array $config Configuration.
             * @param webdav_client $fake Client to return.
             */
            public function __construct(
                \stdClass $record,
                array $config,
                /** @var webdav_client Client to return. */
                private webdav_client $fake,
            ) {
                parent::__construct($record, $config);
            }

            #[\Override]
            protected function create_client(): webdav_client {
                return $this->fake;
            }
        };
    }

    /**
     * Stat, list and read are confined to the base folder and map paths back.
     */
    public function test_read_operations(): void {
        $client = new fake_webdav_client();
        $client->add_file('Catalogue/Safety/Hand out.pdf', 'pdf');
        $client->add_file('Catalogue/readme.txt', 'hello');
        $client->add_file('Private/salary.xlsx', 'secret');
        $source = $this->make_source($client, ['basefolder' => 'Catalogue']);

        $entry = $source->stat('Safety/Hand out.pdf');
        $this->assertSame('Safety/Hand out.pdf', $entry->path);
        $this->assertSame(3, $entry->size);
        $this->assertSame(sha1('pdf'), $entry->revision);
        $this->assertSame('application/pdf', $entry->mimetype);
        $this->assertNotSame('', $entry->externalid);
        $this->assertSame(
            'https://cloud.example.org/nc/remote.php/dav/files/nextpool/Catalogue/Safety/Hand%20out.pdf',
            end($client->requests)[1]
        );

        $this->assertNull($source->stat('missing.pdf'));
        $this->assertNull($source->stat('missing-folder/missing.pdf'));
        $this->assertSame([], $source->list_entries('missing-folder'));
        $this->assertSame([], $source->list_entries('missing-folder/child'));
        $list = $source->list_entries();
        $this->assertSame(['Safety', 'readme.txt'], array_map(fn($e) => $e->path, $list));
        $this->assertTrue($list[0]->isfolder);

        $target = make_request_directory() . '/out';
        $source->read('readme.txt', $target);
        $this->assertSame('hello', file_get_contents($target));
    }

    /**
     * Paths outside the base folder never reach the server (bug01).
     */
    public function test_confinement(): void {
        $client = new fake_webdav_client();
        $client->add_file('Private/salary.xlsx', 'secret');
        $source = $this->make_source($client, ['basefolder' => 'Catalogue']);

        try {
            $source->read('../Private/salary.xlsx', make_request_directory() . '/out');
            $this->fail('Expected exception.');
        } catch (source_exception $e) {
            $this->assertSame('error_invalidpath', $e->errorcode);
        }
        $this->assertEmpty($client->requests);
    }

    /**
     * A file is found by its id after a move inside the base folder, but not outside it (q46, task71).
     */
    public function test_find_by_externalid(): void {
        $client = new fake_webdav_client();
        $client->add_file('Catalogue/A/handout.pdf', 'pdf');
        $source = $this->make_source($client, ['basefolder' => 'Catalogue']);
        $id = $source->stat('A/handout.pdf')->externalid;

        $client->move('Catalogue/A/handout.pdf', 'Catalogue/B/handout-v2.pdf');
        $this->assertNull($source->stat('A/handout.pdf'));
        $found = $source->find_by_externalid($id);
        $this->assertSame('B/handout-v2.pdf', $found->path);
        $this->assertSame($id, $found->externalid);
        $this->assertStringContainsString('<d:href>/files/nextpool/Catalogue</d:href>', end($client->searchbodies));

        $client->move('Catalogue/B/handout-v2.pdf', 'Private/handout-v2.pdf');
        $this->assertNull($source->find_by_externalid($id), 'Outside the base folder the file is not found.');
        $this->assertNull($source->find_by_externalid('../1'), 'Only numeric ids are searched.');
    }

    /**
     * The web link opens a file or folder by its Nextcloud id; missing paths have none (F08.AC10, task84).
     */
    public function test_get_web_url(): void {
        $client = new fake_webdav_client();
        $client->add_file('Catalogue/Safety/Hand out.pdf', 'pdf');
        $source = $this->make_source($client, ['basefolder' => 'Catalogue']);

        $this->assertTrue($source->supports_web_url());
        $id = $source->stat('Safety/Hand out.pdf')->externalid;
        $this->assertSame('https://cloud.example.org/nc/index.php/f/' . $id, $source->get_web_url('Safety/Hand out.pdf'));
        $this->assertMatchesRegularExpression('#^https://cloud\.example\.org/nc/index\.php/f/\d+$#', $source->get_web_url(''));
        $this->assertNull($source->get_web_url('missing.pdf'));
        $this->expectException(source_exception::class);
        $source->get_web_url('../Private/salary.xlsx');
    }

    /**
     * Writing creates missing folders and returns the stored entry.
     *
     * @param int $status Status the server answers a PUT into a missing folder with.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missing_parent_status_provider')]
    public function test_write_creates_folders(int $status): void {
        $client = new fake_webdav_client();
        $client->missingparentstatus = $status;
        $client->add_file('Catalogue/keep.txt', 'x');
        $source = $this->make_source($client, ['basefolder' => 'Catalogue']);
        $file = make_request_directory() . '/in';
        file_put_contents($file, 'new content');

        $entry = $source->write('New/Deep/doc.txt', $file);
        $this->assertSame('New/Deep/doc.txt', $entry->path);
        $this->assertSame('new content', $client->tree['Catalogue/New/Deep/doc.txt']);
        $mkcol = ['MKCOL', 'https://cloud.example.org/nc/remote.php/dav/files/nextpool/Catalogue/New/'];
        $this->assertContains($mkcol, $client->requests);
    }

    /**
     * Statuses for a PUT into a missing folder: the WebDAV standard and Nextcloud 32 (bug231).
     *
     * @return array<string,array{0:int}>
     */
    public static function missing_parent_status_provider(): array {
        return [
            'conflict (WebDAV)' => [409],
            'not found (Nextcloud 32)' => [404],
        ];
    }

    /**
     * Deleting removes a file inside the base folder, ignores missing files and refuses folders (TD-405).
     */
    public function test_delete(): void {
        $client = new fake_webdav_client();
        $client->add_file('Catalogue/kurs-2/7-package.zip', 'zip');
        $client->add_file('Catalogue/kurs-2/keep.txt', 'x');
        $source = $this->make_source($client, ['basefolder' => 'Catalogue']);

        $source->delete('kurs-2/7-package.zip');
        $this->assertArrayNotHasKey('Catalogue/kurs-2/7-package.zip', $client->tree);
        $this->assertSame('x', $client->tree['Catalogue/kurs-2/keep.txt']);
        $delete = ['DELETE', 'https://cloud.example.org/nc/remote.php/dav/files/nextpool/Catalogue/kurs-2/7-package.zip'];
        $this->assertContains($delete, $client->requests);

        // Already gone: nothing to do, no error.
        $source->delete('kurs-2/7-package.zip');

        try {
            $source->delete('kurs-2');
            $this->fail('A folder must not be deleted.');
        } catch (source_exception $e) {
            $this->assertSame('error_notafile', $e->errorcode);
        }
        $this->assertArrayHasKey('Catalogue/kurs-2/keep.txt', $client->tree);

        $client->forcestatus = 403;
        $this->expectException(source_exception::class);
        $source->delete('kurs-2/keep.txt');
    }

    /**
     * HTTP errors map to readable exceptions.
     *
     * @return array
     */
    public static function error_provider(): array {
        return [
            'unauthorised' => [401, 'error_nextcloudauth'],
            'forbidden' => [403, 'error_nextcloudauth'],
            'server error' => [500, 'error_sourcehttp'],
            'transport' => [0, 'error_sourceunavailable'],
        ];
    }

    /**
     * Mapping HTTP errors.
     *
     * @param int $status Simulated status.
     * @param string $errorcode Expected error code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('error_provider')]
    public function test_errors(int $status, string $errorcode): void {
        $client = new fake_webdav_client();
        $client->forcestatus = $status;
        $source = $this->make_source($client);
        try {
            $source->list_entries();
            $this->fail('Expected exception.');
        } catch (source_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Incomplete configuration is reported instead of sending requests.
     */
    public function test_not_configured(): void {
        $this->resetAfterTest();
        $source = new source((object) ['id' => 1, 'name' => 'x', 'enabled' => 1], ['authmode' => 'apppassword']);
        $this->expectException(source_exception::class);
        $source->stat('a.txt');
    }

    /**
     * Configuration validation for both authentication modes.
     */
    public function test_validate_config(): void {
        $this->assertArrayHasKey('issuerid', source::validate_config(['authmode' => 'oauth2', 'issuerid' => 0], []));
        $this->assertEmpty(source::validate_config(['authmode' => 'oauth2', 'issuerid' => 3], []));

        $errors = source::validate_config(['authmode' => 'apppassword', 'serverurl' => 'ftp://x', 'username' => '',
            'apppassword' => ''], []);
        $this->assertEqualsCanonicalizing(['serverurl', 'username', 'apppassword'], array_keys($errors));

        // When editing, an empty password keeps the stored one.
        $this->assertEmpty(source::validate_config(['authmode' => 'apppassword', 'serverurl' => 'https://x',
            'username' => 'u', 'apppassword' => ''], ['serverurl' => 'https://x', 'apppassword' => 'stored']));
    }

    /**
     * An app password is only accepted for an https server URL.
     */
    public function test_validate_config_requires_https(): void {
        $errors = source::validate_config(['authmode' => 'apppassword', 'serverurl' => 'http://cloud.example',
            'username' => 'u', 'apppassword' => 'secret'], []);
        $this->assertSame(['serverurl'], array_keys($errors));
        $this->assertSame(get_string('error_serverurlhttps', 'nextpoolsource_nextcloud'), $errors['serverurl']);

        $this->assertEmpty(source::validate_config(['authmode' => 'apppassword', 'serverurl' => 'HTTPS://cloud.example',
            'username' => 'u', 'apppassword' => 'secret'], []));
    }

    /**
     * A stored app password configured with http is not used.
     */
    public function test_http_app_password_not_used(): void {
        $this->resetAfterTest();
        $source = new source((object) ['id' => 1, 'name' => 'x', 'enabled' => 1], [
            'authmode' => 'apppassword', 'serverurl' => 'http://cloud.example', 'username' => 'u', 'apppassword' => 'secret',
        ]);
        $this->expectException(source_exception::class);
        $source->stat('a.txt');
    }

    /**
     * A changed server URL requires the app password again, in validation and when storing.
     */
    public function test_server_change_drops_app_password(): void {
        $this->resetAfterTest();
        $existing = ['authmode' => 'apppassword', 'serverurl' => 'https://a.example', 'username' => 'u',
            'apppassword' => 'stored'];
        $moved = ['authmode' => 'apppassword', 'serverurl' => 'https://b.example', 'username' => 'u', 'apppassword' => ''];

        $errors = source::validate_config($moved, $existing);
        $this->assertSame(['apppassword'], array_keys($errors));
        $this->assertSame(get_string('error_apppasswordreenter', 'nextpoolsource_nextcloud'), $errors['apppassword']);
        $this->assertEmpty(source::validate_config(['apppassword' => 'new'] + $moved, $existing));

        $instance = manager::create('nextcloud', 'Cloud', $existing);
        manager::update($instance->get_id(), 'Cloud', $moved, true);
        $stored = manager::get_stored_config($instance->get_id());
        $this->assertSame('https://b.example', $stored['serverurl']);
        $this->assertEmpty($stored['apppassword'] ?? '');

        manager::update($instance->get_id(), 'Cloud', ['apppassword' => 'new'] + $moved, true);
        manager::update($instance->get_id(), 'Renamed', $moved, true);
        $this->assertSame('new', manager::get_stored_config($instance->get_id())['apppassword']);
    }
    /**
     * Missing child folders are empty, but a missing configured base folder must fail the connection check (bug236).
     */
    public function test_connection_still_reports_missing_base_folder(): void {
        $source = $this->make_source(new fake_webdav_client(), ['basefolder' => 'Missing']);
        $this->assertSame([], $source->list_entries('child'));
        $this->assertNull($source->stat('child/file.pdf'));
        $this->expectException(source_exception::class);
        $this->expectExceptionMessage('Missing');
        $source->test_connection();
    }
}
