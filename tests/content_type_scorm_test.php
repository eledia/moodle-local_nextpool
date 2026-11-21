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

use local_nextpool\local\content_type\scorm;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\version_manager;

/**
 * Tests for the SCORM content type: place, update in place, refusal rules (task25a, q20).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scorm::class)]
final class content_type_scorm_test extends \advanced_testcase {
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
     * Bytes of a core SCORM test package.
     *
     * @param string $name File name in mod/scorm/tests/packages.
     * @return string
     */
    private function package(string $name): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/mod/scorm/tests/packages/' . $name);
    }

    /**
     * The basic single SCO package with one more file: same SCO identifiers, different content.
     *
     * @return string
     */
    private function changed_basic_package(): string {
        $path = make_request_directory() . '/package.zip';
        file_put_contents($path, $this->package('singlescobasic.zip'));
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('nextpool-changed.txt', 'version 2');
        $zip->close();
        return (string) file_get_contents($path);
    }

    /**
     * Store a track value for a learner in a SCO, as the SCORM player would.
     *
     * @param int $scormid SCORM instance id.
     * @param int $scoid SCO id.
     * @param int $userid Learner.
     * @param string $element Element name.
     * @param string $value Value.
     */
    private function track(int $scormid, int $scoid, int $userid, string $element, string $value): void {
        global $DB;
        $attemptid = $DB->get_field('scorm_attempt', 'id', ['scormid' => $scormid, 'userid' => $userid, 'attempt' => 1])
            ?: $DB->insert_record('scorm_attempt', (object) ['scormid' => $scormid, 'userid' => $userid, 'attempt' => 1]);
        $elementid = $DB->get_field('scorm_element', 'id', ['element' => $element])
            ?: $DB->insert_record('scorm_element', (object) ['element' => $element]);
        $DB->insert_record('scorm_scoes_value', (object) [
            'scoid' => $scoid, 'attemptid' => $attemptid, 'elementid' => $elementid, 'value' => $value, 'timemodified' => time(),
        ]);
    }

    /**
     * A SCORM item becomes a SCORM activity; a new version with the same SCOs keeps their ids and tracks.
     */
    public function test_place_and_update(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'scorm'], $this->package('singlescobasic.zip'), 'golf.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('scorm', $cm->modname);
        $scormrecord = $DB->get_record('scorm', ['id' => $cm->instance]);
        $this->assertSame('golf.zip', $scormrecord->reference);
        $this->assertSame('SCORM_1.2', $scormrecord->version);
        $sco = $DB->get_record('scorm_scoes', ['scorm' => $cm->instance, 'identifier' => 'item_1'], '*', MUST_EXIST);

        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->track((int) $cm->instance, (int) $sco->id, (int) $learner->id, 'cmi.core.lesson_status', 'completed');

        $version2 = $this->generator->publish_new_version((int) $item->id, $this->changed_basic_package(), 'golf.zip');
        $this->assertNull((new scorm())->check_update($cm, $item, $version2));
        placement_manager::apply((int) $placement->id, (int) $version2->id);

        $after = $DB->get_record('scorm_scoes', ['scorm' => $cm->instance, 'identifier' => 'item_1'], '*', MUST_EXIST);
        $this->assertEquals($sco->id, $after->id, 'Same identifier, same SCO.');
        $this->assertSame(1, $DB->count_records('scorm_scoes_value', ['scoid' => $sco->id]), 'Tracks are kept.');
        $this->assertGreaterThan((int) $scormrecord->revision, (int) $DB->get_field('scorm', 'revision', ['id' => $cm->instance]));
    }

    /**
     * Lost SCOs with tracks, a different standard and unfinished saved progress are reasons to ask the teacher.
     */
    public function test_check_update_refusals(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'scorm'], $this->package('singlescobasic.zip'), 'golf.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $sco = $DB->get_record('scorm_scoes', ['scorm' => $cm->instance, 'identifier' => 'item_1'], '*', MUST_EXIST);
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $handler = new scorm();

        // Without learner data a package with other SCOs is fine.
        $golf = $this->package('RuntimeMinimumCalls_SCORM12.zip');
        $other = $this->generator->publish_new_version((int) $item->id, $golf, 'golf.zip');
        $this->assertNull($handler->check_update($cm, $item, $other));

        // With a track in item_1, losing item_1 would delete it.
        $this->track((int) $cm->instance, (int) $sco->id, (int) $learner->id, 'cmi.core.lesson_status', 'completed');
        $this->assertStringContainsString('item_1', (string) $handler->check_update($cm, $item, $other));

        // A different standard.
        $aicc = $this->generator->publish_new_version((int) $item->id, $this->package('validaicc.zip'), 'golf.zip');
        $this->assertStringContainsString('AICC', (string) $handler->check_update($cm, $item, $aicc));

        // Unfinished saved progress (q20), even when no SCO is lost.
        $same = $this->generator->publish_new_version((int) $item->id, $this->changed_basic_package(), 'golf.zip');
        $this->assertNull($handler->check_update($cm, $item, $same));
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->track((int) $cm->instance, (int) $sco->id, (int) $other->id, 'cmi.core.lesson_status', 'incomplete');
        $this->track((int) $cm->instance, (int) $sco->id, (int) $other->id, 'cmi.suspend_data', 'page=3');
        $this->assertSame(get_string('noinplace_scormprogress', 'local_nextpool'), $handler->check_update($cm, $item, $same));
    }

    /**
     * The manifest reader finds standard and SCO identifiers without unpacking the package.
     */
    public function test_read_manifest(): void {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'golf.zip',
        ], $this->package('singlescobasic.zip'));
        $manifest = scorm::read_manifest($file);
        $this->assertSame('SCORM_1.2', $manifest['version']);
        $this->assertContains('item_1', $manifest['identifiers']);
        $this->assertContains('golf_sample_default_org', $manifest['identifiers']);
    }

    /**
     * A ZIP with the given files.
     *
     * @param array $files Path => content.
     * @return string
     */
    private function zip(array $files): string {
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
     * A version core could not parse is refused before the package is replaced, so the activity keeps working
     * (bug58).
     */
    public function test_unreadable_package_is_refused(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'scorm'], $this->package('singlescobasic.zip'), 'golf.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $handler = new scorm();
        $invalid = get_string('noinplace_scorminvalid', 'local_nextpool');

        $broken = [
            'manifest not XML' => $this->zip(['imsmanifest.xml' => '<manifest><organizations>', 'index.html' => 'x']),
            'manifest without content' => $this->zip(['imsmanifest.xml' => '<manifest identifier="m"/>']),
            'neither manifest nor AICC' => $this->zip(['index.html' => '<p>Hello</p>']),
            'no ZIP' => 'just text',
        ];
        $n = 1;
        foreach ($broken as $case => $content) {
            // Importing refuses such files since bug205; versions from before stay and are checked here.
            $version = $this->import_unchecked((int) $item->id, $content . str_repeat(' ', $n++));
            $this->assertSame($invalid, $handler->check_update($cm, $item, $version), $case);
        }
        $this->assertTrue(scorm::read_manifest(get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'aicc.zip',
        ], $this->package('validaicc.zip')))['valid']);

        // The automatic update offers the version instead and leaves the activity as it was.
        $this->assertFalse(placement_manager::apply_automatically((int) $placement->id, (int) $version->id));
        $this->assertSame($invalid, placement_manager::get_downgrade_reason(placement_manager::get((int) $placement->id)));
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance]);
        $this->assertSame('SCORM_1.2', $scorm->version);
        $files = get_file_storage()->get_area_files(\context_module::instance($cm->id)->id, 'mod_scorm', 'package', 0, 'id', false);
        $applied = version_manager::get_file((int) $placement->appliedversionid);
        $this->assertSame($applied->get_contenthash(), reset($files)->get_contenthash(), 'The package was not replaced.');
    }

    /**
     * Placing works with core's defaults when a site never saved the SCORM settings (bug63, Q-27).
     */
    public function test_place_without_saved_settings(): void {
        global $DB;
        foreach (['framewidth', 'frameheight', 'nav', 'maxgrade', 'whatgrade', 'masteryoverride'] as $name) {
            unset_config($name, 'scorm');
        }
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'scorm'], $this->package('singlescobasic.zip'), 'golf.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance]);
        $this->assertEquals(100, $scorm->width);
        $this->assertEquals(500, $scorm->height);
        $this->assertEquals(100, $scorm->maxgrade);
        $this->assertEquals(1, $scorm->masteryoverride);
    }

    /**
     * Publish a version without the check on import, like a version stored before the check existed (bug205).
     *
     * @param int $itemid Item id.
     * @param string $content Content.
     * @return \stdClass The published version.
     */
    private function import_unchecked(int $itemid, string $content): \stdClass {
        global $DB;
        $DB->set_field('local_nextpool_item', 'type', 'file', ['id' => $itemid]);
        try {
            return $this->generator->publish_new_version($itemid, $content, 'golf.zip');
        } finally {
            $DB->set_field('local_nextpool_item', 'type', 'scorm', ['id' => $itemid]);
        }
    }
}
