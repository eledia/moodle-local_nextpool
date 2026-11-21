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
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Direct upload detection and atomic placement (M08, task191/task192).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(direct_upload::class)]
final class upload_detection_test extends \advanced_testcase {
    /**
     * @var \stdClass Course.
     */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        item_types::reset_cache();
    }

    /**
     * Write a small ZIP fixture.
     *
     * @param array $files Entries by name.
     * @return string
     */
    private function archive(array $files): string {
        $path = make_request_directory() . '/upload.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    /**
     * The same validators recognise packages despite their file name (test195/test205).
     */
    public function test_detection_uses_content_and_preserves_preference(): void {
        global $USER;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        $path = make_request_directory() . '/package.zip';
        file_put_contents($path, $generator->create_package());
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('imsmanifest.xml', '<manifest><organizations><organization identifier="org">'
            . '<title>SCORM title</title></organization></organizations></manifest>');
        $zip->close();
        $detected = direct_upload::detect($path, 'lesson.zip', $this->course->id, $USER->id);
        $this->assertSame(['xapi', 'scorm'], $detected->get_allowed_types());
        $this->assertSame('xapi', $detected->get_type());
        $this->assertNotEmpty($detected->candidates['xapi']['title']);
        $this->assertSame('SCORM title', $detected->candidates['scorm']['title']);
        $this->assertStringContainsString('Nextpool', $detected->get_learning_data_notice('xapi'));

        $path = $this->archive(['h5p.json' => '{"mainLibrary":"H5P.GreetingCard","title":"Hello"}']);
        $detected = direct_upload::detect($path, 'lesson.zip', $this->course->id, $USER->id);
        $this->assertSame('h5p', $detected->get_type());
        $this->assertSame('Hello', $detected->candidates['h5p']['title']);
    }

    /**
     * Office containers are files; unknown ZIPs must be rejected (q171).
     */
    public function test_office_document_is_file_but_zip_is_rejected(): void {
        global $USER;
        $path = $this->archive(['[Content_Types].xml' => '<Types/>']);
        $result = direct_upload::detect($path, 'Handbook.docx', $this->course->id, $USER->id);
        $this->assertSame('file', $result->get_type());
        $this->assertSame('Handbook', $result->candidates['file']['title']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_uploadnotrecognised', 'local_nextpool'));
        direct_upload::detect($path, 'Handbook.ZIP', $this->course->id, $USER->id);
    }

    /**
     * An enrolled student sees the reason and cannot choose a detected type (test193).
     */
    public function test_detection_explains_missing_permission(): void {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $path = make_request_directory() . '/document.pdf';
        file_put_contents($path, '%PDF-1.4 test');
        $result = direct_upload::detect($path, 'document.pdf', $this->course->id, $user->id);
        $this->assertNull($result->get_type());
        $this->assertNotEmpty($result->reasons['file']);
    }

    /**
     * A direct file upload creates a course-bound item and real resource activity (test190/test210).
     */
    public function test_create_and_place_file_preserves_position_and_visibility(): void {
        global $USER, $DB;
        $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $before = $this->getDataGenerator()->create_module('resource', ['course' => $this->course->id, 'section' => 1]);
        $path = make_request_directory() . '/document.pdf';
        file_put_contents($path, '%PDF-1.4 test');
        $placement = direct_upload::create_and_place(
            $this->course->id,
            1,
            $before->cmid,
            $path,
            'document.pdf',
            'file',
            (object) ['title' => 'Document', 'visible' => 0],
            [],
            $USER->id
        );
        $item = item_manager::get($placement->itemid);
        $cm = get_fast_modinfo($this->course->id)->get_cm($placement->cmid);
        $this->assertSame('file', $item->type);
        $this->assertEquals($this->course->id, $item->origincourseid);
        $this->assertSame(status::PUBLISHED, $item->status);
        $this->assertSame('resource', $cm->modname);
        $this->assertFalse((bool) $cm->visible);
        $this->assertTrue(direct_upload::can_upload($cm));
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $cm->section]));
        $this->assertLessThan(array_search((string) $before->cmid, $sequence), array_search((string) $cm->id, $sequence));
        $version = version_manager::get($placement->appliedversionid);
        $this->assertEquals(sha1_file($path), $version->contenthash);
    }

    /**
     * A failure after storing the upload removes all catalogue and storage records (test211).
     */
    public function test_failed_placement_rolls_back_item_and_file(): void {
        $this->preventResetByRollback();
        global $DB;
        $source = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $path = make_request_directory() . '/document.pdf';
        file_put_contents($path, '%PDF-1.4 test');
        try {
            direct_upload::create_and_place(
                $this->course->id,
                0,
                999999,
                $path,
                'document.pdf',
                'file',
                (object) ['title' => 'Document']
            );
            $this->fail('A missing position must reject the placement.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidcoursemoduleid', $e->errorcode);
        }
        if ($DB->is_transaction_started()) {
            $DB->force_transaction_rollback();
        }
        $this->assertSame(0, $DB->count_records('local_nextpool_item'));
        $this->assertSame(0, $DB->count_records('local_nextpool_version'));
        $this->assertEmpty($source->list_entries(''));
    }
    /**
     * A file's new version keeps the activity and reports edits made directly in it (test199/test204).
     */
    public function test_file_update_and_course_edits(): void {
        $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $path = make_request_directory() . '/document.pdf';
        file_put_contents($path, '%PDF-1.4 first');
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $path,
            'document.pdf',
            'file',
            (object) ['title' => 'Document']
        );
        $cm = get_fast_modinfo($this->course->id)->get_cm($placement->cmid);
        $this->assertFalse(direct_upload::has_course_changes($cm));
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cm->id)->id,
            'mod_resource',
            'content',
            0,
            'id',
            false
        );
        $replacement = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'changed.pdf',
        ], '%PDF-1.4 changed in Moodle');
        reset($files)->replace_file_with($replacement);
        $this->assertTrue(direct_upload::has_course_changes($cm));
        file_put_contents($path, '%PDF-1.4 second');
        $result = direct_upload::add_version($placement->id, $path, 'document.pdf', false);
        $this->assertTrue($result->applied);
        $this->assertEquals($cm->id, $result->cmid);
        $this->assertSame(2, (int) version_manager::get($result->appliedversionid)->versionnr);
        $this->assertFalse(direct_upload::has_course_changes($cm));
    }

    /**
     * SCORM uploads use the real SCORM activity, including its update rules (test190/test199).
     */
    public function test_scorm_upload_and_update(): void {
        global $CFG;
        $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $path = make_request_directory() . '/lesson.zip';
        copy($CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip', $path);
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $path,
            'lesson.zip',
            'scorm',
            (object) ['title' => 'Lesson']
        );
        $cm = get_fast_modinfo($this->course->id)->get_cm($placement->cmid);
        $this->assertSame('scorm', $cm->modname);
        $this->assertFalse(direct_upload::has_course_changes($cm));
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('changed.txt', 'version 2');
        $zip->close();
        $result = direct_upload::add_version($placement->id, $path, 'lesson.zip', false);
        $this->assertTrue($result->applied);
        $this->assertEquals($cm->id, $result->cmid);
        $this->assertFalse(direct_upload::has_course_changes($cm));
    }
    /**
     * H5P with missing libraries requires library permission and uses content hashes consistently (test194/test204).
     */
    public function test_h5p_upload_libraries_and_hashes(): void {
        global $CFG, $USER;
        $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $path = $CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p';
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $denied = direct_upload::detect($path, 'greeting-card.h5p', $this->course->id, $teacher->id);
        $this->assertNull($denied->get_type());
        $this->assertNotEmpty($denied->reasons['h5p']);
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $path,
            'greeting-card.h5p',
            'h5p',
            (object) ['title' => 'Greeting card']
        );
        $version = version_manager::get($placement->appliedversionid);
        $this->assertSame(1, (int) $version->h5plibraries);
        $cm = get_fast_modinfo($this->course->id)->get_cm($placement->cmid);
        $this->assertSame('h5pactivity', $cm->modname);
        $this->assertFalse(direct_upload::has_course_changes($cm));
    }

    /**
     * Changing H5P main library publishes and offers the new version without replacing attempts (test200).
     */
    public function test_h5p_incompatible_version_is_offered_without_notification(): void {
        global $CFG;
        $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $path = make_request_directory() . '/greeting.h5p';
        copy($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p', $path);
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $path,
            'greeting.h5p',
            'h5p',
            (object) ['title' => 'Greeting card']
        );
        $zip = new \ZipArchive();
        $zip->open($path);
        $json = json_decode($zip->getFromName('h5p.json'), true);
        $json['mainLibrary'] = 'H5P.Other';
        $zip->addFromString('h5p.json', json_encode($json));
        $zip->close();
        $messages = $this->redirectMessages();
        $result = direct_upload::add_version($placement->id, $path, 'greeting.h5p', true);
        $this->assertFalse($result->applied);
        $this->assertNotEmpty($result->reason);
        $this->assertEquals($placement->appliedversionid, $result->appliedversionid);
        $this->assertSame(2, (int) version_manager::get($result->pendingversionid)->versionnr);
        $this->assertSame(status::PUBLISHED, version_manager::get($result->pendingversionid)->status);
        $this->assertCount(0, $messages->get_messages());
    }

    /**
     * A co-teacher with a module-level H5P prohibition cannot replace content owned by a valid deployer.
     */
    public function test_h5p_new_version_requires_deploy_in_the_activity(): void {
        global $CFG, $DB, $PAGE;
        $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_source();
        $path = make_request_directory() . '/greeting.h5p';
        copy($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p', $path);
        $libraryfile = \core_h5p\helper::create_fake_stored_file_from_path($path, (int) get_admin()->id);
        \core_h5p\helper::save_h5p(new \core_h5p\factory(), $libraryfile, (object) [], false, true);
        $placement = direct_upload::create_and_place(
            $this->course->id,
            0,
            0,
            $path,
            'greeting.h5p',
            'h5p',
            (object) ['title' => 'Working content']
        );
        $context = \context_module::instance($placement->cmid);
        $item = item_manager::get($placement->itemid);
        $version = version_manager::get($placement->appliedversionid);
        $source = \local_nextpool\local\source\manager::get($item->sourceid);
        $oldrevision = $source->stat($version->sourceref)->revision;
        $oldfiles = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $oldfile = reset($oldfiles);
        $oldhash = $oldfile->get_contenthash();
        $oldowner = $oldfile->get_userid();
        $count = $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/h5p:deploy', CAP_ALLOW, $roleid, \context_course::instance($this->course->id)->id);
        assign_capability('moodle/h5p:deploy', CAP_PROHIBIT, $roleid, $context->id);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/h5p:deploy', \context_course::instance($this->course->id)));
        $this->assertFalse(has_capability('moodle/h5p:deploy', $context));
        $this->assertTrue(has_capability('moodle/course:manageactivities', $context));
        $zip = new \ZipArchive();
        $zip->open($path);
        $metadata = json_decode($zip->getFromName('h5p.json'), true);
        $metadata['title'] = 'Replacement';
        $zip->addFromString('h5p.json', json_encode($metadata));
        $zip->close();
        try {
            direct_upload::add_version($placement->id, $path, 'greeting.h5p', false, $teacher->id);
            $this->fail('A publisher without H5P deploy permission must be rejected before writing.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('uploadh5pforbidden', $exception->errorcode);
        }
        $this->assertEquals($version->id, item_manager::get($item->id)->currentversionid);
        $this->assertEquals($version->id, \local_nextpool\local\placement_manager::get($placement->id)->appliedversionid);
        $this->assertSame($count, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
        $this->assertSame($oldrevision, $source->stat($version->sourceref)->revision);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $this->assertSame($oldhash, reset($files)->get_contenthash());
        $this->assertEquals($oldowner, reset($files)->get_userid());
        $PAGE->set_context($context);
        $PAGE->set_url('/local/nextpool/uploadversion.php', ['cmid' => $placement->cmid]);
        $form = new \local_nextpool\form\upload_version_form(null, [
            'item' => $item, 'context' => $context, 'maxbytes' => 0, 'changed' => false,
        ]);
        $this->assertSame(get_string('uploadh5pforbidden', 'local_nextpool'), $form->validation([], [])['package']);
    }
}
