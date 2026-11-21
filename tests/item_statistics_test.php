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
use local_nextpool\local\item_statistics;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\task\compute_item_statistics;

/**
 * Tests for storing and reading the statistics of a catalogue item (F15, task126).
 *
 * The figures of xAPI items are tested end to end in mod_nextpool (content_type_xapi_statistics_test).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_statistics::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(compute_item_statistics::class)]
final class item_statistics_test extends \advanced_testcase {
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
     * A stored row as the scheduled task writes it.
     *
     * @param int $itemid Item id.
     * @param int $versionnr Version number, 0 for the total.
     * @param array $fields Values and flags.
     */
    private function insert_row(int $itemid, int $versionnr, array $fields = []): void {
        global $DB;
        $DB->insert_record(item_statistics::TABLE, (object) ($fields + [
            'itemid' => $itemid,
            'versionnr' => $versionnr,
            'started' => 10,
            'completed' => 8,
            'passed' => 6,
            'completionrate' => 80,
            'passrate' => 75,
            'scoremedian' => 82.5,
            'scorelow' => 70,
            'scorehigh' => 90.25,
            'durationmedian' => 840,
            'attemptsmedian' => 1,
            'timecalculated' => 1000,
        ]));
    }

    /**
     * A type without learning data keeps no figures; the placements are counted live (q111, F15.AC06).
     */
    public function test_type_without_learning_data(): void {
        global $DB;
        $item = $this->generator->create_published_item(['duration' => 900]);
        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();
        placement_manager::place((int) $item->id, (int) $first->id, 0, policy::AUTO);
        placement_manager::place((int) $item->id, (int) $first->id, 0, policy::AUTO);
        placement_manager::place((int) $item->id, (int) $second->id, 0, policy::AUTO);

        $this->assertFalse(item_statistics::supports($item));
        $this->assertFalse(item_statistics::compute((int) $item->id));
        $this->assertFalse($DB->record_exists(item_statistics::TABLE, ['itemid' => $item->id]));

        $statistics = item_statistics::get((int) $item->id);
        $this->assertFalse($statistics->supported);
        $this->assertSame(2, $statistics->courses);
        $this->assertSame(3, $statistics->activities);
        $this->assertSame(900, $statistics->expectedduration);
        $this->assertNull($statistics->timecalculated);
        $this->assertNull($statistics->total);
        $this->assertNull($statistics->coursecount);
        $this->assertCount(1, $statistics->versions);
        $this->assertSame(1, $statistics->versions[0]->versionnr);
        $this->assertFalse($statistics->versions[0]->used);
        $this->assertNull($statistics->versions[0]->figures);
    }

    /**
     * Stored rows come back as figure sets with types and flags; versions are listed with "not used yet" and
     * "withdrawn" (p110-d), versions with figures that no longer exist too.
     */
    public function test_get_reads_stored_figures(): void {
        $item = $this->generator->create_published_item();
        $second = $this->generator->publish_new_version((int) $item->id, 'version 2');
        version_manager::set_status((int) $second->id, status::WITHDRAWN);
        $this->generator->publish_new_version((int) $item->id, 'version 3');
        $this->insert_row((int) $item->id, 0, [
            'coursecount' => 2,
            'passrate' => null,
            'scoremedian' => null,
            'suppressed' => 'passrate:derived,scoremedian:none',
        ]);
        $this->insert_row((int) $item->id, 2, ['scorelow' => null, 'suppressed' => 'scorelow:small']);
        $this->insert_row((int) $item->id, 7, [
            'started' => null,
            'suppressed' => 'unknown:small,started:small',
        ]);

        $statistics = item_statistics::get((int) $item->id);

        $this->assertSame(1000, $statistics->timecalculated);
        $this->assertSame(2, $statistics->coursecount);
        $total = $statistics->total;
        $this->assertSame(10, $total->started);
        $this->assertSame(8, $total->completed);
        $this->assertSame(6, $total->passed);
        $this->assertSame(80.0, $total->completionrate);
        $this->assertNull($total->passrate);
        $this->assertNull($total->scoremedian);
        $this->assertSame(70.0, $total->scorelow);
        $this->assertSame(90.25, $total->scorehigh);
        $this->assertSame(840, $total->durationmedian);
        $this->assertSame(1, $total->attemptsmedian);
        $this->assertSame(
            ['passrate' => item_statistics::FLAG_DERIVED, 'scoremedian' => item_statistics::FLAG_NONE],
            $total->flags
        );

        $this->assertSame([1, 2, 3, 7], array_column($statistics->versions, 'versionnr'));
        [$v1, $v2, $v3, $v7] = $statistics->versions;
        $this->assertFalse($v1->used);
        $this->assertFalse($v1->withdrawn);
        $this->assertTrue($v2->used);
        $this->assertTrue($v2->withdrawn);
        $this->assertSame((int) $second->id, $v2->versionid);
        $this->assertSame(['scorelow' => item_statistics::FLAG_SMALL], $v2->figures->flags);
        $this->assertFalse($v3->used);
        $this->assertNull($v7->versionid);
        $this->assertTrue($v7->used);
        $this->assertSame(['started' => item_statistics::FLAG_SMALL], $v7->figures->flags);
    }

    /**
     * The statistics go with a deleted item (plan 21 §5.1).
     */
    public function test_deleted_with_item(): void {
        global $DB;
        $item = $this->generator->create_item();
        $this->insert_row((int) $item->id, 0);

        item_manager::delete((int) $item->id);

        $this->assertFalse($DB->record_exists(item_statistics::TABLE, ['itemid' => $item->id]));
    }

    /**
     * The scheduled task clears the figures of items without placements and of deleted items (FR-ALL-04).
     */
    public function test_task_clears_items_without_placements(): void {
        global $DB;
        $item = $this->generator->create_published_item();
        $this->insert_row((int) $item->id, 0);
        $this->insert_row(999999, 0);
        $task = \core\task\manager::get_scheduled_task(compute_item_statistics::class);
        $this->assertNotEmpty($task);

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('Computed the statistics of 0 catalogue items.', $output);
        $this->assertSame(0, $DB->count_records(item_statistics::TABLE));
    }
}
