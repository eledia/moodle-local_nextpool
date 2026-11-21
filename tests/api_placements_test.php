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

use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;

/**
 * Public placement list for other plugins (task181, LernHive ADR-P31).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversMethod(api::class, 'get_placements_by_uuid')]
final class api_placements_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Offers of a new version come from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Several items in several courses, several placements per item, with applied and pending versions.
     */
    public function test_placements_of_several_items(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $other = $this->generator->create_published_item();
        $notify = placement_manager::place((int) $item->id, (int) $course1->id, 0, policy::NOTIFY);
        $pinned = placement_manager::place((int) $item->id, (int) $course2->id, 0, policy::PINNED);
        $third = placement_manager::place((int) $other->id, (int) $course2->id, 0, policy::NOTIFY);
        $v2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);

        $result = api::get_placements_by_uuid([$item->uuid, $other->uuid, $item->uuid]);

        $this->assertSame([$item->uuid, $other->uuid], array_keys($result));
        $this->assertCount(2, $result[$item->uuid]);
        [$first, $second] = $result[$item->uuid];
        $this->assertSame((int) $notify->id, $first->placementid);
        $this->assertSame((int) $notify->cmid, $first->cmid);
        $this->assertSame((int) $course1->id, $first->courseid);
        $this->assertSame('resource', $first->modname);
        $this->assertSame((int) $item->id, $first->itemid);
        $this->assertSame($item->uuid, $first->itemuuid);
        $this->assertSame((int) $notify->appliedversionid, $first->appliedversionid);
        $this->assertSame(1, $first->appliedversionnr);
        $this->assertSame((int) $v2->id, $first->pendingversionid);
        $this->assertSame(2, $first->pendingversionnr);
        $this->assertSame(policy::NOTIFY, $first->policy);
        $this->assertSame((int) $notify->timeplaced, $first->timeplaced);
        $this->assertSame((int) $notify->timeplaced, $first->timeapplied);

        $this->assertSame((int) $pinned->cmid, $second->cmid);
        $this->assertSame((int) $course2->id, $second->courseid);
        $this->assertNull($second->pendingversionid);
        $this->assertNull($second->pendingversionnr);
        $this->assertSame(policy::PINNED, $second->policy);

        $this->assertCount(1, $result[$other->uuid]);
        $this->assertSame((int) $third->cmid, $result[$other->uuid][0]->cmid);

        // Only public fields, no person (placedby) in the result.
        $this->assertSame([
            'placementid', 'cmid', 'courseid', 'modname', 'itemid', 'itemuuid', 'appliedversionid', 'appliedversionnr',
            'pendingversionid', 'pendingversionnr', 'policy', 'timeplaced', 'timeapplied',
        ], array_keys((array) $first));
    }

    /**
     * After an update the applied version and the time it arrived change; a policy change does not move that time.
     */
    public function test_applied_version_after_update(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $v2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        placement_manager::apply((int) $placement->id, (int) $v2->id);
        $DB->set_field(placement_manager::LOG_TABLE, 'timecreated', 1000, ['placementid' => $placement->id]);
        $DB->set_field(placement_manager::LOG_TABLE, 'timecreated', 2000, [
            'placementid' => $placement->id,
            'action' => placement_manager::ACTION_UPDATE,
        ]);
        placement_manager::set_policy((int) $placement->id, policy::PINNED);

        $row = api::get_placements_by_uuid([$item->uuid])[$item->uuid][0];
        $this->assertSame((int) $v2->id, $row->appliedversionid);
        $this->assertSame(2, $row->appliedversionnr);
        $this->assertNull($row->pendingversionid);
        $this->assertSame(2000, $row->timeapplied);
        $this->assertSame(policy::PINNED, $row->policy);
    }

    /**
     * Unknown uuids and items without placements give an empty list; empty input gives an empty result.
     */
    public function test_unknown_uuid_and_item_without_placement(): void {
        $item = $this->generator->create_published_item();
        $unknown = \core\uuid::generate();

        $this->assertSame([$unknown => [], $item->uuid => []], api::get_placements_by_uuid([$unknown, $item->uuid, '']));
        $this->assertSame([], api::get_placements_by_uuid([]));
    }

    /**
     * Course modules being deleted (recycle bin, asynchronous deletion) and deleted ones are left out.
     */
    public function test_deleted_course_modules(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $kept = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $deleting = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $deleted = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $deleting->cmid]);
        // Without the recycle bin's backup; the placement goes with the course module either way.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        course_delete_module((int) $deleted->cmid);

        $rows = api::get_placements_by_uuid([$item->uuid])[$item->uuid];
        $this->assertSame([(int) $kept->cmid], array_column($rows, 'cmid'));

        delete_course($course, false);
        $this->assertSame([$item->uuid => []], api::get_placements_by_uuid([$item->uuid]));
    }

    /**
     * The number of reads does not grow with the number of uuids.
     */
    public function test_query_count(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $uuids = [];
        $reads = [];
        foreach ([1, 4] as $count) {
            while (count($uuids) < $count) {
                $item = $this->generator->create_published_item();
                placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
                placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
                $uuids[] = $item->uuid;
            }
            $before = $DB->perf_get_reads();
            $result = api::get_placements_by_uuid($uuids);
            $reads[$count] = $DB->perf_get_reads() - $before;
            $this->assertCount($count, $result);
        }
        $this->assertSame(1, $reads[1]);
        $this->assertSame($reads[1], $reads[4], 'Reads do not grow with the number of uuids.');
    }
}
