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
 * Tests for the hook callbacks.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(api::class, 'can_open_editorial')]
#[\PHPUnit\Framework\Attributes\CoversMethod(api::class, 'get_editorial_url')]
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * The update banner needs the capabilities of the placements page: viewcatalogue and manageactivities.
     */
    public function test_can_see_update_banner(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertTrue(hook_callbacks::can_see_update_banner($context, (int) $teacher->id));
        $this->assertFalse(hook_callbacks::can_see_update_banner($context, (int) $student->id));

        // Without viewcatalogue the placements page is closed, so the banner must not link to it.
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('local/nextpool:viewcatalogue', CAP_PROHIBIT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(has_capability('moodle/course:manageactivities', $context, $teacher));
        $this->assertFalse(hook_callbacks::can_see_update_banner($context, (int) $teacher->id));
    }

    /**
     * The banner names the waiting update and links to the placements page, without an assertive alert (bug64).
     */
    public function test_update_banner(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $item = $generator->create_published_item();
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $this->setUser($teacher);
        $this->assertNull(hook_callbacks::get_update_banner($context));

        $this->setAdminUser();
        $generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);
        $this->setUser($teacher);
        $html = hook_callbacks::get_update_banner($context);
        $this->assertStringContainsString(get_string('updatesavailable_one', 'local_nextpool', [
            'url' => (new \moodle_url('/local/nextpool/placements.php', ['courseid' => $course->id]))->out(),
        ]), $html);
        $this->assertStringNotContainsString('role="alert"', $html);
        $this->assertStringContainsString('id="local_nextpool-updatebanner"', $html);
    }

    /**
     * Entries the hooks add to the user menu of the current user.
     *
     * @return string[] Title identifiers.
     */
    private function user_menu_entries(): array {
        global $PAGE, $USER;
        $menu = user_get_user_navigation_info($USER, $PAGE);
        return array_values(array_filter(array_map(
            static fn($item) => $item->titleidentifier ?? '',
            $menu->navitems
        )));
    }

    /**
     * Everyone with editorial rights, also only in a course category, gets "Nextpool" in the
     * user menu; others do not (U-20).
     */
    public function test_user_menu_entry(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/user/lib.php');
        $this->resetAfterTest();
        $PAGE->set_url('/my/');
        $category = $this->getDataGenerator()->create_category();
        $editorrole = $this->getDataGenerator()->create_role(['shortname' => 'catalogueeditor']);
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $editorrole, \context_system::instance()->id, true);
        $reviewerrole = $this->getDataGenerator()->create_role(['shortname' => 'cataloguereviewer']);
        assign_capability('local/nextpool:reviewversions', CAP_ALLOW, $reviewerrole, \context_system::instance()->id, true);

        $editor = $this->getDataGenerator()->create_user();
        role_assign($editorrole, $editor->id, \context_coursecat::instance($category->id)->id);
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign($reviewerrole, $reviewer->id, \context_system::instance()->id);
        $learner = $this->getDataGenerator()->create_user();

        $entry = 'usermenucatalogue,local_nextpool';
        $this->setUser($editor);
        $this->assertFalse(has_capability('local/nextpool:manageitems', \context_system::instance()));
        $this->assertContains($entry, $this->user_menu_entries(), 'Editor in a category only.');
        $this->setUser($reviewer);
        $this->assertContains($entry, $this->user_menu_entries(), 'Reviewer.');
        $this->setAdminUser();
        $this->assertContains($entry, $this->user_menu_entries(), 'Admin.');
        $this->setUser($learner);
        $this->assertNotContains($entry, $this->user_menu_entries(), 'No editorial rights.');
        $this->setGuestUser();
        $this->assertNotContains($entry, $this->user_menu_entries(), 'Guest.');

        // The entry leads to the editorial list.
        $this->setUser($editor);
        $hook = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($hook);
        $item = $hook->get_navitems()[0];
        $this->assertSame('link', $item->itemtype);
        $this->assertSame('/local/nextpool/items.php', $item->url->out_as_local_url(false));
        $this->assertSame(get_string('usermenucatalogue', 'local_nextpool'), $item->title);
    }

    /**
     * Keys of the top navigation for the current user.
     *
     * @return array
     */
    private function primary_keys(): array {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url('/my/');
        $primary = new \core\navigation\views\primary($page);
        $primary->initialise();
        return array_map('strval', $primary->children->get_key_list());
    }

    /**
     * Editors and reviewers, also only in a course category, find the catalogue in the top navigation;
     * learners and guests do not.
     */
    public function test_primary_navigation_entry(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $editorrole = $this->getDataGenerator()->create_role(['shortname' => 'catalogueeditor']);
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $editorrole, \context_system::instance()->id, true);
        $editor = $this->getDataGenerator()->create_user();
        role_assign($editorrole, $editor->id, \context_coursecat::instance($category->id)->id);
        $learner = $this->getDataGenerator()->create_user();

        $this->setUser($editor);
        $this->assertContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys(), 'Editor in a category only.');
        $this->setAdminUser();
        $this->assertContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys(), 'Admin.');
        $this->setUser($learner);
        $this->assertNotContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys(), 'No editorial rights.');
        $this->setGuestUser();
        $this->assertNotContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys(), 'Guest.');

        // The entry leads to the editorial list.
        $this->setUser($editor);
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url('/my/');
        $primary = new \core\navigation\views\primary($page);
        $primary->initialise();
        $node = $primary->get(hook_callbacks::PRIMARY_NODE_KEY);
        $this->assertSame('/local/nextpool/items.php', $node->action()->out_as_local_url(false));
        $this->assertSame(get_string('usermenucatalogue', 'local_nextpool'), $node->text);
    }

    /**
     * The public check for the editorial catalogue matches the user menu entry (task179).
     */
    public function test_can_open_editorial(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $reviewerrole = $this->getDataGenerator()->create_role(['shortname' => 'cataloguereviewer']);
        assign_capability('local/nextpool:reviewversions', CAP_ALLOW, $reviewerrole, \context_system::instance()->id, true);
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign($reviewerrole, $reviewer->id, \context_coursecat::instance($category->id)->id);

        $this->setUser($reviewer);
        $this->assertTrue(api::can_open_editorial(), 'Reviewer in a category only.');
        $this->setAdminUser();
        $this->assertTrue(api::can_open_editorial(), 'Admin.');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(api::can_open_editorial(), 'No editorial rights.');
        $this->setGuestUser();
        $this->assertFalse(api::can_open_editorial(), 'Guest.');
        $this->setUser();
        $this->assertFalse(api::can_open_editorial(), 'Not logged in.');
        $this->assertSame('/local/nextpool/items.php', api::get_editorial_url()->out_as_local_url(false));
    }

    /**
     * With the LernHive launcher showing Nextpool, the user menu entry "Nextpool" and the top navigation node go;
     * a missing launcher, a "no" or a failure keep them (task179, q137). The other entries stay.
     */
    public function test_launcher_hides_entries(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/user/lib.php');
        $this->resetAfterTest();
        $PAGE->set_url('/my/');
        if (class_exists(hook_callbacks::LAUNCHER)) {
            $this->markTestSkipped('LernHive is installed; the stand-in cannot be loaded.');
        }
        $this->setAdminUser();
        $entry = 'usermenucatalogue,local_nextpool';

        // No launcher.
        $this->assertFalse(hook_callbacks::shown_in_launcher());
        $this->assertContains($entry, $this->user_menu_entries());
        $this->assertContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys());

        require_once(__DIR__ . '/fixtures/lernhive_launcher_manager.php');
        $launcher = \local_lernhive\launcher\launcher_manager::class;
        try {
            // The launcher does not show Nextpool.
            $launcher::$answer = false;
            $this->assertFalse(hook_callbacks::shown_in_launcher());
            $this->assertSame('local_nextpool', end($launcher::$asked));
            $this->assertContains($entry, $this->user_menu_entries());
            $this->assertContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys());

            // Anything but true keeps the entries.
            $launcher::$answer = 1;
            $this->assertFalse(hook_callbacks::shown_in_launcher());

            // The launcher shows Nextpool: only the review entry stays in the user menu.
            $launcher::$answer = true;
            $this->assertTrue(hook_callbacks::shown_in_launcher());
            $entries = $this->user_menu_entries();
            $this->assertNotContains($entry, $entries);
            $this->assertContains('reviewqueue,local_nextpool', $entries);
            $this->assertNotContains(hook_callbacks::PRIMARY_NODE_KEY, $this->primary_keys());

            // The launcher fails.
            $launcher::$answer = new \RuntimeException('launcher broken');
            $this->assertFalse(hook_callbacks::shown_in_launcher());
            $this->assertDebuggingCalled('LernHive launcher: launcher broken');
            $this->assertContains($entry, $this->user_menu_entries());
            $this->resetDebugging();
        } finally {
            $launcher::$answer = false;
            $launcher::$asked = [];
        }
    }

    /**
     * Teachers see "Content catalogue" as a tab right after "Participants"; learners do not get it.
     */
    public function test_secondary_navigation_position(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $keys = function () use ($course): array {
            global $PAGE;
            $PAGE = new \moodle_page();
            $PAGE->set_course($course);
            $PAGE->set_url('/course/view.php', ['id' => $course->id]);
            $secondary = new \core\navigation\views\secondary($PAGE);
            $secondary->initialise();
            return array_map('strval', $secondary->children->get_key_list());
        };

        $this->setUser($teacher);
        $teacherkeys = $keys();
        $this->assertContains(hook_callbacks::COURSE_NODE_KEY, $teacherkeys);
        $participants = array_search('participants', $teacherkeys, true);
        $this->assertNotFalse($participants);
        $this->assertSame(hook_callbacks::COURSE_NODE_KEY, $teacherkeys[$participants + 1]);

        $this->setUser($student);
        $this->assertNotContains(hook_callbacks::COURSE_NODE_KEY, $keys());
    }
}
