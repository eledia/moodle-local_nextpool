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

use local_nextpool\local\access;
use local_nextpool\output\area_nav;

/**
 * Tests for the section navigation of the catalogue area (task64).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(area_nav::class)]
final class area_nav_test extends \advanced_testcase {
    /**
     * Create a user who holds the given capabilities in a context.
     *
     * @param string[] $capabilities Capabilities.
     * @param \context $context Context of the role assignment.
     * @return \stdClass User.
     */
    private function create_user_with(array $capabilities, \context $context): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Each user sees the tabs of the pages they may open, in a fixed order.
     */
    public function test_editorial_tabs_follow_capabilities(): void {
        $this->resetAfterTest();

        $this->setAdminUser();
        $this->assertSame(
            [area_nav::ITEMS, area_nav::REVIEWS, area_nav::CATEGORIES, area_nav::SOURCES, area_nav::CUSTOMFIELDS],
            array_keys(area_nav::get_editorial_tabs())
        );

        // An editor in a course category reaches the item list only.
        $category = $this->getDataGenerator()->create_category();
        $this->setUser($this->create_user_with([access::MANAGE], \context_coursecat::instance($category->id)));
        $this->assertSame([area_nav::ITEMS], array_keys(area_nav::get_editorial_tabs()));
        $this->assertSame('', area_nav::editorial(area_nav::ITEMS));

        // A reviewer in a course category also reaches the review overview (task85).
        $this->setUser($this->create_user_with([access::REVIEW], \context_coursecat::instance($category->id)));
        $this->assertSame([area_nav::ITEMS, area_nav::REVIEWS], array_keys(area_nav::get_editorial_tabs()));
        $this->assertStringContainsString('Waiting for review (0)', area_nav::editorial(area_nav::ITEMS));

        // A site-level manager of storage locations and categories, without editorial rights.
        $this->setUser($this->create_user_with(
            ['local/nextpool:managesources', 'local/nextpool:managecategories'],
            \context_system::instance()
        ));
        $tabs = area_nav::get_editorial_tabs();
        $this->assertSame([area_nav::CATEGORIES, area_nav::SOURCES], array_keys($tabs));
        $this->assertSame('/local/nextpool/categories.php', $tabs[area_nav::CATEGORIES]->out_as_local_url(false));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], area_nav::get_editorial_tabs());
    }

    /**
     * The tab row names the area and marks the current page; on a sub-page the current tab stays a link.
     */
    public function test_editorial_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/items.php');

        $html = area_nav::editorial(area_nav::CATEGORIES);
        $this->assertStringContainsString('aria-label="Catalogue sections"', $html);
        $this->assertStringContainsString('Catalogue items', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString('Item custom fields', $html);
        $this->assertStringNotContainsString('/local/nextpool/categories.php', $html);

        $html = area_nav::editorial(area_nav::CATEGORIES, true);
        $this->assertStringContainsString('/local/nextpool/categories.php', $html);
    }

    /**
     * The course row links the catalogue and the content in the course, with its count, and keeps the course.
     */
    public function test_course_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_url('/local/nextpool/catalogue.php', ['courseid' => $course->id]);

        $html = area_nav::course($course->id, area_nav::CATALOGUE, 2);
        $this->assertStringContainsString('Catalogue content in this course (2)', $html);
        $this->assertStringContainsString('/local/nextpool/placements.php?courseid=' . $course->id, $html);
    }

    /**
     * A system page refuses users without its capability.
     */
    public function test_setup_system_page_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $page = new \moodle_page();
        $this->expectException(\required_capability_exception::class);
        area_nav::setup_system_page(
            $page,
            new \moodle_url('/local/nextpool/sources.php'),
            area_nav::SOURCES,
            'Storage locations'
        );
    }
}
