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

use local_nextpool\event\version_published;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Tests for item versions (F02).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
final class version_manager_test extends \advanced_testcase {
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
     * Publish a version through review.
     *
     * @param int $versionid Version id.
     * @return \stdClass
     */
    private function publish(int $versionid): \stdClass {
        version_manager::set_status($versionid, status::REVIEW);
        return version_manager::set_status($versionid, status::PUBLISHED);
    }

    /**
     * Importing stores an immutable snapshot that survives changes in the backend.
     */
    public function test_create_from_source_snapshot(): void {
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $this->generator->put_file($source, 'Safety/handout.pdf', 'first');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        $v1 = version_manager::create_from_source($item->id, '/Safety//handout.pdf', [
            'label' => 'Initial', 'changelog' => 'First release',
        ]);
        $this->assertEquals(1, $v1->versionnr);
        $this->assertSame(status::DRAFT, $v1->status);
        $this->assertSame('Safety/handout.pdf', $v1->sourceref);
        $this->assertSame('handout.pdf', $v1->filename);
        $this->assertSame(sha1('first'), $v1->contenthash);
        $this->assertEquals(5, $v1->filesize);
        $this->assertSame('application/pdf', $v1->mimetype);
        $this->assertSame('Initial', $v1->label);

        $this->generator->put_file($source, 'Safety/handout.pdf', 'second');
        $v2 = version_manager::create_from_source($item->id, 'Safety/handout.pdf', ['basedonversionid' => $v1->id]);
        $this->assertEquals(2, $v2->versionnr);
        $this->assertEquals($v1->id, $v2->basedonversionid);

        // Version 1 still delivers its own content.
        $this->assertSame('first', version_manager::get_file($v1->id)->get_content());
        $this->assertSame('second', version_manager::get_file($v2->id)->get_content());
        $this->assertSame([2, 1], array_map(fn($v) => (int) $v->versionnr, version_manager::get_versions($item->id)));
    }

    /**
     * The label is the month of the import and the file name without extension (task103).
     */
    public function test_build_label(): void {
        $time = make_timestamp(2026, 11, 14, 12);
        $this->assertSame('2026-11_Handout 1', version_manager::build_label('Handout 1.pdf', $time));
        $this->assertSame('2026-11_archive.v2', version_manager::build_label('archive.v2.zip', $time));
        $this->assertSame(255, \core_text::strlen(version_manager::build_label(str_repeat('a', 300) . '.pdf', $time)));
    }

    /**
     * An import without a label gets the automatic one; a label passed by the caller wins (task103).
     */
    public function test_import_gets_automatic_label(): void {
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $this->generator->put_file($source, 'Safety/handout.pdf', 'first');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        $v1 = version_manager::create_from_source($item->id, 'Safety/handout.pdf');
        $this->assertSame(version_manager::build_label('handout.pdf'), $v1->label);
        $this->assertStringEndsWith('_handout', $v1->label);

        $this->generator->put_file($source, 'Safety/handout.pdf', 'second');
        $v2 = version_manager::create_from_source($item->id, 'Safety/handout.pdf', ['label' => '  Own name ']);
        $this->assertSame('Own name', $v2->label);
    }

    /**
     * Label and change log can be changed afterwards, except for rejected and cancelled versions (task103).
     */
    public function test_update_details(): void {
        global $DB;
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $this->generator->put_file($source, 'handout.pdf', 'first');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $version = version_manager::create_from_source($item->id, 'handout.pdf');

        $updated = version_manager::update_details((int) $version->id, ' 2026 edition ', '<p>New</p>', FORMAT_HTML);
        $this->assertSame('2026 edition', $updated->label);
        $this->assertSame('<p>New</p>', $updated->changelog);
        // Empty values are stored as none.
        $updated = version_manager::update_details((int) $version->id, '', '', FORMAT_HTML);
        $this->assertNull($updated->label);
        $this->assertNull($updated->changelog);

        $DB->set_field(version_manager::TABLE, 'status', status::REJECTED, ['id' => $version->id]);
        $this->assertFalse(version_manager::can_edit_details(version_manager::get((int) $version->id)));
        $this->expectException(\moodle_exception::class);
        version_manager::update_details((int) $version->id, 'x', '', FORMAT_HTML);
    }

