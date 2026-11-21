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

use local_nextpool\output\editorial_navbar;

/**
 * Tests for the breadcrumb of the editorial pages (U-20).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(editorial_navbar::class)]
final class editorial_navbar_test extends \advanced_testcase {
    /**
     * Nodes the helper added: text and link.
     *
     * @param \stdClass|null $item Item.
     * @param string|null $current Current page.
     * @return array<int,array{0:string,1:?string}>
     */
    private function trail(?\stdClass $item, ?string $current): array {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/local/nextpool/items.php');
        editorial_navbar::add($PAGE, $item, $current);
        $page = $PAGE;
        $trail = [];
        foreach ($page->navbar->get_items() as $node) {
            if ($node->type === \navigation_node::TYPE_CUSTOM) {
                $trail[] = [(string) $node->text, $node->action ? $node->action->out_as_local_url(false) : null];
            }
        }
        // Core may put the home node of the user in front; the trail of the plugin starts with the list.
        $start = array_search('Catalogue items', array_column($trail, 0), true);
        return array_slice($trail, (int) $start);
    }

    /**
     * List, item and the pages below an item build the same trail; only the current page has no link.
     */
    public function test_trail(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $item = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_item(['title' => 'Fire & safety']);

        $this->assertSame([['Catalogue items', null]], $this->trail(null, null));
        $this->assertSame([
            ['Catalogue items', '/local/nextpool/items.php'],
            ['Add item', null],
        ], $this->trail(null, 'Add item'));
        $this->assertSame([
            ['Catalogue items', '/local/nextpool/items.php'],
            ['Fire &amp; safety', null],
        ], $this->trail($item, null));
        $this->assertSame([
            ['Catalogue items', '/local/nextpool/items.php'],
            ['Fire &amp; safety', '/local/nextpool/item.php?id=' . $item->id],
            ['Edit', null],
        ], $this->trail($item, 'Edit'));
    }
}
