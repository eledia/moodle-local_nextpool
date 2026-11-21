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

use local_nextpool\local\direct_upload;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Storage location for direct uploads and the service direct_upload (M06 task78c, test91).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(direct_upload::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(placement_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source_manager::class)]
final class direct_upload_test extends \advanced_testcase {
    /** @var \stdClass Course the packages are uploaded from. */
    private \stdClass $course;
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;
    /** @var \mod_nextpool_generator */
    private \mod_nextpool_generator $modgenerator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        // The real xAPI handler of mod_nextpool checks the packages (task78d).
        item_types::reset_cache();
    }

    #[\Override]
    protected function tearDown(): void {
        item_types::reset_cache();
        parent::tearDown();
    }

    /**
     * Local file with a package.
     *
     * @param array $files Changes to the fixture package, see mod_nextpool_generator::create_package().
     * @return string Absolute path.
     */
    private function package(array $files = []): string {
        $path = make_request_directory() . '/upload.zip';
        file_put_contents($path, $this->modgenerator->create_package($files));
        return $path;
    }

    /**
     * A Nextpool course module as the activity form creates it, without placement.
     *
     * @return int Course module id.
     */
    private function create_cm(): int {
        return (int) $this->getDataGenerator()->create_module('nextpool', ['course' => $this->course->id])->cmid;
    }

    /**
     * Metadata from the activity form.
     *
     * @return \stdClass
     */
    private function meta(): \stdClass {
        return (object) ['title' => 'Fire safety', 'description' => '<p>Short</p>', 'license' => 'cc-4.0'];
    }

    /**
     * Upload the fixture package into a new activity.
     *
     * @return \stdClass The placement.
     */
    private function upload(): \stdClass {
        return direct_upload::create($this->course->id, $this->create_cm(), $this->package(), 'Fire safety.zip', $this->meta());
    }

    /**
     * Without a setting the first enabled Moodle file storage takes uploads; a chosen one does not fall back.
     */
    public function test_storage_location_setting(): void {
        $this->assertSame(0, direct_upload::get_sourceid());
        $this->assertNull(direct_upload::get_source());

        $disabled = $this->generator->create_source('moodle', [], 'Disabled');
        source_manager::set_enabled($disabled->get_id(), false);
        $first = $this->generator->create_source('moodle', [], 'First');
        $second = $this->generator->create_source('moodle', [], 'Second');
        $this->assertSame($first->get_id(), direct_upload::get_sourceid());
        $this->assertSame(
            [$first->get_id() => 'First', $second->get_id() => 'Second'],
            direct_upload::get_writable_sources()
        );
        $this->assertTrue(source_manager::can_delete($first->get_id()), 'A default is no setting.');

        direct_upload::set_sourceid($second->get_id());
        $this->assertSame($second->get_id(), direct_upload::get_source()->get_id());

        // The chosen location is protected from deletion until another one is chosen.
        $this->assertFalse(source_manager::can_delete($second->get_id()));
        try {
            source_manager::delete($second->get_id());
            $this->fail('The storage location for direct uploads must not be deleted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sourceisuploadtarget', $e->errorcode);
        }

        // Disabled: no fallback to the first one, teachers only see the catalogue.
        source_manager::set_enabled($second->get_id(), false);
        $this->assertSame(0, direct_upload::get_sourceid());

        $this->expectException(\moodle_exception::class);
        direct_upload::set_sourceid($disabled->get_id());
    }

    /**
     * One upload becomes item, file, published version and placement; the check of the storage location
     * afterwards finds nothing new (M06.AC02, AC09).
     */
    public function test_create(): void {
        global $DB;
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Uploads']);
        $cmid = $this->create_cm();
        $package = $this->package();
        $sink = $this->redirectEvents();

        $placement = direct_upload::create($this->course->id, $cmid, $package, 'Fire safety.zip', $this->meta());

        $events = array_map(static fn($event) => get_class($event), $sink->get_events());
        $sink->close();
        $item = item_manager::get((int) $placement->itemid);
        $this->assertSame('xapi', $item->type);
        $this->assertSame('Fire safety', $item->title);
        $this->assertSame(\context_course::instance($this->course->id)->id, (int) $item->contextid);
        $this->assertSame((int) $this->course->id, (int) $item->origincourseid);
        $this->assertSame($source->get_id(), (int) $item->sourceid);
        $this->assertSame(status::PUBLISHED, $item->status);
        $authorids = array_map('intval', array_column(item_manager::get_authors((int) $item->id), 'userid'));
        $this->assertSame([(int) get_admin()->id], $authorids);

        $version = version_manager::get((int) $item->currentversionid);
        $path = 'kurs-' . $this->course->id . '/' . $item->id . '-Fire safety.zip';
        $this->assertSame(1, (int) $version->versionnr);
        $this->assertSame(status::PUBLISHED, $version->status);
        $this->assertSame('Fire safety.zip', $version->filename);
        $this->assertSame($path, $version->sourceref);
        $entry = $source->stat($path);
        $this->assertSame($entry->revision, $version->sourcerevision);
        $this->assertSame($entry->externalid, $version->sourceexternalid);
        $this->assertSame(sha1_file($package), $version->contenthash);

        $this->assertSame($cmid, (int) $placement->cmid);
        $this->assertSame('auto', $placement->policy);
        $this->assertSame((int) $version->id, (int) $placement->appliedversionid);
        $this->assertSame(['place'], array_column(placement_manager::get_log((int) $placement->id), 'action'));
        $this->assertContains(\local_nextpool\event\placement_created::class, $events);
        $this->assertContains(\local_nextpool\event\version_published::class, $events);

        // No second draft from the storage location check (TD-405).
        $this->assertNull(version_manager::check_for_update((int) $item->id));
        $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
        $this->assertTrue(direct_upload::can_upload(get_fast_modinfo($this->course)->get_cm($cmid)));
    }

    /**
     * Without a licence from the form the item gets the site default, or "All rights reserved" when the site has
     * none or "unknown"; a chosen licence stays (task169).
     */
    public function test_create_default_license(): void {
        $this->generator->create_source('moodle');
        $meta = static fn(array $fields = []): \stdClass
            => (object) (['title' => 'Fire safety', 'description' => '<p>Short</p>'] + $fields);

        set_config('sitedefaultlicense', 'unknown');
        $placement = direct_upload::create($this->course->id, $this->create_cm(), $this->package(), 'a.zip', $meta());
        $this->assertSame('allrightsreserved', item_manager::get((int) $placement->itemid)->license);

        set_config('sitedefaultlicense', 'cc-4.0');
        $placement = direct_upload::create($this->course->id, $this->create_cm(), $this->package(), 'b.zip', $meta());
        $this->assertSame('cc-4.0', item_manager::get((int) $placement->itemid)->license);

        $placement = direct_upload::create(
            $this->course->id,
            $this->create_cm(),
            $this->package(),
            'c.zip',
            $meta(['license' => 'public'])
        );
        $this->assertSame('public', item_manager::get((int) $placement->itemid)->license);
    }

    /**
     * Two packages with the same file name in the same course do not overwrite each other (TD-404).
     */
    public function test_same_file_name(): void {
        $source = $this->generator->create_source('moodle');
        $first = $this->upload();
        $second = direct_upload::create(
            $this->course->id,
            $this->create_cm(),
            $this->package(['index.html' => 'other']),
            'Fire safety.zip',
            $this->meta()
        );
        $paths = array_map(
            static fn($entry) => $entry->path,
            $source->list_entries('kurs-' . $this->course->id)
        );
        $this->assertCount(2, $paths);
        $this->assertContains(direct_upload::get_path($this->course->id, (int) $first->itemid, 'Fire safety.zip'), $paths);
        $this->assertContains(direct_upload::get_path($this->course->id, (int) $second->itemid, 'Fire safety.zip'), $paths);
        $fallback = direct_upload::get_path($this->course->id, (int) $first->itemid, '../');
        $this->assertSame('kurs-' . $this->course->id . '/' . $first->itemid . '-package.zip', $fallback);
    }

    /**
     * Packages the xAPI handler refuses, and the reason.
     *
     * @return array
     */
    public static function invalid_package_provider(): array {
        return [
            'no tincan.xml' => [['tincan.xml' => null], 'error_package_notincan'],
            'unsafe path' => [['../evil.html' => 'x'], 'error_package_unsafepath'],
            'above the size limit' => [['big.bin' => str_repeat('x', 20000)], 'error_package_toolarge'],
        ];
    }

    /**
     * A package the type refuses creates nothing and never reaches the storage location (M06.AC03).
     *
     * @param array $files Changes to the fixture package.
     * @param string $error Expected error code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_package_provider')]
    public function test_create_invalid_package(array $files, string $error): void {
        global $CFG, $DB;
        $CFG->maxbytes = 10000;
        $source = $this->generator->create_source('moodle');
        $cmid = $this->create_cm();
        try {
            direct_upload::create($this->course->id, $cmid, $this->package($files), 'broken.zip', $this->meta());
            $this->fail('Expected exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame($error, $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(item_manager::TABLE));
        $this->assertSame(0, $DB->count_records(placement_manager::TABLE));
        $this->assertSame([], $source->list_entries(''));
    }

    /**
     * Without a storage location for direct uploads nothing is created.
     */
    public function test_create_without_storage_location(): void {
        global $DB;
        try {
            $this->upload();
            $this->fail('Expected exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_nouploadsource', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(item_manager::TABLE));
    }

    /**
     * A storage location that cannot be reached leaves no item behind; the error names the cause (M06.AC03).
     */
    public function test_create_storage_location_fails(): void {
        // Rollback of the service's transaction, not of the test.
        $this->preventResetByRollback();
        global $DB;
        $nextcloud = $this->generator->create_source('nextcloud', ['basefolder' => 'Nextpool', 'authmode' => 'apppassword']);
        direct_upload::set_sourceid($nextcloud->get_id());
        try {
            $this->upload();
            $this->fail('Expected exception.');
        } catch (source_exception $e) {
            $this->assertSame('error_sourcenotconfigured', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(item_manager::TABLE));
        $this->assertSame(0, $DB->count_records(version_manager::TABLE));
    }

    /**
     * A failure after the file was written deletes it again and rolls back item and version (TD-405).
     */
    public function test_create_failure_after_write(): void {
        // Rollback of the service's transaction, not of the test.
        $this->preventResetByRollback();
        global $DB;
        $source = $this->generator->create_source('moodle');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        try {
            direct_upload::create($this->course->id, (int) $page->cmid, $this->package(), 'Fire safety.zip', $this->meta());
            $this->fail('Expected exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_wrongmodule', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(item_manager::TABLE));
        $this->assertSame(0, $DB->count_records(version_manager::TABLE));
        $this->assertSame(0, $DB->count_records(placement_manager::TABLE));
        $folder = 'kurs-' . $this->course->id;
        $this->assertSame([], $source->stat($folder) ? $source->list_entries($folder) : []);
    }

    /**
     * A new package becomes a published version written into the activity; the file keeps its path (M06.AC09).
     */
    public function test_add_version(): void {
        $source = $this->generator->create_source('moodle');
        $placement = $this->upload();
        $item = item_manager::get((int) $placement->itemid);
        $path = version_manager::get((int) $item->currentversionid)->sourceref;
        // The setting may change later; the item keeps its storage location (TD-404).
        $other = $this->generator->create_source('moodle', [], 'Other');
        direct_upload::set_sourceid($other->get_id());

        $package = $this->package(['index.html' => '<html><body>Version 2</body></html>']);
        $meta = (object) ['changelog' => 'Typo fixed'];
        $placement = direct_upload::add_version((int) $placement->id, $package, 'Fire safety v2.zip', false, 0, $meta);

        $version = version_manager::get((int) $placement->appliedversionid);
        $this->assertSame(2, (int) $version->versionnr);
        $this->assertSame(status::PUBLISHED, $version->status);
        $this->assertSame('Fire safety v2.zip', $version->filename);
        $this->assertSame('Typo fixed', $version->changelog);
        $this->assertSame($path, $version->sourceref);
        $this->assertSame((int) $version->id, (int) item_manager::get((int) $item->id)->currentversionid);
        $this->assertSame(sha1_file($package), $source->stat($path)->revision);
        $this->assertSame([], $other->list_entries(''));
        $this->assertNull(version_manager::check_for_update((int) $item->id));

        // The same package again: nothing to do, the storage location is not touched.
        try {
            direct_upload::add_version((int) $placement->id, $package, 'Fire safety v2.zip', false);
            $this->fail('Expected exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_unchanged', $e->errorcode);
        }
    }

    /**
     * A package of another xAPI activity needs the confirmation; without it the previous file is written back.
     */
    public function test_add_version_other_activity(): void {
        // Rollback of the service's transaction, not of the test.
        $this->preventResetByRollback();
        $source = $this->generator->create_source('moodle');
        $placement = $this->upload();
        $item = item_manager::get((int) $placement->itemid);
        $path = version_manager::get((int) $item->currentversionid)->sourceref;
        $before = $source->stat($path)->revision;
        $package = $this->package(['tincan.xml' => $this->modgenerator->create_manifest('https://example.com/xapi/other')]);

        try {
            direct_upload::add_version((int) $placement->id, $package, 'other.zip', false);
            $this->fail('Expected exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_noinplace', $e->errorcode);
        }
        $this->assertCount(1, version_manager::get_versions((int) $item->id));
        $this->assertSame($before, $source->stat($path)->revision, 'The previous content is back.');

        $placement = direct_upload::add_version((int) $placement->id, $package, 'other.zip', true);
        $this->assertSame(2, (int) version_manager::get((int) $placement->appliedversionid)->versionnr);
        $this->assertSame(sha1_file($package), $source->stat($path)->revision);
    }

    /**
     * Without a snapshot of the previous version, a failed upload leaves the file in place instead of deleting it
     * (bug107).
     */
    public function test_undo_write_without_snapshot_keeps_file(): void {
        $source = $this->generator->create_source('moodle');
        $placement = $this->upload();
        $item = item_manager::get((int) $placement->itemid);
        $latest = version_manager::get((int) $item->currentversionid);
        $path = (string) $latest->sourceref;
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_nextpool',
            version_manager::FILEAREA,
            (int) $latest->id
        );
        $this->assertNull(version_manager::get_file((int) $latest->id), 'The snapshot is gone.');
        // The new package was written; a later step failed and the write is undone.
        $this->generator->put_file($source, $path, 'new package');

        $undo = new \ReflectionMethod(direct_upload::class, 'undo_write');
        $undo->invoke(null, $source, $path, $latest);

        $this->assertDebuggingCalled();
        $this->assertNotNull($source->stat($path), 'The file of the item is still there.');
    }

    /**
     * Once the editorial team widened the item, teachers can no longer upload new packages (TD-407).
     */
    public function test_upload_refused_after_widening(): void {
        $this->generator->create_source('moodle');
        $placement = $this->upload();
        $cm = get_fast_modinfo($this->course)->get_cm((int) $placement->cmid);
        $this->assertTrue(direct_upload::can_upload($cm));

        item_manager::update((int) $placement->itemid, (object) ['contextid' => \context_system::instance()->id]);
        $this->assertFalse(direct_upload::can_upload($cm));
        $this->expectExceptionMessage(get_string('error_uploadnotallowed', 'local_nextpool'));
        direct_upload::add_version((int) $placement->id, $this->package(['index.html' => 'v2']), 'v2.zip', true);
    }

    /**
     * Activities without placement or with a placement of a catalogue item cannot take uploads.
     */
    public function test_can_upload_other_activities(): void {
        $cmid = $this->create_cm();
        $this->assertFalse(direct_upload::can_upload(get_fast_modinfo($this->course)->get_cm($cmid)));

        $item = $this->modgenerator->create_xapi_item();
        $placement = $this->modgenerator->place($item, $this->course);
        $this->assertFalse(direct_upload::can_upload(get_fast_modinfo($this->course)->get_cm((int) $placement->cmid)));
    }

    /**
     * A restored version keeps its number; an existing number is refused (option versionnr, task78f).
     */
    public function test_create_from_file_versionnr(): void {
        $source = $this->generator->create_source('moodle');
        $item = $this->generator->create_item(['type' => 'xapi', 'sourceid' => $source->get_id()]);
        $package = $this->package();
        $entry = $source->write('restored.zip', $package);

        $version = version_manager::create_from_file((int) $item->id, $package, 'restored.zip', $entry, ['versionnr' => 4]);
        $this->assertSame(4, (int) $version->versionnr);
        $this->assertSame(status::DRAFT, $version->status);
        $this->assertSame('restored.zip', $version->sourceref);

        $this->expectException(\coding_exception::class);
        $changed = $this->package(['index.html' => 'v5']);
        version_manager::create_from_file((int) $item->id, $changed, 'restored.zip', $entry, ['versionnr' => 4]);
    }
}