    /**
     * Identical content does not create a new version.
     */
    public function test_unchanged_content_rejected(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'a.txt', 'same');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        version_manager::create_from_source($item->id, 'a.txt');

        $this->expectExceptionMessage(get_string('error_unchanged', 'local_nextpool'));
        version_manager::create_from_source($item->id, 'a.txt');
    }

    /**
     * Missing files, folders and paths outside the base folder are rejected.
     *
     * @return array
     */
    public static function bad_path_provider(): array {
        return [
            'missing' => ['missing.txt', 'error_notfound'],
            'folder' => ['Sub', 'error_notafile'],
            'outside base folder' => ['../outside.txt', 'error_invalidpath'],
        ];
    }

    /**
     * Rejecting bad paths.
     *
     * @param string $path Path.
     * @param string $errorcode Expected error code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_path_provider')]
    public function test_bad_paths(string $path, string $errorcode): void {
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Base']);
        $this->generator->put_file($source, 'Sub/a.txt', 'x');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        try {
            version_manager::create_from_source($item->id, $path);
            $this->fail('Expected an exception.');
        } catch (source_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Publishing sets the current version; withdrawing falls back to the previous published one.
     */
    public function test_publish_and_withdraw(): void {
        $source = $this->generator->create_source();
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $this->generator->put_file($source, 'a.txt', 'one');
        $v1 = version_manager::create_from_source($item->id, 'a.txt');
        $this->generator->put_file($source, 'a.txt', 'two');
        $v2 = version_manager::create_from_source($item->id, 'a.txt');

        $sink = $this->redirectEvents();
        $published = $this->publish($v1->id);
        $this->assertNotEmpty($published->timepublished);
        $this->assertEquals(get_admin()->id, $published->publishedby);
        $this->assertEquals($v1->id, item_manager::get($item->id)->currentversionid);
        $this->assertCount(1, array_filter($sink->get_events(), fn($e) => $e instanceof version_published));

        $this->publish($v2->id);
        $this->assertEquals($v2->id, item_manager::get($item->id)->currentversionid);

        version_manager::set_status($v2->id, status::WITHDRAWN);
        $this->assertEquals($v1->id, item_manager::get($item->id)->currentversionid);

        // The last published version of a published item cannot be withdrawn.
        item_manager::set_status($item->id, status::REVIEW);
        item_manager::set_status($item->id, status::PUBLISHED);
        try {
            version_manager::set_status($v1->id, status::WITHDRAWN);
            $this->fail('Withdrawing the last published version must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_lastpublishedversion', $e->errorcode);
        }
        // The change is rolled back at once, not only when the request ends (bug34).
        $this->assertSame(status::PUBLISHED, version_manager::get($v1->id)->status);
        $this->assertEquals($v1->id, item_manager::get($item->id)->currentversionid);
    }

    /**
     * Change detection compares the backend revision with the latest version.
     */
    public function test_check_for_update(): void {
        $source = $this->generator->create_source();
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $this->assertNull(version_manager::check_for_update($item->id));

        $this->generator->put_file($source, 'a.txt', 'one');
        version_manager::create_from_source($item->id, 'a.txt');
        $this->assertNull(version_manager::check_for_update($item->id));

        $this->generator->put_file($source, 'a.txt', 'two');
        $changed = version_manager::check_for_update($item->id);
        $this->assertNotNull($changed);
        $this->assertSame(sha1('two'), $changed->revision);
    }

    /**
     * A new revision of the same file with unchanged content is recorded on the latest version (bug36).
     */
    public function test_unchanged_content_records_revision(): void {
        global $DB;
        $source = $this->generator->create_source();
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $this->generator->put_file($source, 'a.txt', 'one');
        $v1 = version_manager::create_from_source($item->id, 'a.txt');
        // The storage location reports another revision (e.g. a new ETag) for the same bytes.
        $DB->set_field(version_manager::TABLE, 'sourcerevision', 'old-etag', ['id' => $v1->id]);
        $this->assertNotNull(version_manager::check_for_update($item->id));

        try {
            version_manager::create_from_source($item->id, 'a.txt');
            $this->fail('Unchanged content must not become a version');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_unchanged', $e->errorcode);
        }
        $this->assertSame(sha1('one'), version_manager::get($v1->id)->sourcerevision);
        $this->assertNull(version_manager::check_for_update($item->id), 'Polling does not download the file again.');
    }

    /**
     * Two versions compare field by field; the content counts as changed by checksum (F02).
     */
    public function test_compare(): void {
        global $PAGE;
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item([], 'version 1', 'handout.pdf');
        $v1 = version_manager::get((int) $item->currentversionid);
        $v2 = $generator->publish_new_version((int) $item->id, 'version 2', 'handout.pdf');
        $v3 = $generator->publish_new_version((int) $item->id, 'version 3', 'handout.pdf');

        // The order of the arguments does not matter; the older version is "from".
        $comparison = version_manager::compare((int) $v3->id, (int) $v1->id);
        $this->assertEquals($v1->id, $comparison['from']->id);
        $this->assertTrue($comparison['rows']['versionnr']['changed']);
        $this->assertTrue($comparison['rows']['contenthash']['changed']);
        $this->assertFalse($comparison['rows']['filename']['changed']);
        $this->assertSame([2, 3], array_map(fn($v) => (int) $v->versionnr, $comparison['between']));

        $same = version_manager::compare((int) $v2->id, (int) $v2->id);
        $this->assertFalse($same['rows']['contenthash']['changed']);
        $this->assertSame([], $same['between']);

        // The item page links each version to the one before it.
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/item.php', ['id' => $item->id]);
        $data = (new \local_nextpool\output\item_page(item_manager::get((int) $item->id)))
            ->export_for_template($PAGE->get_renderer('core'));
        $links = array_column($data['versions'], 'compareurl', 'versionnr');
        $this->assertStringContainsString('from=' . $v2->id, html_entity_decode($links[3]));
        $this->assertSame('', $links[1]);

        $other = $generator->create_published_item();
        $this->expectException(\coding_exception::class);
        version_manager::compare((int) $v1->id, (int) $other->currentversionid);
    }

    /**
     * A version of the storage location check is shown with its own sentence, and separators are not read out
     * (bug63, U-32, U-27).
     */
    public function test_item_page_version_lines(): void {
        global $PAGE;
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $generator->put_file(\local_nextpool\local\source\manager::get((int) $item->sourceid), 'handout.pdf', 'version 2');
        version_manager::create_from_source((int) $item->id, 'handout.pdf', ['createdby' => 0]);

        $PAGE->set_url('/local/nextpool/item.php', ['id' => $item->id]);
        $output = $PAGE->get_renderer('core');
        $data = (new \local_nextpool\output\item_page(item_manager::get((int) $item->id)))->export_for_template($output);
        $created = array_column($data['versions'], 'created', 'versionnr');
        $this->assertStringStartsWith(
            explode('{$a}', get_string('createdautomaticallyon', 'local_nextpool', '{$a}'))[0],
            $created[2]
        );
        $this->assertStringContainsString(fullname(get_admin()), $created[1]);
        $html = $output->render_from_template('local_nextpool/item_page', $data);
        $this->assertStringContainsString('<span aria-hidden="true">·</span>', $html);
        $this->assertDoesNotMatchRegularExpression('/\)\s·\s/u', $html, 'No separator outside aria-hidden.');
    }
}
