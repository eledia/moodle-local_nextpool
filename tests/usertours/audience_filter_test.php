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

namespace local_nextpool\usertours;

use local_nextpool\local\tour_manager;
use tool_usertours\tour;

/**
 * Core filters apply actual course and editorial permissions (task177).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(audience_filter::class)]
final class audience_filter_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        tour_manager::install();
    }

    /**
     * Teachers and custom course roles qualify; students and unrelated teachers do not.
     */
    public function test_teacher_audience_uses_course_capabilities(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $tour = tour::instance(get_config('local_nextpool', 'tour_installed_catalogue'));
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertFalse(audience_filter::filter_matches($tour, $context));
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(audience_filter::filter_matches($tour, $context));
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:viewcatalogue', CAP_ALLOW, $role, $context->id);
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $role, $context->id);
        $custom = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($custom->id, $course->id, $role);
        $this->setUser($custom);
        $this->assertTrue(audience_filter::filter_matches($tour, $context));
        $other = $this->getDataGenerator()->create_course();
        $this->assertFalse(audience_filter::filter_matches($tour, \context_course::instance($other->id)));
    }

    /**
     * A custom category editor qualifies without a system-wide management role.
     */
    public function test_editorial_audience_uses_category_capabilities(): void {
        global $PAGE;
        $PAGE->set_url('/local/nextpool/items.php');
        $tour = tour::instance(get_config('local_nextpool', 'tour_installed_items'));
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertFalse(audience_filter::filter_matches($tour, \context_system::instance()));
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $role, $context->id);
        role_assign($role, $user->id, $context->id);
        $this->assertTrue(audience_filter::filter_matches($tour, \context_system::instance()));
    }

    /**
     * The item tour checks the particular item rather than any editorial permission elsewhere.
     */
    public function test_item_audience_uses_item_permissions(): void {
        global $PAGE;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $plugin = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $plugin->create_published_item(['contextid' => $context->id]);
        $other = $plugin->create_published_item();
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $role, $context->id);
        role_assign($role, $user->id, $context->id);
        $this->setUser($user);
        $tour = tour::instance(get_config('local_nextpool', 'tour_installed_item'));
        $PAGE->set_url('/local/nextpool/item.php', ['id' => $item->id]);
        $this->assertTrue(audience_filter::filter_matches($tour, \context_system::instance()));
        $PAGE->set_url('/local/nextpool/item.php', ['id' => $other->id]);
        $this->assertFalse(audience_filter::filter_matches($tour, \context_system::instance()));
    }

    /**
     * Item editors can use Core's reset link, whose AJAX page context has no item id.
     */
    public function test_item_editor_can_reset_tour_through_core(): void {
        global $PAGE;
        $item = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_published_item();
        $user = $this->getDataGenerator()->create_user();
        \local_nextpool\local\item_editor_manager::add((int) $item->id, (int) $user->id);
        $this->setUser($user);
        $PAGE->set_url('/lib/ajax/service.php');
        $PAGE->set_context(\context_system::instance());
        $id = (int) get_config('local_nextpool', 'tour_installed_item');
        $result = \tool_usertours\external\tour::reset_tour(
            $id,
            \context_system::instance()->id,
            (new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]))->out(false)
        );
        $result = \core_external\external_api::clean_returnvalue(
            \tool_usertours\external\tour::reset_tour_returns(),
            $result
        );
        $this->assertSame($id, $result['startTour']);
    }

    /**
     * The Core hook includes our server-side filter and leaves unrelated tours unrestricted.
     */
    public function test_core_hook_and_unrelated_tours(): void {
        $this->assertContains(audience_filter::class, \tool_usertours\helper::get_all_filters());
        $tour = new tour();
        $this->assertTrue(audience_filter::filter_matches($tour, \context_system::instance()));
    }
}
