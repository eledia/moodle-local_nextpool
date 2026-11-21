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
use local_nextpool\local\placement_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\item_page;

/**
 * Items uploaded from a course survive the deletion of the activity and of the course (M06 task78b, test95, q56).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_page::class)]
final class course_deletion_test extends \advanced_testcase {
    /** @var \core_course_category Course category of the course. */
    private \core_course_category $category;
    /** @var \stdClass Course the item was uploaded from. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->category = $this->getDataGenerator()->create_category();
        $this->course = $this->getDataGenerator()->create_course(['category' => $this->category->id]);
    }

    /**
     * Published xAPI item bound to the course, with the fixture package as version 1.
     *
     * @param bool $place Whether to place it in the course, which unpacks the package as a direct upload does.
     * @return \stdClass
     */
    private function create_course_item(bool $place = true): \stdClass {
        /** @var \mod_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        $item = $generator->create_xapi_item([
            'contextid' => \context_course::instance($this->course->id)->id,
            'origincourseid' => $this->course->id,
        ]);
        if ($place) {
            $generator->place($item, $this->course);
        }
        return $item;
    }

    /**
     * Number of unpacked package files of the item's current version in a context.
     *
     * @param \stdClass $item Item.
     * @param int $contextid Context.
     * @return int
     */
    private function count_package_files(\stdClass $item, int $contextid): int {
        return count(get_file_storage()->get_area_files(
            $contextid,
            'local_nextpool',
            version_manager::FILEAREA_XAPIPACKAGE,
            (int) $item->currentversionid,
            'id',
            false
        ));
    }

    /**
     * Deleting the activity keeps the item; the editorial list marks it as without placement.
     */
    public function test_delete_activity(): void {
        global $DB;
        $item = $this->create_course_item(false);
        /** @var \mod_nextpool_generator $modgenerator */
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        $placement = $modgenerator->place($item, $this->course);
        $systemcontextid = [\context_system::instance()->id];
        $this->assertCount(0, item_manager::search($systemcontextid, ['unplaced' => 1]));

        course_delete_module((int) $placement->cmid);

        $item = item_manager::get((int) $item->id);
        $this->assertSame(status::PUBLISHED, $item->status);
        $this->assertEquals(\context_course::instance($this->course->id)->id, $item->contextid);
        $this->assertFalse($DB->record_exists(placement_manager::TABLE, ['id' => $placement->id]));
        $this->assertEquals([$item->id], array_keys(item_manager::search($systemcontextid, ['unplaced' => 1])));
    }

    /**
     * Deleting the course moves its items to the course category and withdraws them; the origin stays (M06.AC11).
     */
    public function test_delete_course(): void {
        global $DB;
        $item = $this->create_course_item();
        item_manager::set_tags((int) $item->id, ['safety']);
        $coursecontextid = \context_course::instance($this->course->id)->id;
        $this->assertGreaterThan(0, $this->count_package_files($item, $coursecontextid));
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $draft = $generator->create_item(['type' => 'xapi', 'contextid' => $coursecontextid,
            'origincourseid' => $this->course->id]);
        // A catalogue item of the category placed in the course is not affected.
        $catalogueitem = $generator->create_published_item(['contextid' => $this->category->get_context()->id]);
        placement_manager::place((int) $catalogueitem->id, (int) $this->course->id, 0, 'auto');
        $this->assertStringContainsString('course/view.php', item_page::get_origin_course_link($item));

        delete_course($this->course, false);

        $categorycontextid = $this->category->get_context()->id;
        $item = item_manager::get((int) $item->id);
        $this->assertEquals($categorycontextid, $item->contextid);
        $this->assertSame(status::WITHDRAWN, $item->status);
        $this->assertEquals($this->course->id, $item->origincourseid);
        $this->assertGreaterThan(0, $this->count_package_files($item, $categorycontextid));
        $this->assertEquals([$categorycontextid], $DB->get_fieldset('tag_instance', 'contextid', ['itemid' => $item->id,
            'component' => 'local_nextpool', 'itemtype' => item_manager::TAG_ITEMTYPE]));
        $this->assertSame('Uploaded from course: Deleted course', item_page::get_origin_course_link($item));

        $draft = item_manager::get((int) $draft->id);
        $this->assertEquals($categorycontextid, $draft->contextid);
        $this->assertSame(status::DRAFT, $draft->status);

        $catalogueitem = item_manager::get((int) $catalogueitem->id);
        $this->assertEquals($categorycontextid, $catalogueitem->contextid);
        $this->assertSame(status::PUBLISHED, $catalogueitem->status);
    }

    /**
     * An item widened to the whole site is no longer bound to the course and stays published.
     */
    public function test_delete_course_after_widening(): void {
        $item = $this->create_course_item();
        item_manager::update((int) $item->id, (object) ['contextid' => \context_system::instance()->id]);

        delete_course($this->course, false);

        $item = item_manager::get((int) $item->id);
        $this->assertEquals(\context_system::instance()->id, $item->contextid);
        $this->assertSame(status::PUBLISHED, $item->status);
        $this->assertEquals($this->course->id, $item->origincourseid);
    }

    /**
     * Deleting the course category with its courses: items of the courses go to the parent and are withdrawn.
     */
    public function test_delete_category_with_course(): void {
        $parent = $this->getDataGenerator()->create_category();
        $this->category->change_parent($parent->id);
        $item = $this->create_course_item();

        \core_course_category::get($this->category->id)->delete_full(false);

        $item = item_manager::get((int) $item->id);
        $this->assertEquals($parent->get_context()->id, $item->contextid);
        $this->assertSame(status::WITHDRAWN, $item->status);
        $this->assertGreaterThan(0, $this->count_package_files($item, $parent->get_context()->id));
    }
}
