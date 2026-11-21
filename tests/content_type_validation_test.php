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

namespace local_nextpool;

use core_external\external_api;
use local_nextpool\external\import_version;
use local_nextpool\local\content_type\base;
use local_nextpool\local\item_types;
use local_nextpool\local\source\manager;
use local_nextpool\local\version_manager;

/**
 * Every content type checks the file before it becomes a version (task157, bug205, bug206).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_types::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(import_version::class)]
final class content_type_validation_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Bytes of a ZIP with the given files.
     *
     * @param string[] $files Name => content.
     * @return string
     */
    private static function zip(array $files): string {
        $path = make_request_directory() . '/package.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return (string) file_get_contents($path);
    }

    /**
     * Bytes of a fixture file below dirroot.
     *
     * @param string $path Path relative to dirroot.
     * @return string
     */
    private static function fixture(string $path): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/' . $path);
    }

    /**
     * Bytes of the xAPI package that is wrongly imported in the cases below (bug205).
     *
     * @return string
     */
    private static function xapi_package(): string {
        return self::zip([
            'tincan.xml' => '<?xml version="1.0"?><tincan xmlns="http://projecttincan.com/tincan.xsd"><activities>'
                . '<activity id="https://example.com/a" type="http://adlnet.gov/expapi/activities/course">'
                . '<name>A</name><launch lang="en">index.html</launch></activity></activities></tincan>',
            'index.html' => '<p>Start</p>',
        ]);
    }

    /**
     * Files that fit their type, and files that do not.
     *
     * @return array<string,array{0:string,1:\Closure,2:string,3:bool}> Type, content, file name, accepted.
     */
    public static function file_provider(): array {
        $quiz = fn() => self::zip([
            'questions.xml' => self::fixture('local/nextpool/tests/fixtures/quiz/questions.xml'),
            'quiz.json' => self::fixture('local/nextpool/tests/fixtures/quiz/quiz.json'),
        ]);
        return [
            'file takes any file' => ['file', fn() => self::xapi_package(), 'package.zip', true],
            'url with a link' => ['url', fn() => "[InternetShortcut]\r\nURL=https://example.com/\r\n", 'link.url', true],
            'url with an xAPI package (bug205)' => ['url', fn() => self::xapi_package(), 'package.zip', false],
            'url with text but no address' => ['url', fn() => "Just a note\n", 'note.txt', false],
            'url with a large file' => ['url', fn() => 'https://example.com/' . str_repeat('a', 70000), 'link.txt', false],
            'h5p with a package' => ['h5p', fn() => self::fixture('h5p/tests/fixtures/greeting-card.h5p'), 'card.h5p', true],
            'h5p with an xAPI package' => ['h5p', fn() => self::xapi_package(), 'package.zip', false],
            'h5p with a text file' => ['h5p', fn() => 'not a package', 'card.h5p', false],
            'scorm with a package' => ['scorm', fn() => self::fixture('mod/scorm/tests/packages/singlescobasic.zip'),
                'golf.zip', true],
            'scorm with an xAPI package' => ['scorm', fn() => self::xapi_package(), 'package.zip', false],
            'book with chapters' => ['book', fn() => self::zip(['01_intro.html' => '<p>Hi</p>']), 'book.zip', true],
            'book without chapters' => ['book', fn() => self::zip(['img/a.png' => 'x']), 'book.zip', false],
            'book with a PDF' => ['book', fn() => "%PDF-1.4\0binary", 'book.pdf', false],
            'page with HTML' => ['page', fn() => '<p>Hello</p>', 'page.html', true],
            'page with a ZIP and index.html' => ['page', fn() => self::zip(['index.html' => '<p>Hi</p>']), 'page.zip', true],
            'page with a ZIP without index.html' => ['page', fn() => self::zip(['a.html' => '<p>Hi</p>']), 'page.zip', false],
            'page with a PDF' => ['page', fn() => "%PDF-1.4\0binary", 'page.pdf', false],
            'quiz with a package' => ['quiz', $quiz, 'quiz.zip', true],
            'quiz with an xAPI package' => ['quiz', fn() => self::xapi_package(), 'package.zip', false],
            'xapi with a package' => ['xapi', fn() => self::fixture('mod/nextpool/tests/fixtures/upload/fire-safety.zip'),
                'package.zip', true],
            'xapi with an H5P package' => ['xapi', fn() => self::fixture('h5p/tests/fixtures/greeting-card.h5p'),
                'package.zip', false],
        ];
    }

    /**
     * Importing from the storage location accepts only files of the type, with a message (bug205).
     *
     * @param string $type Item type.
     * @param \Closure $content Returns the file content.
     * @param string $filename File name.
     * @param bool $accepted Whether the file becomes a version.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('file_provider')]
    public function test_import_checks_the_file(string $type, \Closure $content, string $filename, bool $accepted): void {
        if (!item_types::is_placeable($type)) {
            $this->markTestSkipped('No handler for ' . $type . ' (mod_nextpool not installed).');
        }
        $source = $this->generator->create_source();
        $this->generator->put_file($source, $filename, $content());
        $item = $this->generator->create_item(['sourceid' => $source->get_id(), 'type' => $type]);
        try {
            $version = version_manager::create_from_source((int) $item->id, $filename);
            $this->assertTrue($accepted, 'The file should have been refused.');
            $this->assertSame(1, (int) $version->versionnr);
        } catch (\moodle_exception $e) {
            $this->assertFalse($accepted, 'Refused: ' . $e->getMessage());
            $this->assertNotEmpty($e->getMessage());
            $this->assertNull(version_manager::get_latest((int) $item->id), 'No version is stored.');
        }
    }

    /**
     * The message names the file the type expects (bug205, bug206).
     */
    public function test_message_names_the_expected_file(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'package.zip', self::xapi_package());
        $item = $this->generator->create_item(['sourceid' => $source->get_id(), 'type' => 'h5p']);
        $this->expectExceptionMessage(get_string(
            'error_filenotfortype',
            'local_nextpool',
            get_string('expectedfile_h5p', 'local_nextpool')
        ));
        version_manager::create_from_source((int) $item->id, 'package.zip');
    }

    /**
     * The web service refuses a file of another type and leaves no version (bug205).
     */
    public function test_web_service_checks_the_file(): void {
        $item = $this->generator->create_published_item(['type' => 'url'], "https://example.com/\n", 'link.txt');
        $this->generator->put_file(manager::get((int) $item->sourceid), 'link.txt', self::xapi_package());
        try {
            $result = import_version::execute($item->uuid, 'link.txt');
            external_api::clean_returnvalue(import_version::execute_returns(), $result);
            $this->fail('The package must not become a version of a link item.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_filenotfortype', $e->errorcode);
        }
        $this->assertSame(1, (int) version_manager::get_latest((int) $item->id)->versionnr);

        // A link file is imported as before.
        $this->generator->put_file(manager::get((int) $item->sourceid), 'link.txt', "https://example.com/new\n");
        $result = external_api::clean_returnvalue(
            import_version::execute_returns(),
            import_version::execute($item->uuid, 'link.txt')
        );
        $this->assertSame(2, $result['versionnr']);
    }

    /**
     * An upload for a new item is checked before it is written into the storage location (bug205).
     */
    public function test_upload_checks_the_file_before_writing(): void {
        $source = $this->generator->create_source();
        $item = $this->generator->create_item(['sourceid' => $source->get_id(), 'type' => 'scorm']);
        $localfile = make_request_directory() . '/upload';
        file_put_contents($localfile, '<p>not a package</p>');
        try {
            version_manager::create_from_upload((int) $item->id, $localfile, 'golf.zip');
            $this->fail('The file must not become a version.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_filenotfortype', $e->errorcode);
        }
        $this->assertNull($source->stat('golf.zip'), 'Nothing is written into the storage location.');
    }

    /**
     * Versions stored before the check stay; the check only applies to new versions.
     */
    public function test_existing_versions_stay(): void {
        global $DB;
        $item = $this->generator->create_published_item(['type' => 'file'], self::xapi_package(), 'package.zip');
        $DB->set_field('local_nextpool_item', 'type', 'url', ['id' => $item->id]);
        $item = $DB->get_record('local_nextpool_item', ['id' => $item->id]);
        $this->assertSame(1, (int) version_manager::get((int) $item->currentversionid)->versionnr);
        $this->assertNotFalse(version_manager::get_file((int) $item->currentversionid));
    }

    /**
     * Each type with a handler names its file; the extensions decide which files the import page offers (bug206).
     */
    public function test_expected_file_and_extensions(): void {
        foreach (['file', 'url', 'h5p', 'scorm', 'book', 'page', 'quiz'] as $type) {
            $this->assertNotSame('', item_types::get_handler($type)->get_expected_file(), $type);
        }
        $this->assertTrue(item_types::get_handler('file')->accepts_filename('anything.bin'));
        $this->assertTrue(item_types::get_handler('url')->accepts_filename('Link.URL'));
        $this->assertFalse(item_types::get_handler('url')->accepts_filename('package.zip'));
        $this->assertTrue(item_types::get_handler('h5p')->accepts_filename('card.h5p'));
        $this->assertFalse(item_types::get_handler('h5p')->accepts_filename('card.zip'));
        $this->assertTrue(item_types::get_handler('page')->accepts_filename('index.htm'));
        $this->assertFalse(item_types::get_handler('page')->accepts_filename('noextension'));
    }

    /**
     * The item form offers only types that can be placed, the most common first, and keeps the current type (bug207).
     */
    public function test_form_menu(): void {
        $menu = item_types::get_form_menu();
        $this->assertSame('file', array_key_first($menu));
        foreach (array_keys($menu) as $type) {
            $this->assertTrue(item_types::is_placeable($type), $type);
        }
        $this->assertArrayNotHasKey('generic', $menu);
        $this->assertArrayNotHasKey('assign', $menu);
        $this->assertSame(item_types::get_menu()['generic'], item_types::get_form_menu('generic')['generic']);
        $this->assertEqualsCanonicalizing(
            array_values(array_filter(item_types::all(), [item_types::class, 'is_placeable'])),
            array_keys($menu)
        );
    }
}
