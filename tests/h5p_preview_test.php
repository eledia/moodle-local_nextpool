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

use local_nextpool\local\content_type\h5p;
use local_nextpool\local\direct_upload;
use local_nextpool\local\h5p_guard;
use local_nextpool\local\h5p_preview;
use local_nextpool\local\h5p_preview_sender;
use local_nextpool\local\item_manager;
use local_nextpool\local\preview_assets;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\tests\unsupported_h5p_preview;

/**
 * Real Core H5P deployment behind an anonymous, permission-checked preview adapter (F16).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(h5p_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(h5p_preview_sender::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(preview_assets::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_nextpool_pluginfile')]
final class h5p_preview_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Viewer without system H5P deployment rights. */
    private \stdClass $teacher;
    /** @var \local_nextpool_generator Generator. */
    private \local_nextpool_generator $generator;
    /** @var string Real Core package fixture, with a safe image for proxy tests. */
    private string $path;

    #[\Override]
    protected function setUp(): void {
        global $CFG, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        if (
            !h5p_preview::supported() && !in_array($this->name(), [
            'test_old_core_fails_before_writing_cache', 'test_legacy_system_cache_is_removed_without_support_guard',
            'test_repack_budget_counts_only_content',
            ], true)
        ) {
            $this->markTestSkipped('This Core does not provide native H5P content/export access guards.');
        }
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', [
            'firstname' => 'PrivateViewer', 'lastname' => 'Identity',
        ]);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $this->path = make_request_directory() . '/card.h5p';
        copy($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p', $this->path);
        $zip = new \ZipArchive();
        $zip->open($this->path);
        $zip->addFromString('content/pixel.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));
        $zip->close();
        $PAGE->set_context(\context_course::instance($this->course->id));
        $PAGE->set_url('/local/nextpool/preview_h5p.php', ['courseid' => $this->course->id]);
        require_once($CFG->dirroot . '/local/nextpool/lib.php');
    }

    /**
     * Publish a real, site-wide H5P snapshot as the current editor.
     *
     * @param bool $libraries Publication choice.
     * @return \stdClass Current version.
     */
    private function published(bool $libraries = true): \stdClass {
        $item = $this->generator->create_published_item(['type' => 'h5p'], file_get_contents($this->path), 'card.h5p', $libraries);
        return version_manager::get((int) $item->currentversionid);
    }

    /**
     * Install fixture libraries without creating any Core content record.
     */
    private function libraries(): void {
        $file = \core_h5p\helper::create_fake_stored_file_from_path($this->path, get_admin()->id, \context_system::instance());
        \core_h5p\helper::save_h5p(new \core_h5p\factory(), $file, new \stdClass(), false, true);
        $file->delete();
        $this->assertNotEmpty((new \core_h5p\factory())->get_framework()->getLibraryId('H5P.GreetingCard', 1, 0));
    }

    /**
     * An authorised teacher needs no system deploy permission and sends no identity or learning state to H5P.
     */
    public function test_anonymous_preview_without_tracking_or_viewed_event(): void {
        global $DB, $PAGE;
        $version = $this->published();
        $snapshot = version_manager::get_file((int) $version->id);
        $this->setUser($this->teacher);
        $this->assertFalse(has_capability('moodle/h5p:deploy', \context_system::instance()));
        set_config('enablesavestate', 1);
        set_config('savestatefreq', 2);
        set_config('enablesavestate', 1, 'mod_h5pactivity');
        $sink = $this->redirectEvents();
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        h5p_preview::add_assets_to_page($preview);
        $this->assertNotEmpty(h5p_preview::render($preview));
        $settings = $preview['settings'];
        $this->assertSame([], $settings['user']);
        $this->assertSame('', $settings['moodleComponent']);
        $this->assertFalse($settings['reportingIsEnabled']);
        $this->assertFalse($settings['postUserStatistics']);
        $this->assertFalse($settings['saveFreq']);
        $this->assertSame('', $settings['ajax']['contentUserData']);
        $json = json_encode($settings);
        $this->assertStringNotContainsString(fullname($this->teacher), $json);
        $content = $settings['contents']['cid-' . $preview['h5pid']];
        $this->assertSame('', $content['exportUrl']);
        $this->assertSame('', $content['embedCode']);
        // H5P.getPath appends '/images/...' to this directory URL; IDs must remain separate route segments.
        $this->assertStringContainsString(
            '/previewh5pmedia/' . $version->id . '/' . $this->course->id,
            rawurldecode($content['contentUrl'])
        );
        $this->assertStringNotContainsString('/core_h5p/content/', $content['contentUrl']);
        $head = $PAGE->requires->get_head_code($PAGE, $PAGE->get_renderer('core'));
        $this->assertStringContainsString('"user":[]', $head);
        $this->assertStringNotContainsString(fullname($this->teacher), $head);
        foreach ($sink->get_events() as $event) {
            $this->assertNotInstanceOf(\core_h5p\event\h5p_viewed::class, $event);
        }
        foreach (['h5pactivity_attempts', 'h5pactivity_attempts_results', 'xapi_states', 'local_eledia_lrs_statement'] as $table) {
            $this->assertSame(0, $DB->count_records($table));
        }
        $package = h5p_preview::package($version);
        $this->assertEquals(h5p_preview::context()->id, $package->get_contextid());
        $this->assertEquals(h5p_guard::get_owner(), $package->get_userid());
        $this->assertFalse(get_file_storage()->get_file_by_hash(h5p_preview::key((int) $version->id)));
        $this->assertEquals(h5p_guard::get_owner(), $snapshot->get_userid());
        $this->assertNull(\core_h5p\api::get_content_from_pathnamehash($snapshot->get_pathnamehash()));
        $this->assertNull(\core_h5p\api::get_content_from_pathnamehash($package->get_pathnamehash()));
        $zip = new \ZipArchive();
        $zip->open(get_file_storage()->get_file_system()->get_local_path_from_storedfile($package, true));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($entries);
        $this->assertSame(['content/content.json', 'content/pixel.png', 'h5p.json'], $entries);
    }

    /**
     * An actual M08 course upload is published by its teacher, whose system deploy capability is false.
     */
    public function test_course_teacher_publisher_can_preview(): void {
        $this->libraries();
        $source = $this->generator->create_source();
        direct_upload::set_sourceid($source->get_id());
        $this->setUser($this->teacher);
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $this->path,
            'card.h5p',
            'h5p',
            (object) ['title' => 'Course card']
        );
        $version = version_manager::get((int) $placement->appliedversionid);
        $this->assertEquals($this->teacher->id, $version->publishedby);
        $this->assertFalse(has_capability('moodle/h5p:deploy', \context_system::instance()));
        $this->assertTrue(has_capability('moodle/h5p:deploy', \context_course::instance($this->course->id)));
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $this->assertGreaterThan(0, $preview['h5pid']);
    }

    /**
     * The operational cache is shared across teachers and courses without recording either viewer as its owner.
     */
    public function test_cache_is_shared_anonymously(): void {
        global $DB;
        $version = $this->published();
        $this->setUser($this->teacher);
        $first = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $file = h5p_preview::package($version);
        $othercourse = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $this->setUser($other);
        $second = h5p_preview::prepare((int) $version->id, (int) $othercourse->id);
        $this->assertSame($first['h5pid'], $second['h5pid']);
        $this->assertEquals($file->get_id(), h5p_preview::package($version)->get_id());
        $this->assertSame(1, $DB->count_records('files', ['component' => 'local_nextpool',
            'filearea' => h5p_preview::FILEAREA, 'itemid' => $version->id, 'filename' => $file->get_filename()]));
        foreach (
            get_file_storage()->get_area_files(
                \context_system::instance()->id,
                'core_h5p',
                'content',
                $first['h5pid'],
                'id',
                false
            ) as $media
        ) {
            $this->assertNull($media->get_userid());
        }
    }

    /**
     * Neither a student nor the guest can use known cache URLs, native content ids or native exports.
     */
    public function test_native_routes_deny_known_urls_and_ids(): void {
        global $DB;
        $version = $this->published();
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $package = h5p_preview::package($version);
        $url = \moodle_url::make_pluginfile_url(
            $package->get_contextid(),
            'local_nextpool',
            h5p_preview::FILEAREA,
            $version->id,
            '/',
            $package->get_filename()
        )->out(false);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        foreach ([$student, guest_user()] as $user) {
            $this->setUser($user);
            $this->assertFalse(\core_h5p\api::can_access_content($preview['h5pid']));
            $this->assertFalse(\core_h5p\api::can_access_export('card-' . $preview['h5pid'] . '.h5p'));
            try {
                $messages = new \stdClass();
                [, $id] = \core_h5p\api::create_content_from_pluginfile_url(
                    $url,
                    new \stdClass(),
                    new \core_h5p\factory(),
                    $messages,
                    true,
                    false
                );
                $this->assertFalse($id);
            } catch (\moodle_exception $exception) {
                $this->assertSame('h5pprivatefile', $exception->errorcode);
            }
            $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'pixel.png'));
        }
        $this->assertSame(1, $DB->count_records('h5p'));
    }

    /**
     * The actual pluginfile proxy rechecks catalogue rights rather than trusting a previous H5P session.
     */
    public function test_proxy_rechecks_viewer_rights(): void {
        global $PAGE;
        $version = $this->published();
        $this->setUser($this->teacher);
        h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        // A file request is a fresh request, rather than the page whose theme was initialised by the player.
        $PAGE = new \moodle_page();
        $sender = $this->createMock(h5p_preview_sender::class);
        $sender->expects($this->once())->method('send')->with($this->callback(
            fn($file) => $file instanceof \stored_file && $file->get_mimetype() === 'image/png'
        ), []);
        \core\di::set(h5p_preview_sender::class, $sender);
        $args = [$version->id, $this->course->id, 'pixel.png'];
        $this->assertTrue(local_nextpool_pluginfile(
            null,
            null,
            \context_system::instance(),
            h5p_preview::MEDIA_AREA,
            $args,
            false
        ));
        $role = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($this->course->id);
        role_assign($role, $this->teacher->id, $context->id);
        assign_capability('local/nextpool:viewcatalogue', CAP_PROHIBIT, $role, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('local/nextpool:viewcatalogue', $context));
        $this->assertFalse(local_nextpool_pluginfile(
            null,
            null,
            \context_system::instance(),
            h5p_preview::MEDIA_AREA,
            $args,
            false
        ));
    }

    /**
     * Media is bound to the exact current published version and the verified anonymous package hash.
     */
    public function test_proxy_rejects_stale_and_mismatched_content(): void {
        global $DB;
        $version = $this->published();
        $this->setUser($this->teacher);
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $this->assertNotNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'pixel.png'));
        $DB->set_field('h5p', 'contenthash', str_repeat('0', 40), ['id' => $preview['h5pid']]);
        $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'pixel.png'));
        $DB->set_field('h5p', 'contenthash', h5p_preview::package($version)->get_contenthash(), ['id' => $preview['h5pid']]);
        $DB->set_field(version_manager::TABLE, 'status', status::WITHDRAWN, ['id' => $version->id]);
        $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'pixel.png'));
        $DB->set_field(version_manager::TABLE, 'status', status::PUBLISHED, ['id' => $version->id]);
        $DB->set_field(item_manager::TABLE, 'currentversionid', 0, ['id' => $version->itemid]);
        $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'pixel.png'));
    }

    /**
     * The content proxy refuses active document/script formats and sandboxes allowed SVG documents.
     */
    public function test_media_mime_policy_and_svg_sandbox(): void {
        $version = $this->published();
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $fs = get_file_storage();
        $formats = ['unsafe.html' => 'text/html', 'unsafe.js' => 'application/javascript', 'image.svg' => 'image/svg+xml'];
        foreach ($formats as $name => $mime) {
            $fs->create_file_from_string(['contextid' => \context_system::instance()->id, 'component' => 'core_h5p',
                'filearea' => 'content', 'itemid' => $preview['h5pid'], 'filepath' => '/', 'filename' => $name,
                'mimetype' => $mime], '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        }
        $fs->create_file_from_string(['contextid' => \context_system::instance()->id, 'component' => 'core_h5p',
            'filearea' => 'content', 'itemid' => $preview['h5pid'], 'filepath' => '/', 'filename' => 'captions.vtt',
            'mimetype' => 'text/vtt'], "WEBVTT\n\n00:00.000 --> 00:01.000\nCaption\n");
        $this->assertNotNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'captions.vtt'));
        $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'unsafe.html'));
        $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'unsafe.js'));
        $this->assertNull(h5p_preview::find_media((int) $version->id, (int) $this->course->id, '../pixel.png'));
        $svg = h5p_preview::find_media((int) $version->id, (int) $this->course->id, 'image.svg');
        $this->assertNotNull($svg);
        $headers = h5p_preview_sender::headers($svg);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertSame("sandbox; default-src 'none'; style-src 'unsafe-inline'", $headers['Content-Security-Policy']);
    }

    /**
     * Even an authorised update publication cannot smuggle bundled patch updates through the NULL-owner projection.
     */
    public function test_content_only_deployment_never_updates_bundled_libraries(): void {
        global $DB;
        $this->libraries();
        $before = (int) $DB->get_field('h5p_libraries', 'patchversion', ['machinename' => 'H5P.GreetingCard']);
        $zip = new \ZipArchive();
        $zip->open($this->path);
        $library = json_decode($zip->getFromName('H5P.GreetingCard-1.0/library.json'));
        $library->patchVersion = $before + 1;
        $zip->addFromString('H5P.GreetingCard-1.0/library.json', json_encode($library));
        $zip->close();
        $version = $this->published(true);
        $this->setUser($this->teacher);
        h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $this->assertSame($before, (int) $DB->get_field('h5p_libraries', 'patchversion', ['machinename' => 'H5P.GreetingCard']));
    }

    /**
     * The public Core iframe branch renders without invoking Core's viewed-event output method.
     */
    public function test_core_iframe_rendering_without_view_event(): void {
        $zip = new \ZipArchive();
        $zip->open($this->path);
        $json = json_decode($zip->getFromName('h5p.json'));
        $json->embedTypes = ['iframe'];
        $zip->addFromString('h5p.json', json_encode($json));
        $library = json_decode($zip->getFromName('H5P.GreetingCard-1.0/library.json'));
        $library->embedTypes = ['iframe'];
        $zip->addFromString('H5P.GreetingCard-1.0/library.json', json_encode($library));
        $zip->close();
        $version = $this->published();
        $sink = $this->redirectEvents();
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $this->assertSame('iframe', $preview['embedtype']);
        $this->assertNotEmpty($preview['settings']['contents']['cid-' . $preview['h5pid']]['scripts']);
        $this->assertStringContainsString('h5p-iframe', h5p_preview::render($preview));
        foreach ($sink->get_events() as $event) {
            $this->assertNotInstanceOf(\core_h5p\event\h5p_viewed::class, $event);
        }
    }

    /**
     * The publication flag is mandatory even when the viewer could install libraries personally.
     */
    public function test_missing_libraries_without_publication_permission_do_not_install(): void {
        global $DB;
        $version = $this->published(false);
        try {
            h5p_preview::prepare((int) $version->id, (int) $this->course->id);
            $this->fail('Missing libraries need an explicit publication decision.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('previewunavailable', $exception->errorcode);
        }
        $this->assertSame(0, $DB->count_records('h5p_libraries'));
        $this->assertSame(0, $DB->count_records('h5p'));
    }

    /**
     * Revoking the actual publisher's system library permission prevents missing-library installation.
     */
    public function test_revoked_publisher_permission_prevents_install(): void {
        global $DB;
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'card.h5p', file_get_contents($this->path));
        $item = $this->generator->create_item(['type' => 'h5p', 'sourceid' => $source->get_id()]);
        $version = version_manager::create_from_source((int) $item->id, 'card.h5p');
        version_manager::set_status((int) $version->id, status::REVIEW);
        $publisher = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        $system = \context_system::instance();
        foreach (['local/nextpool:manageitems', 'local/nextpool:reviewversions', 'moodle/h5p:updatelibraries'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $role, $system->id);
        }
        role_assign($role, $publisher->id, $system->id);
        $this->setUser($publisher);
        $version = version_manager::set_status((int) $version->id, status::PUBLISHED, 0, true);
        item_manager::set_status((int) $item->id, status::REVIEW);
        item_manager::set_status((int) $item->id, status::PUBLISHED);
        $this->assertEquals($publisher->id, $version->publishedby);
        assign_capability('moodle/h5p:updatelibraries', CAP_PROHIBIT, $role, $system->id, true);
        $this->assertFalse(has_capability('moodle/h5p:updatelibraries', $system, $publisher));
        $this->setUser($this->teacher);
        try {
            h5p_preview::prepare((int) $version->id, (int) $this->course->id);
            $this->fail('The viewer must not restore a revoked publisher permission.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('previewunavailable', $exception->errorcode);
        }
        $this->assertSame(0, $DB->count_records('h5p_libraries'));
        $this->assertSame(0, $DB->count_records('h5p'));
    }

    /**
     * Unsupported Core generations are rejected before any library, content or file cache is created.
     */
    public function test_old_core_fails_before_writing_cache(): void {
        global $DB;
        $version = $this->published();
        $before = [$DB->count_records('files'), $DB->count_records('h5p_libraries'), $DB->count_records('h5p')];
        try {
            unsupported_h5p_preview::prepare((int) $version->id, (int) $this->course->id);
            $this->fail('Unsafe old Core media routes must disable H5P preview.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('previewunavailable', $exception->errorcode);
        }
        $this->assertSame($before, [$DB->count_records('files'), $DB->count_records('h5p_libraries'), $DB->count_records('h5p')]);
    }

    /**
     * Version cleanup removes anonymous deployed content and packages without touching the protected snapshot.
     */
    public function test_cleanup_removes_operational_cache(): void {
        global $DB;
        $version = $this->published();
        $snapshot = version_manager::get_file((int) $version->id);
        $preview = h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        preview_assets::delete((int) $version->id);
        $this->assertFalse($DB->record_exists('h5p', ['id' => $preview['h5pid']]));
        $this->assertFalse($DB->record_exists('files', ['component' => 'local_nextpool', 'filearea' => h5p_preview::FILEAREA,
            'itemid' => $version->id]));
        $this->assertEmpty(get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'core_h5p',
            'content',
            $preview['h5pid'],
            'id',
            false
        ));
        $this->assertNotFalse(get_file_storage()->get_file_by_id($snapshot->get_id()));
        $this->assertEquals(h5p_guard::get_owner(), $snapshot->get_userid());
    }

    /**
     * Legacy SYSTEM cache is purged even through the old-Core compatibility branch.
     */
    public function test_legacy_system_cache_is_removed_without_support_guard(): void {
        global $DB;
        $version = $this->published();
        $snapshot = version_manager::get_file((int) $version->id);
        $legacy = get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => h5p_preview::FILEAREA,
            'itemid' => $version->id, 'filepath' => '/', 'filename' => 'legacy.h5p', 'userid' => get_admin()->id,
        ], $snapshot);
        $id = \core_h5p\helper::save_h5p(new \core_h5p\factory(), $legacy, new \stdClass());
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, unsupported_h5p_preview::purge_legacy());
        $this->assertFalse($DB->record_exists('h5p', ['id' => $id]));
        $this->assertFalse(get_file_storage()->get_file_by_id($legacy->get_id()));
        $this->assertNotFalse(get_file_storage()->get_file_by_id($snapshot->get_id()));
        $this->assertGreaterThan(0, $DB->count_records('h5p_libraries'));
    }

    /**
     * Content-only repacking uses the site's existing total budget and excludes unused library bytes.
     */
    public function test_repack_budget_counts_only_content(): void {
        global $CFG;
        $version = $this->published();
        $snapshot = version_manager::get_file((int) $version->id);
        $CFG->maxbytes = 1024;
        $this->assertNotNull(h5p::create_content_only_package($snapshot));
        $zip = new \ZipArchive();
        $zip->open($this->path);
        $zip->addFromString('content/large.png', str_repeat('a', 2048));
        $zip->close();
        $large = \core_h5p\helper::create_fake_stored_file_from_path($this->path, h5p_guard::get_owner());
        try {
            h5p::create_content_only_package($large);
            $this->fail('The declared total must be checked before streaming content.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_h5ptoolarge', $exception->errorcode);
        }
    }
}
