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
use local_nextpool\local\comment_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\output\catalogue_page;

/**
 * Pages read their rows without one query per row (bug56).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(placement_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalogue_page::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(comment_manager::class, 'count_open')]
final class query_count_test extends \advanced_testcase {
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
     * The placements page gets item and version numbers with the placements.
     */
    public function test_placement_overview(): void {
        global $DB;
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['title' => 'Handout']);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $pinned = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);
        $other = $this->generator->create_published_item();
        placement_manager::place((int) $other->id, (int) $course->id, 0, policy::AUTO);

        $reads = $DB->perf_get_reads();
        $overview = placement_manager::get_overview((int) $course->id);
        $this->assertSame(1, $DB->perf_get_reads() - $reads);
        $this->assertCount(3, $overview);
        $row = $overview[$placement->id];
        $this->assertSame('Handout', $row->itemtitle);
        $this->assertEquals($item->contextid, $row->itemcontextid);
        $this->assertEquals(1, $row->appliedversionnr);
        $this->assertEquals(2, $row->currentversionnr);
        $this->assertEquals(2, $row->pendingversionnr);
        $this->assertEquals(0, $overview[$pinned->id]->pendingversionnr);
    }

    /**
     * The course catalogue loads the tags of all cards at once.
     */
    public function test_catalogue_tags(): void {
        global $DB, $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_url('/local/nextpool/catalogue.php', ['courseid' => $course->id]);
        $output = $PAGE->get_renderer('core');
        $reads = [];
        foreach ([1, 4] as $count) {
            while (count(catalogue::get_items((int) $course->id)) < $count) {
                $item = $this->generator->create_published_item();
                item_manager::set_tags((int) $item->id, ['safety & health', 'onboarding']);
            }
            $items = catalogue::get_items((int) $course->id);
            $page = new catalogue_page((int) $course->id, $items, [], true);
            $page->export_for_template($output);
            $before = $DB->perf_get_reads();
            $export = $page->export_for_template($output);
            $reads[$count] = $DB->perf_get_reads() - $before;
        }
        $this->assertSame($reads[1], $reads[4], 'Reads do not grow with the number of cards.');
        $this->assertSame('safety &amp; health, onboarding', $export['items'][0]['tags']);
    }

    /**
     * The course catalogue counts the open comments of all cards with one query (task173).
     */
    public function test_catalogue_open_comments(): void {
        global $DB, $PAGE;
        $this->redirectMessages();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_url('/local/nextpool/catalogue.php', ['courseid' => $course->id]);
        $output = $PAGE->get_renderer('core');
        $reads = [];
        foreach ([1, 4] as $count) {
            while (count(catalogue::get_items((int) $course->id)) < $count) {
                $item = $this->generator->create_published_item(['title' => 'Item ' . $count]);
                comment_manager::add((int) $item->id, 'First');
                comment_manager::add((int) $item->id, 'Second');
            }
            $items = catalogue::get_items((int) $course->id);
            $page = new catalogue_page((int) $course->id, $items, [], true);
            $page->export_for_template($output);
            $before = $DB->perf_get_reads();
            $export = $page->export_for_template($output);
            $reads[$count] = $DB->perf_get_reads() - $before;
        }
        $this->assertSame($reads[1], $reads[4], 'Reads do not grow with the number of cards.');
        $action = $export['items'][0]['feedbackaction'];
        $this->assertStringContainsString('data-local-nextpool-itemdialog="feedback"', $action);
        $this->assertMatchesRegularExpression('~<span class="visually-hidden"[^>]*>Feedback on [^<]+, 2 open</span>~', $action);
        $this->assertMatchesRegularExpression('~<span class="badge[^"]*"[^>]*>2</span>~', $action);
    }
}
