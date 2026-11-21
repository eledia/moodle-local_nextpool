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

use local_nextpool\local\catalogue;
use local_nextpool\local\direct_upload;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Backup, restore and duplication of activities with a course-bound item (M06 task78f, test94, adr31).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class backup_course_bound_test extends \advanced_testcase {
    /** @var \stdClass Course the package is uploaded in. */
    private \stdClass $course;
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;
    /** @var \mod_nextpool_generator */
    private \mod_nextpool_generator $modgenerator;
    /** @var int Moodle file storage location that takes the direct uploads. */
    private int $filesid;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $CFG->backup_database_logger_level = \backup::LOG_WARNING;
        $this->course = $this->getDataGenerator()->create_course();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $this->modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        item_types::reset_cache();
        $this->filesid = $this->generator->create_source('moodle', [], 'Files')->get_id();
    }

    #[\Override]
    protected function tearDown(): void {
        item_types::reset_cache();
        parent::tearDown();
    }

    /**
     * Local file with a package.
     *
     * @param array $files Changes to the fixture package.
     * @return string Absolute path.
     */
    private function package(array $files = []): string {
        $path = make_request_directory() . '/upload.zip';
        file_put_contents($path, $this->modgenerator->create_package($files));
        return $path;
    }

    /**
     * Upload a package into a new activity of the course and add a second version.
     *
     * @return \stdClass The placement, with version 2 applied.
     */
    private function upload_two_versions(): \stdClass {
        $cmid = (int) $this->getDataGenerator()->create_module('nextpool', ['course' => $this->course->id])->cmid;
        $placement = direct_upload::create(
            (int) $this->course->id,
            $cmid,
            $this->package(),
            'Fire safety.zip',
            (object) [
                'title' => 'Fire safety',
                'description' => '<p>Short</p>',
                'license' => 'cc-4.0',
                'authors' => [['userid' => get_admin()->id], ['name' => 'Erika Muster', 'role' => 'Design']],
            ]
        );
        return direct_upload::add_version(
            (int) $placement->id,
            $this->package(['notes.txt' => 'second version']),
            'Fire safety.zip',
            false,
            0,
            (object) ['changelog' => 'Exit signs updated']
        );
    }

    /**
     * Back up a course; the backup directory stays in the temporary area.
     *
     * @param int $courseid Course id.
     * @param int $mode Backup mode.
     * @return string Backup id, also the directory name.
     */
    private function backup(int $courseid, int $mode = \backup::MODE_IMPORT): string {
        global $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        if ($mode !== \backup::MODE_GENERAL) {
            $bc->destroy();
            return $backupid;
        }
        // Like a download and upload of the .mbz on another site: unpack the stored file into a new directory.
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $extractid = 'mbz' . random_string(8);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($extractid));
        $file->delete();
        return $extractid;
    }

    /**
     * Restore a backup into a new course.
     *
     * @param string $backupid Backup id.
     * @return array [new course id, restore id]
     */
    private function restore_into_new_course(string $backupid): array {
        global $USER;
        $course = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $course->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $restoreid = $rc->get_restoreid();
        $rc->execute_plan();
        $rc->destroy();
        return [(int) $course->id, $restoreid];
    }

    /**
     * Pretend the backup comes from another site: the item is not here, nor is its snapshot.
     *
     * @param \stdClass $placement Placement of the original activity.
     */
    private function forget_item(\stdClass $placement): void {
        global $DB;
        $DB->set_field(item_manager::TABLE, 'uuid', \core\uuid::generate(), ['id' => $placement->itemid]);
        $fs = get_file_storage();
        foreach (version_manager::get_versions((int) $placement->itemid) as $version) {
            $fs->delete_area_files(\context_system::instance()->id, 'local_nextpool', version_manager::FILEAREA, $version->id);
        }
    }

    /**
     * Messages of the restore log.
     *
     * @param string $restoreid Restore id.
     * @return string[]
     */
    private function get_restore_log(string $restoreid): array {
        global $DB;
        return array_values($DB->get_fieldset_select('backup_logs', 'message', 'backupid = ?', [$restoreid]));
    }

    /**
     * Files of the backup as listed in files.xml.
     *
     * @param string $backupid Backup id.
     * @return string Content of files.xml.
     */
    private function get_files_xml(string $backupid): string {
        global $CFG;
        return (string) file_get_contents($CFG->tempdir . '/backup/' . $backupid . '/files.xml');
    }

    /**
     * Duplicating the activity in its course places the same item a second time.
     */
    public function test_duplicate_in_same_course(): void {
        $placement = $this->upload_two_versions();
        $cm = get_fast_modinfo($this->course->id)->get_cm((int) $placement->cmid);

        $newcm = duplicate_module($this->course, $cm);

        $copy = placement_manager::get_by_cmid((int) $newcm->id);
        $this->assertNotNull($copy);
        $this->assertEquals($placement->itemid, $copy->itemid);
        $this->assertEquals($placement->appliedversionid, $copy->appliedversionid);
        $this->assertSame(2, placement_manager::count_for_item((int) $placement->itemid));
        $this->assertSame(1, $this->count_items(), 'No second item.');
    }

    /**
     * A course copy links the same item, which stays unavailable in the target course (q56).
     */
    public function test_copy_into_other_course_links_same_item(): void {
        $placement = $this->upload_two_versions();
        $backupid = $this->backup((int) $this->course->id);
        $this->assertStringContainsString(
            '<component>local_nextpool</component>',
            $this->get_files_xml($backupid),
            'The snapshot of the applied version is in the backup (adr31).'
        );

        [$newcourseid] = $this->restore_into_new_course($backupid);

        $restored = array_values(placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, $restored);
        $this->assertEquals($placement->itemid, $restored[0]->itemid);
        $this->assertEquals($placement->appliedversionid, $restored[0]->appliedversionid);
        $item = item_manager::get((int) $placement->itemid);
        $this->assertFalse(catalogue::is_available_in_course($item, $newcourseid), 'Not in the catalogue of the copy.');
        $this->assertEquals(\context_course::instance($this->course->id)->id, $item->contextid);
        $this->assertSame(1, $this->count_items(), 'No second item.');
    }

    /**
     * On another site the restore creates a new course-bound item with the same version and content (M06.AC10).
     */
    public function test_restore_on_other_site_creates_course_bound_item(): void {
        $placement = $this->upload_two_versions();
        $applied = version_manager::get((int) $placement->appliedversionid);
        $backupid = $this->backup((int) $this->course->id, \backup::MODE_GENERAL);
        $this->forget_item($placement);

        [$newcourseid] = $this->restore_into_new_course($backupid);

        $restored = array_values(placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, $restored);
        $this->assertNotEquals($placement->itemid, $restored[0]->itemid);
        $item = item_manager::get((int) $restored[0]->itemid);
        $this->assertEquals(\context_course::instance($newcourseid)->id, $item->contextid);
        $this->assertSame($newcourseid, (int) $item->origincourseid);
        $this->assertSame(status::PUBLISHED, $item->status);
        $this->assertSame('Fire safety', $item->title);
        $this->assertSame('cc-4.0', $item->license);
        $this->assertSame(policy::AUTO, $restored[0]->policy);

        $version = version_manager::get((int) $restored[0]->appliedversionid);
        $this->assertSame(2, (int) $version->versionnr, 'Same version number.');
        $this->assertSame($applied->contenthash, $version->contenthash, 'Same content.');
        $this->assertSame('Exit signs updated', $version->changelog);
        $this->assertSame($applied->contenthash, version_manager::get_file((int) $version->id)->get_contenthash());
        $this->assertCount(1, version_manager::get_versions((int) $item->id));

        // Authors as free text, also the one who had an account on the original site (q91).
        $authors = item_manager::get_authors((int) $item->id);
        $this->assertCount(2, $authors);
        $this->assertNull($authors[0]->userid);
        $this->assertSame(fullname(get_admin()), $authors[0]->name);
        $this->assertSame('Erika Muster', $authors[1]->name);
        $this->assertSame('Design', $authors[1]->role);

        $this->assertTrue(direct_upload::can_upload(get_fast_modinfo($newcourseid)->get_cm((int) $restored[0]->cmid)));
    }

    /**
     * Items that are not course-bound travel without package, as before (TD-408).
     */
    public function test_item_not_course_bound_has_no_package_in_backup(): void {
        $item = $this->generator->create_published_item();
        placement_manager::place((int) $item->id, (int) $this->course->id, 0, policy::NOTIFY);

        $backupid = $this->backup((int) $this->course->id);

        $this->assertStringNotContainsString('<component>local_nextpool</component>', $this->get_files_xml($backupid));
        [$newcourseid] = $this->restore_into_new_course($backupid);
        $this->assertCount(1, placement_manager::get_for_course($newcourseid));
        $this->assertSame(1, $this->count_items());
    }

    /**
     * An unwritable storage location for direct uploads falls back to the first Moodle file storage (q92).
     */
    public function test_restore_falls_back_to_moodle_file_storage(): void {
        // The failing direct upload rolls back its own transaction, not the test's.
        $this->preventResetByRollback();
        $placement = $this->upload_two_versions();
        $backupid = $this->backup((int) $this->course->id, \backup::MODE_GENERAL);
        $this->forget_item($placement);
        $nextcloud = $this->generator->create_source('nextcloud', ['basefolder' => 'Nextpool', 'authmode' => 'apppassword']);
        direct_upload::set_sourceid($nextcloud->get_id());

        [$newcourseid, $restoreid] = $this->restore_into_new_course($backupid);

        $restored = array_values(placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, $restored);
        $this->assertSame($this->filesid, (int) item_manager::get((int) $restored[0]->itemid)->sourceid);
        $expected = get_string('restore_packagefallback', 'local_nextpool', (object) [
            'title' => 'Fire safety',
            'source' => 'Files',
        ]);
        $this->assertContains($expected, $this->get_restore_log($restoreid));
    }

    /**
     * Without any storage location that takes the package the activity stays without item, with a note (q92).
     */
    public function test_restore_without_writable_storage_location(): void {
        $this->preventResetByRollback();
        $placement = $this->upload_two_versions();
        $backupid = $this->backup((int) $this->course->id, \backup::MODE_GENERAL);
        $this->forget_item($placement);
        $nextcloud = $this->generator->create_source('nextcloud', ['basefolder' => 'Nextpool', 'authmode' => 'apppassword']);
        direct_upload::set_sourceid($nextcloud->get_id());
        \local_nextpool\local\source\manager::set_enabled($this->filesid, false);
        $items = $this->count_items();

        [$newcourseid, $restoreid] = $this->restore_into_new_course($backupid);

        $this->assertSame([], placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, get_fast_modinfo($newcourseid)->get_instances_of('nextpool'));
        $this->assertSame($items, $this->count_items());
        $log = implode("\n", $this->get_restore_log($restoreid));
        $this->assertStringContainsString('Fire safety', $log);
        $this->assertStringContainsString(get_string('error_nouploadsource', 'local_nextpool'), $log);
    }

    /**
     * A backup without the package restores the activity without item, with a note in the restore log.
     */
    public function test_restore_without_package_in_backup(): void {
        global $CFG;
        $placement = $this->upload_two_versions();
        $applied = version_manager::get((int) $placement->appliedversionid);
        $backupid = $this->backup((int) $this->course->id, \backup::MODE_GENERAL);
        $this->forget_item($placement);
        $hash = $applied->contenthash;
        unlink($CFG->tempdir . '/backup/' . $backupid . '/files/' . substr($hash, 0, 2) . '/' . $hash);

        [$newcourseid, $restoreid] = $this->restore_into_new_course($backupid);

        $this->assertSame([], placement_manager::get_for_course($newcourseid));
        $expected = get_string('restore_packagenotrestored', 'local_nextpool', (object) [
            'title' => 'Fire safety',
            'reason' => get_string('restore_packagemissing', 'local_nextpool'),
        ]);
        $this->assertContains($expected, $this->get_restore_log($restoreid));
    }

    /**
     * A backup whose course-bound flag was set by hand does not place an item of a course category elsewhere (bug104).
     */
    public function test_forged_course_bound_flag_is_ignored(): void {
        global $CFG;
        $category = $this->getDataGenerator()->create_category();
        $item = $this->generator->create_published_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $backupid = $this->backup((int) $course->id);
        $forged = 0;
        foreach (glob($CFG->tempdir . '/backup/' . $backupid . '/activities/*/module.xml') as $file) {
            $xml = (string) file_get_contents($file);
            $xml = str_replace('<coursebound>0</coursebound>', '<coursebound>1</coursebound>', $xml, $count);
            file_put_contents($file, $xml);
            $forged += $count;
        }
        $this->assertSame(1, $forged, 'The placement carries the flag.');

        // The new course is created in the default category, outside the item's category.
        [$newcourseid] = $this->restore_into_new_course($backupid);

        $this->assertSame([], placement_manager::get_for_course($newcourseid));
        $this->assertSame(1, $this->count_items(), 'No new item either.');
    }

    /**
     * Someone without access to the original course does not link its course-bound item, but gets a new one from the
     * package in the backup (bug104, q56, q57).
     */
    public function test_course_bound_item_of_inaccessible_course_is_not_linked(): void {
        global $USER;
        $placement = $this->upload_two_versions();
        // A downloaded backup file: it carries the package.
        $backupid = $this->backup((int) $this->course->id, \backup::MODE_GENERAL);
        $target = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($target, 'editingteacher');
        $this->setUser($teacher);

        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $restoreid = $rc->get_restoreid();
        $rc->execute_plan();
        $rc->destroy();

        $restored = array_values(placement_manager::get_for_course((int) $target->id));
        $this->assertCount(1, $restored, implode("\n", $this->get_restore_log($restoreid)));
        $this->assertNotEquals($placement->itemid, $restored[0]->itemid);
        $item = item_manager::get((int) $restored[0]->itemid);
        $this->assertEquals(\context_course::instance($target->id)->id, $item->contextid);
        $this->assertSame(2, $this->count_items());
    }

    /**
     * Number of items on the site.
     *
     * @return int
     */
    private function count_items(): int {
        global $DB;
        return $DB->count_records(item_manager::TABLE);
    }
    /**
     * Course-bound files and H5P survive an archive from another site with their original type (M08 test201).
     *
     * @param string $type Uploaded type.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('upload_types_provider')]
    public function test_restore_further_upload_types(string $type): void {
        global $CFG;
        $path = make_request_directory() . '/upload';
        if ($type === 'h5p') {
            copy($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p', $path);
            $filename = 'greeting.h5p';
        } else if ($type === 'scorm') {
            copy($CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip', $path);
            $filename = 'lesson.zip';
        } else {
            file_put_contents($path, '%PDF-1.4 lesson');
            $filename = 'lesson.pdf';
        }
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $path,
            $filename,
            $type,
            (object) ['title' => 'Lesson']
        );
        $backupid = $this->backup((int) $this->course->id, \backup::MODE_GENERAL);
        $this->forget_item($placement);
        [$courseid] = $this->restore_into_new_course($backupid);
        $placements = array_values(placement_manager::get_for_course($courseid));
        $this->assertCount(1, $placements);
        $restored = item_manager::get((int) $placements[0]->itemid);
        $this->assertSame($type, $restored->type);
        $this->assertEquals($courseid, $restored->origincourseid);
        $version = version_manager::get((int) $placements[0]->appliedversionid);
        $this->assertSame(sha1_file($path), $version->contenthash);
        $this->assertSame(1, (int) $version->versionnr);
        $this->assertNotNull(version_manager::get_file((int) $version->id));
    }

    /**
     * Supported non-xAPI upload types.
     *
     * @return array
     */
    public static function upload_types_provider(): array {
        return ['file' => ['file'], 'h5p' => ['h5p'], 'scorm' => ['scorm']];
    }
}
