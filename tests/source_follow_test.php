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

use local_nextpool\local\item_manager;
use local_nextpool\local\source\base;
use local_nextpool\local\version_manager;
use local_nextpool\output\item_page;
use local_nextpool\task\poll_sources;

/**
 * Items follow their file by its backend id and show when it is gone (q46, q50, task71, test52).
 *
 * Runs against the Moodle file backend, whose file ids survive a rename or move like those of
 * Nextcloud and OneDrive.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\nextpoolsource_moodle\source::class)]
final class source_follow_test extends \advanced_testcase {
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
     * Move or rename a file of the Moodle backend, keeping its file id.
     *
     * @param base $source Backend instance.
     * @param string $from Full path, e.g. "Catalogue/a.txt".
     * @param string $to Full path.
     */
    private function move(base $source, string $from, string $to): void {
        $fs = get_file_storage();
        $file = $fs->get_file(
            \context_system::instance()->id,
            'nextpoolsource_moodle',
            'content',
            $source->get_id(),
            '/' . (dirname($from) === '.' ? '' : dirname($from) . '/'),
            basename($from)
        );
        $file->rename('/' . (dirname($to) === '.' ? '' : dirname($to) . '/'), basename($to));
    }

    /**
     * An item with one imported version of Catalogue/A/handout.txt.
     *
     * @return array{0:base,1:\stdClass,2:\stdClass} Source, item, version.
     */
    private function setup_item(): array {
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $this->generator->put_file($source, 'A/handout.txt', 'one');
        $version = version_manager::create_from_source($item->id, 'A/handout.txt');
        return [$source, $item, $version];
    }

    /**
     * The backend id is stored with the version.
     */
    public function test_version_stores_externalid(): void {
        [$source, , $version] = $this->setup_item();
        $this->assertSame($source->stat('A/handout.txt')->externalid, $version->sourceexternalid);
    }

    /**
     * Moved without rename inside the base folder: path followed, no version (F03O.AC05).
     */
    public function test_moved_file_is_followed(): void {
        [$source, $item, $version] = $this->setup_item();
        $this->move($source, 'Catalogue/A/handout.txt', 'Catalogue/B/handout.txt');

        $this->assertNull(version_manager::check_for_update($item->id));
        $this->assertSame('B/handout.txt', version_manager::get($version->id)->sourceref);
        $this->assertCount(1, version_manager::get_versions($item->id));
        $this->assertSame(0, (int) item_manager::get($item->id)->timesourcemissing);

        // A later content change is found at the new place.
        $this->generator->put_file($source, 'B/handout.txt', 'two');
        $this->assertSame('B/handout.txt', version_manager::check_for_update($item->id)->path);
    }

    /**
     * Renamed: a new version, even with identical content (q50, F03O.AC14).
     */
    public function test_renamed_file_creates_version(): void {
        [$source, $item, $version] = $this->setup_item();
        $this->move($source, 'Catalogue/A/handout.txt', 'Catalogue/A/handout-2026.txt');

        $entry = version_manager::check_for_update($item->id);
        $this->assertSame('A/handout-2026.txt', $entry->path);
        $v2 = version_manager::create_from_source($item->id, $entry->path);
        $this->assertSame(2, (int) $v2->versionnr);
        $this->assertSame('handout-2026.txt', $v2->filename);
        $this->assertSame($version->contenthash, $v2->contenthash);
        $this->assertSame($version->sourceexternalid, $v2->sourceexternalid);
        $this->assertNull(version_manager::check_for_update($item->id));
    }

    /**
     * Deleted: the item shows "not found" and keeps its versions; it clears once the file is back (F03O.AC09).
     */
    public function test_deleted_file_is_marked_missing(): void {
        global $PAGE;
        [$source, $item] = $this->setup_item();
        $this->move($source, 'Catalogue/A/handout.txt', 'Catalogue/A/tmp.bin');
        get_file_storage()->get_file(
            \context_system::instance()->id,
            'nextpoolsource_moodle',
            'content',
            $source->get_id(),
            '/Catalogue/A/',
            'tmp.bin'
        )->delete();

        $this->assertNull(version_manager::check_for_update($item->id));
        $missing = (int) item_manager::get($item->id)->timesourcemissing;
        $this->assertGreaterThan(0, $missing);
        $this->assertCount(1, version_manager::get_versions($item->id));

        // A second check keeps the first time it was missed.
        $this->waitForSecond();
        version_manager::check_for_update($item->id);
        $this->assertSame($missing, (int) item_manager::get($item->id)->timesourcemissing);

        $data = (new item_page(item_manager::get($item->id)))->export_for_template($PAGE->get_renderer('core'));
        $this->assertNotSame('', $data['sourcemissing']);

        $this->generator->put_file($source, 'A/handout.txt', 'one');
        version_manager::check_for_update($item->id);
        $this->assertSame(0, (int) item_manager::get($item->id)->timesourcemissing);
    }

    /**
     * Moved out of the base folder: "not found", versions stay (F03O.AC15).
     */
    public function test_moved_outside_basefolder_is_missing(): void {
        [$source, $item, $version] = $this->setup_item();
        $this->move($source, 'Catalogue/A/handout.txt', 'Private/handout.txt');

        $this->assertNull($source->find_by_externalid((string) $version->sourceexternalid));
        $this->assertNull(version_manager::check_for_update($item->id));
        $this->assertGreaterThan(0, (int) item_manager::get($item->id)->timesourcemissing);
        $this->assertSame('A/handout.txt', version_manager::get($version->id)->sourceref);
    }

    /**
     * Versions from before task71 learn the id of their file on the next check.
     */
    public function test_old_version_learns_externalid(): void {
        global $DB;
        [$source, $item, $version] = $this->setup_item();
        $DB->set_field(version_manager::TABLE, 'sourceexternalid', null, ['id' => $version->id]);

        $this->assertNull(version_manager::check_for_update($item->id));
        $this->assertSame($source->stat('A/handout.txt')->externalid, version_manager::get($version->id)->sourceexternalid);
    }

    /**
     * Unchanged content and name of a moved file: no version, but the new place is recorded.
     */
    public function test_reimport_of_moved_file_records_path(): void {
        [$source, $item, $version] = $this->setup_item();
        $this->move($source, 'Catalogue/A/handout.txt', 'Catalogue/B/handout.txt');
        try {
            version_manager::create_from_source($item->id, 'B/handout.txt');
            $this->fail('Unchanged content must not become a version');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_unchanged', $e->errorcode);
        }
        $this->assertSame('B/handout.txt', version_manager::get($version->id)->sourceref);
    }

    /**
     * Another storage location's file is never found by id.
     */
    public function test_find_by_externalid_other_location(): void {
        [, , $version] = $this->setup_item();
        $other = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $this->assertNull($other->find_by_externalid((string) $version->sourceexternalid));
        $this->assertNull($other->find_by_externalid('abc'));
    }

    /**
     * Polling imports a renamed file from its new path (F06, q50).
     */
    public function test_poll_imports_renamed_file(): void {
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $item = $this->generator->create_published_item(['sourceid' => $source->get_id()], 'one', 'A/handout.txt');
        $this->move($source, 'Catalogue/A/handout.txt', 'Catalogue/A/renamed.txt');

        ob_start();
        (new poll_sources())->execute();
        ob_end_clean();
        $latest = version_manager::get_latest($item->id);
        $this->assertSame(2, (int) $latest->versionnr);
        $this->assertSame('A/renamed.txt', $latest->sourceref);
    }
}
