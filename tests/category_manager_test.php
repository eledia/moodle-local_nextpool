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

use local_nextpool\local\category_manager;

/**
 * Tests for the catalogue category tree (F10).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(category_manager::class)]
final class category_manager_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creating builds materialised paths and appends to the end of the siblings.
     */
    public function test_create_paths_and_order(): void {
        $safety = category_manager::create('  Safety ');
        $it = category_manager::create('IT');
        $fire = category_manager::create('Fire', (int) $safety->id);

        $this->assertSame('Safety', $safety->name);
        $this->assertSame('/' . $safety->id, $safety->path);
        $this->assertSame('/' . $safety->id . '/' . $fire->id, $fire->path);
        $this->assertGreaterThan((int) $safety->sortorder, (int) $it->sortorder);
        $this->assertEquals(1, $fire->sortorder);

        $tree = category_manager::get_tree();
        $this->assertSame(['Safety', 'Fire', 'IT'], array_map(fn($c) => $c->name, $tree));
        $this->assertSame([0, 1, 0], array_map(fn($c) => $c->depth, $tree));
        $this->assertSame([
            (int) $safety->id => 'Safety',
            (int) $fire->id => 'Safety / Fire',
            (int) $it->id => 'IT',
        ], category_manager::get_menu());
        $this->assertSame('Safety / Fire', category_manager::get_full_name((int) $fire->id));
    }

    /**
     * Names are required; parents must exist.
     */
    public function test_create_rejects_invalid(): void {
        try {
            category_manager::create('   ');
            $this->fail('An empty name must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_required', $e->errorcode);
        }
        $this->expectExceptionMessage(get_string('error_invalidcategory', 'local_nextpool'));
        category_manager::create('Orphan', 999999);
    }

    /**
     * Moving a category takes its subtree along; moving into its own subtree is refused.
     */
    public function test_update_moves_subtree(): void {
        $a = category_manager::create('A');
        $b = category_manager::create('B');
        $a1 = category_manager::create('A1', (int) $a->id);
        $a11 = category_manager::create('A11', (int) $a1->id);

        $a = category_manager::update((int) $a->id, 'A renamed', (int) $b->id);
        $this->assertSame('A renamed', $a->name);
        $this->assertSame('/' . $b->id . '/' . $a->id, $a->path);
        $this->assertSame($a->path . '/' . $a1->id, category_manager::get((int) $a1->id)->path);
        $this->assertSame($a->path . '/' . $a1->id . '/' . $a11->id, category_manager::get((int) $a11->id)->path);
        // A "move to" menu leaves out the category and its whole subtree.
        $this->assertSame([], category_manager::get_menu((int) $b->id));
        $this->assertSame([(int) $b->id], array_keys(category_manager::get_menu((int) $a->id)));

        try {
            category_manager::update((int) $a->id, 'A', (int) $a11->id);
            $this->fail('Moving into a descendant must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_categoryloop', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        category_manager::update((int) $a->id, 'A', (int) $a->id);
    }

    /**
     * Moving up and down swaps siblings and stops at the ends.
     */
    public function test_move(): void {
        $first = category_manager::create('First');
        $second = category_manager::create('Second');
        $third = category_manager::create('Third');

        $this->assertFalse(category_manager::move((int) $first->id, true));
        $this->assertTrue(category_manager::move((int) $third->id, true));
        $this->assertSame(['First', 'Third', 'Second'], array_map(fn($c) => $c->name, category_manager::get_tree()));
        $this->assertTrue(category_manager::move((int) $first->id, false));
        $this->assertSame(['Third', 'First', 'Second'], array_map(fn($c) => $c->name, category_manager::get_tree()));
        $this->assertFalse(category_manager::move((int) $second->id, false));
    }

    /**
     * Only empty categories can be deleted.
     */
    public function test_delete(): void {
        $parent = category_manager::create('Parent');
        $child = category_manager::create('Child', (int) $parent->id);
        $this->assertFalse(category_manager::can_delete((int) $parent->id));

        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_item(['categoryid' => $child->id]);
        $this->assertFalse(category_manager::can_delete((int) $child->id));

        local\item_manager::update((int) $item->id, (object) ['categoryid' => null]);
        $this->assertTrue(category_manager::can_delete((int) $child->id));
        category_manager::delete((int) $child->id);
        category_manager::delete((int) $parent->id);
        $this->assertSame([], category_manager::get_tree());

        $this->expectException(\dml_missing_record_exception::class);
        category_manager::delete((int) $parent->id);
    }
}
