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

use core_competency\course_competency;
use core_competency\course_module_competency;
use local_nextpool\event\placement_created;
use local_nextpool\event\placement_updated;
use local_nextpool\local\item_competencies;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Placing an item links its competencies with the course module (F05, F14).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_competencies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(placement_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(placement_created::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(placement_updated::class)]
final class placement_competencies_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;
    /** @var \core_competency_generator */
    private \core_competency_generator $lp;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $this->lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
    }

    /**
     * A published item with competencies: one linkable, one unknown, one ambiguous, one in a hidden framework.
     *
     * @return array{0:\stdClass,1:int} Item and the id of the linkable competency.
     */
    private function create_item_with_competencies(): array {
        $framework = $this->lp->create_framework();
        $other = $this->lp->create_framework();
        $hidden = $this->lp->create_framework(['visible' => 0]);
        $safe = $this->lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'SAFE-01']);
        $this->lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'TWICE']);
        $this->lp->create_competency(['competencyframeworkid' => $other->get('id'), 'idnumber' => 'TWICE']);
        $this->lp->create_competency(['competencyframeworkid' => $hidden->get('id'), 'idnumber' => 'SECRET']);

        $item = $this->generator->create_published_item(['title' => 'Fire safety']);
        $this->generator->create_item_competencies((int) $item->id, [
            ['competency' => 'SAFE-01', 'level' => 2, 'function' => 'explain'],
            ['competency' => 'MISSING', 'level' => 3, 'function' => 'practice'],
            ['competency' => 'TWICE', 'level' => 1, 'function' => 'check'],
            ['competency' => 'SECRET', 'level' => 4, 'function' => 'apply'],
        ]);
        return [$item, (int) $safe->get('id')];
    }

    /**
     * After placing, the course module carries the linkable competency; the rest is reported and logged.
     */
    public function test_place_links_competencies(): void {
        [$item, $safeid] = $this->create_item_with_competencies();
        $course = $this->getDataGenerator()->create_course();

        $sink = $this->redirectEvents();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof placement_created));
        $sink->close();

        $this->assertSame(
            [
                item_competencies::LINKED,
                item_competencies::UNKNOWN,
                item_competencies::AMBIGUOUS,
                item_competencies::HIDDEN,
            ],
            array_column($placement->competencyresults, 'result')
        );
        $this->assertTrue(course_competency::record_exists_select(
            'courseid = :courseid AND competencyid = :competencyid',
            ['courseid' => $course->id, 'competencyid' => $safeid]
        ));
        $modulecompetencies = array_values(course_module_competency::get_records(['cmid' => $placement->cmid]));
        $this->assertCount(1, $modulecompetencies);
        $this->assertEquals($safeid, $modulecompetencies[0]->get('competencyid'));

        // Skills and ContentHub read level and function from the event.
        $this->assertCount(1, $events);
        $this->assertSame([
            ['competency' => 'SAFE-01', 'level' => 2, 'function' => 'explain', 'competencyid' => $safeid,
                'competencyids' => [$safeid]],
            ['competency' => 'MISSING', 'level' => 3, 'function' => 'practice', 'competencyid' => 0, 'competencyids' => []],
            ['competency' => 'TWICE', 'level' => 1, 'function' => 'check', 'competencyid' => 0, 'competencyids' => []],
            ['competency' => 'SECRET', 'level' => 4, 'function' => 'apply', 'competencyid' => 0, 'competencyids' => []],
        ], $events[0]->other['competencies']);

        $log = placement_manager::get_log((int) $placement->id);
        $entry = array_values(array_filter($log, fn($l) => $l->action === placement_manager::ACTION_COMPETENCY))[0];
        $this->assertSame('SAFE-01: linked; MISSING: unknown; TWICE: ambiguous; SECRET: hidden', $entry->message);
    }

    /**
     * An update event lists the competencies the course module carries.
     */
    public function test_update_event_lists_competencies(): void {
        [$item, $safeid] = $this->create_item_with_competencies();
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $version2 = $this->generator->publish_new_version((int) $item->id, 'version 2');

        $sink = $this->redirectEvents();
        placement_manager::apply((int) $placement->id, (int) $version2->id);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof placement_updated));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame($safeid, $events[0]->other['competencies'][0]['competencyid']);
        $this->assertSame(2, $events[0]->other['competencies'][0]['level']);
    }

    /**
     * Without competencies on the site nothing is linked, and placing still works.
     */
    public function test_place_with_competencies_disabled(): void {
        [$item] = $this->create_item_with_competencies();
        set_config('enabled', 0, 'core_competency');
        $course = $this->getDataGenerator()->create_course();

        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);

        $this->assertSame(item_competencies::DISABLED, $placement->competencyresults[0]['result']);
        $this->assertSame(0, course_module_competency::count_records(['cmid' => $placement->cmid]));
    }

    /**
     * A teacher who may not manage course competencies places the item without competency links.
     */
    public function test_place_without_competency_permission(): void {
        global $DB;
        [$item] = $this->create_item_with_competencies();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'moodle/competency:coursecompetencymanage',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $this->assertSame(item_competencies::NOPERMISSION, $placement->competencyresults[0]['result']);
        $this->assertSame(0, course_module_competency::count_records(['cmid' => $placement->cmid]));
    }

    /**
     * An item without competencies places as before, without a competency log entry.
     */
    public function test_place_without_competencies(): void {
        $item = $this->generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $this->assertSame([], $placement->competencyresults);
        $this->assertCount(1, placement_manager::get_log((int) $placement->id));
    }

    /**
     * A course backup and restore keeps the placement and the competencies of the course module.
     */
    public function test_backup_restore_keeps_competencies(): void {
        global $CFG, $USER;
        [$item, $safeid] = $this->create_item_with_competencies();
        $course = $this->getDataGenerator()->create_course();
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        $newcourse = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $restored = array_values(placement_manager::get_for_course((int) $newcourse->id));
        $this->assertCount(1, $restored);
        $modulecompetencies = array_values(course_module_competency::get_records(['cmid' => $restored[0]->cmid]));
        $this->assertCount(1, $modulecompetencies);
        $this->assertEquals($safeid, $modulecompetencies[0]->get('competencyid'));
    }
}
