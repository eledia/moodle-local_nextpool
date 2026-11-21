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
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\task\apply_update;
use local_nextpool\task\distribute_version;
use local_nextpool\task\send_update_notice;

/**
 * Deferred publication distribution and safe replay (bug164).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(distribute_version::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
final class distribute_version_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Retry does not duplicate offers, notifications or automatic update tasks.
     */
    public function test_distribution_can_be_replayed(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $notify = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $pinned = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $version = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $task = new distribute_version();
        $task->set_custom_data(['versionid' => (int) $version->id]);
        $this->assertNull(placement_manager::get((int) $notify->id)->pendingversionid);
        $task->execute();
        $task->execute();
        $this->assertEquals($version->id, placement_manager::get((int) $notify->id)->pendingversionid);
        $this->assertNull(placement_manager::get((int) $pinned->id)->pendingversionid);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(apply_update::class));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(send_update_notice::class));
        $this->assertSame(1, $DB->count_records(placement_manager::LOG_TABLE, [
            'placementid' => $notify->id, 'action' => placement_manager::ACTION_OFFER,
        ]));
        // A repeated publication event cannot enqueue another identical distribution task.
        $before = count(\core\task\manager::get_adhoc_tasks(distribute_version::class));
        observer::version_published(\local_nextpool\event\version_published::create_from_version($version, $item));
        $this->assertCount($before, \core\task\manager::get_adhoc_tasks(distribute_version::class));
    }

    /**
     * A busy item is retried rather than allowing two versions to distribute concurrently.
     */
    public function test_busy_distribution_can_be_retried(): void {
        global $CFG;
        // PostgreSQL advisory locks are reentrant on one connection; use Core row locks to hold a competing lease.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $version = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $task = new distribute_version();
        $task->set_custom_data(['versionid' => (int) $version->id]);
        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('distribute_' . $item->id, 0);
        $this->assertNotFalse($lock);
        try {
            try {
                $task->execute();
                $this->fail('A concurrent distribution must be retried.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_placementlocked', $e->errorcode);
                $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid);
            }
        } finally {
            $lock->release();
        }
        $task->execute();
        $this->assertEquals($version->id, placement_manager::get((int) $placement->id)->pendingversionid);
    }

    /**
     * Changes after queuing must not offer a deleted, withdrawn or superseded version.
     *
     * @param string $change Change after queuing.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('obsolete_versions')]
    public function test_obsolete_distribution_is_ignored(string $change): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $version = $this->generator->publish_new_version((int) $item->id, 'version 2');
        if ($change === 'withdrawn') {
            version_manager::set_status((int) $version->id, status::WITHDRAWN);
        } else if ($change === 'deleted') {
            version_manager::delete_all_for_item((int) $item->id);
        } else {
            $this->generator->publish_new_version((int) $item->id, 'version 3');
        }
        $task = new distribute_version();
        $task->set_custom_data(['versionid' => (int) $version->id]);
        $task->execute();
        $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(send_update_notice::class));
    }

    /**
     * Changes that make a queued distribution obsolete.
     *
     * @return array
     */
    public static function obsolete_versions(): array {
        return ['deleted' => ['deleted'], 'withdrawn' => ['withdrawn'], 'superseded' => ['superseded']];
    }
}
