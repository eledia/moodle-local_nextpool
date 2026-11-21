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
use local_nextpool\local\item_manager;
use local_nextpool\local\status;

/**
 * Tests for editorial permissions.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_manager::class)]
final class access_test extends \advanced_testcase {
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
     * Create a user with a role holding the given capabilities in a context.
     *
     * @param string[] $capabilities Capabilities to allow.
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
     * Authors submit for review, reviewers publish and withdraw; both can send back to draft.
     */
    public function test_status_capabilities(): void {
        $item = $this->generator->create_item();
        $system = \context_system::instance();
        $author = $this->create_user_with([access::MANAGE], $system);
        $reviewer = $this->create_user_with([access::REVIEW], $system);
        $nobody = $this->getDataGenerator()->create_user();

        $this->assertTrue(access::can_change_status($item, status::DRAFT, status::REVIEW, $author->id));
        $this->assertFalse(access::can_change_status($item, status::DRAFT, status::REVIEW, $reviewer->id));
        $this->assertTrue(access::can_change_status($item, status::REVIEW, status::DRAFT, $author->id));
        $this->assertTrue(access::can_change_status($item, status::REVIEW, status::DRAFT, $reviewer->id));
        $this->assertFalse(access::can_change_status($item, status::REVIEW, status::PUBLISHED, $author->id));
        $this->assertTrue(access::can_change_status($item, status::REVIEW, status::PUBLISHED, $reviewer->id));
        $this->assertTrue(access::can_change_status($item, status::PUBLISHED, status::WITHDRAWN, $reviewer->id));
        // The workflow is checked as well: no direct jump from draft to published.
        $this->assertFalse(access::can_change_status($item, status::DRAFT, status::PUBLISHED, $reviewer->id));

        $this->assertTrue(access::can_view($item, $author->id));
        $this->assertTrue(access::can_view($item, $reviewer->id));
        $this->assertFalse(access::can_view($item, $nobody->id));
        $this->assertTrue(access::can_edit($item, $author->id));
        $this->assertFalse(access::can_edit($item, $reviewer->id));

        $this->setUser($author);
        $this->expectException(\required_capability_exception::class);
        access::require_change_status($item, status::REVIEW, status::PUBLISHED);
    }

    /**
     * A role in a course category grants editorial rights only for items available in that category.
     */
    public function test_category_scope(): void {
        $category = $this->getDataGenerator()->create_category();
        $other = $this->getDataGenerator()->create_category();
        $incategory = $this->generator->create_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $inother = $this->generator->create_item(['contextid' => \context_coursecat::instance($other->id)->id]);
        $sitewide = $this->generator->create_item();

        $editor = $this->create_user_with([access::MANAGE], \context_coursecat::instance($category->id));
        $this->assertTrue(access::can_edit($incategory, $editor->id));
        $this->assertFalse(access::can_edit($inother, $editor->id));
        $this->assertFalse(access::can_edit($sitewide, $editor->id));
    }

    /**
     * A category-level editor may use the editorial pages and lists only the items of their category.
     */
    public function test_editorial_list_category_editor(): void {
        $category = $this->getDataGenerator()->create_category();
        $subcategory = $this->getDataGenerator()->create_category(['parent' => $category->id]);
        $other = $this->getDataGenerator()->create_category();
        $incategory = $this->generator->create_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $insub = $this->generator->create_item(['contextid' => \context_coursecat::instance($subcategory->id)->id]);
        $this->generator->create_item(['contextid' => \context_coursecat::instance($other->id)->id]);
        $this->generator->create_item();

        $editor = $this->create_user_with([access::MANAGE], \context_coursecat::instance($category->id));
        $this->setUser($editor);
        $this->assertTrue(access::can_use_editorial());
        $this->assertTrue(access::can_create());
        $contextids = access::get_editorial_contextids();
        $this->assertNotContains((int) \context_system::instance()->id, $contextids);

        $items = item_manager::search($contextids);
        $this->assertEqualsCanonicalizing([(int) $incategory->id, (int) $insub->id], array_map('intval', array_keys($items)));
        $this->assertSame(2, item_manager::count_search($contextids));
        $this->assertSame(1, item_manager::count_search($contextids, ['search' => $insub->title]));

        // A reviewer in the category sees the same items but may not create any.
        $reviewer = $this->create_user_with([access::REVIEW], \context_coursecat::instance($category->id));
        $this->setUser($reviewer);
        $this->assertTrue(access::can_use_editorial());
        $this->assertFalse(access::can_create());
        $this->assertSame(2, item_manager::count_search(access::get_editorial_contextids()));

        // The category settings link the item list for category editors (bug23).
        global $CFG;
        require_once($CFG->dirroot . '/local/nextpool/lib.php');
        $node = \navigation_node::create('settings');
        local_nextpool_extend_navigation_category_settings($node, \context_coursecat::instance($category->id));
        $this->assertNotFalse($node->find('local_nextpool_items', \navigation_node::TYPE_SETTING));
        $node = \navigation_node::create('settings');
        local_nextpool_extend_navigation_category_settings($node, \context_coursecat::instance($other->id));
        $this->assertFalse($node->find('local_nextpool_items', \navigation_node::TYPE_SETTING));

        // Without any editorial capability the pages are closed and the list is empty.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(access::can_use_editorial());
        $this->assertSame([], item_manager::search(access::get_editorial_contextids()));
        $this->expectException(\required_capability_exception::class);
        access::require_editorial();
    }

    /**
     * A site-level editor lists items of all contexts.
     */
    public function test_editorial_list_site_editor(): void {
        $category = $this->getDataGenerator()->create_category();
        $this->generator->create_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $this->generator->create_item();

        $editor = $this->create_user_with([access::MANAGE], \context_system::instance());
        $this->setUser($editor);
        $this->assertSame(2, item_manager::count_search(access::get_editorial_contextids()));
    }
}
