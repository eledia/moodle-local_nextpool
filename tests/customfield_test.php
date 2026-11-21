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
use local_nextpool\local\item_manager;
use local_nextpool\output\item_page;

/**
 * Tests for custom fields of catalogue items (F01).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_handler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_page::class)]
final class customfield_test extends \advanced_testcase {
    /**
     * Values are saved per item, shown on the item page, checked by capability and removed with the item.
     */
    public function test_custom_fields(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_customfield_generator $cfgenerator */
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category(['component' => 'local_nextpool', 'area' => 'item', 'itemid' => 0]);
        $field = $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'text',
            'shortname' => 'publisher',
            'name' => 'Publisher',
        ]);
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_item(['title' => 'Handout']);

        $handler = item_handler::instance();
        $this->assertTrue($handler->can_configure());
        $handler->instance_form_save((object) ['id' => $item->id, 'customfield_publisher' => 'eLeDia'], true);
        $values = array_map(fn($d) => $d->get_value(), $handler->get_instance_data((int) $item->id));
        $this->assertSame(['eLeDia'], array_values($values));

        $PAGE->set_url('/local/nextpool/item.php', ['id' => $item->id]);
        $data = (new item_page($item))->export_for_template($PAGE->get_renderer('core'));
        $labels = array_column($data['metadata'], 'value', 'label');
        $this->assertSame('eLeDia', $labels['Publisher'] ?? null);

        // A user without editorial rights in the item's context may not view or edit the field.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($handler->can_view($field, (int) $item->id));
        $this->assertFalse($handler->can_edit($field, (int) $item->id));

        $this->setAdminUser();
        item_manager::delete((int) $item->id);
        $this->assertFalse($DB->record_exists('customfield_data', ['fieldid' => $field->get('id'), 'instanceid' => $item->id]));
    }
}
