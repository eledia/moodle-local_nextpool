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

use local_nextpool\customfield\item_handler;
use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\version_manager;

/**
 * Tests for catalogue items in a course category that is deleted (bug33).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_nextpool_pre_course_category_delete')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_nextpool_pre_course_category_delete_move')]
final class category_deletion_test extends \advanced_testcase {
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
     * A published item in a category context, with a tag, an unpacked package file and a custom field value.
     *
     * @param \core_course_category $category Category.
     * @return \stdClass Item.
     */
    private function create_item_in(\core_course_category $category): \stdClass {
        $context = $category->get_context();
        $item = $this->generator->create_published_item(['contextid' => $context->id]);
        item_manager::set_tags((int) $item->id, ['safety']);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_nextpool',
            'filearea' => version_manager::FILEAREA_XAPIPACKAGE,
            'itemid' => (int) $item->currentversionid,
            'filepath' => '/',
            'filename' => 'index.html',
        ], '<html></html>');
        item_handler::instance()->instance_form_save((object) ['id' => $item->id, 'customfield_publisher' => 'eLeDia'], true);
        return item_manager::get((int) $item->id);
    }

    /**
     * Assert that the item and everything stored in its context is in the given context.
     *
     * @param \stdClass $item Item.
     * @param \context $expected Expected visibility context.
     */
    private function assert_item_in(\stdClass $item, \context $expected): void {
        global $DB;
        $item = item_manager::get((int) $item->id);
        $this->assertEquals($expected->id, $item->contextid);
        $this->assertSame($expected->id, access::get_context($item)->id);
        $this->assertEquals(
            [$expected->id],
            array_values(array_unique($DB->get_fieldset('tag_instance', 'contextid', ['itemid' => $item->id,
                'component' => 'local_nextpool', 'itemtype' => item_manager::TAG_ITEMTYPE]))),
            'Tags follow the item.'
        );
        $this->assertCount(1, get_file_storage()->get_area_files(
            $expected->id,
            'local_nextpool',
            version_manager::FILEAREA_XAPIPACKAGE,
            (int) $item->currentversionid,
            'id',
            false
        ), 'The unpacked package survives the deletion of the old context.');
        $this->assertEquals(
            [$expected->id],
            $DB->get_fieldset('customfield_data', 'contextid', ['instanceid' => $item->id]),
            'Custom field data follows the item.'
        );
    }

    /**
     * Create the custom field "publisher" for catalogue items.
     */
    private function create_custom_field(): void {
        /** @var \core_customfield_generator $cfgenerator */
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category(['component' => 'local_nextpool', 'area' => 'item', 'itemid' => 0]);
        $cfgenerator->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'publisher']);
    }

    /**
     * Deleting a category and moving its content: the item follows the content, its placement keeps working.
     */
    public function test_delete_move(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $this->create_custom_field();
        $parent = $this->getDataGenerator()->create_category();
        $category = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $item = $this->create_item_in($category);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $category->delete_move($parent->id);

        $this->assert_item_in($item, $parent->get_context());
        // The course moved to the parent as well, so the item is still available there and updates arrive.
        $version = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);
        $this->assertEquals($version->id, placement_manager::get((int) $placement->id)->pendingversionid);
        placement_manager::apply((int) $placement->id, (int) $version->id);
    }

    /**
     * Deleting a category with all its content: items of the category and of its subcategories go to the parent.
     */
    public function test_delete_full(): void {
        $this->create_custom_field();
        $parent = $this->getDataGenerator()->create_category();
        $category = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $subcategory = $this->getDataGenerator()->create_category(['parent' => $category->id]);
        $item = $this->create_item_in($category);
        $subitem = $this->create_item_in($subcategory);
        $course = $this->getDataGenerator()->create_course(['category' => $parent->id]);

        $category->delete_full(false);

        $this->assert_item_in($item, $parent->get_context());
        $this->assert_item_in($subitem, $parent->get_context());
        $this->generator->publish_new_version((int) $item->id, 'version 2');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $this->assertNotEmpty($placement->cmid);

        // A top-level category hands its items to the system context.
        $top = $this->getDataGenerator()->create_category();
        $topitem = $this->create_item_in($top);
        $top->delete_full(false);
        $this->assert_item_in($topitem, \context_system::instance());
    }

    /**
     * An item whose context vanished without the callbacks is readable and moves to the system context.
     */
    public function test_orphaned_item(): void {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $item = $this->generator->create_published_item(['contextid' => $category->get_context()->id]);
        // Simulate a deletion that bypassed the callbacks.
        $deadcontextid = (int) $category->get_context()->id;
        $DB->delete_records('context', ['id' => $deadcontextid]);
        \context_helper::reset_caches();

        $item = item_manager::get((int) $item->id);
        $this->assertSame(\context_system::instance()->id, access::get_context($item)->id);
        $this->assertTrue(access::can_view($item));
        $this->generator->publish_new_version((int) $item->id, 'version 2');

        $other = $this->getDataGenerator()->create_category();
        $other->delete_full(false);
        $this->assertEquals(\context_system::instance()->id, item_manager::get((int) $item->id)->contextid);
    }
}
