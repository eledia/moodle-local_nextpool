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

namespace local_nextpool\customfield;

use core_customfield\field_controller;
use core_customfield\handler;
use local_nextpool\local\access;
use local_nextpool\local\item_manager;

/**
 * Custom fields of catalogue items (F01, adr13: core first).
 *
 * Admins define the fields site-wide; editors fill them per item. Values are
 * stored in the item's visibility context, like the item itself.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_handler extends handler {
    /** @var bool Keep one handler instance per request. */
    protected const CACHE_HANDLER_INSTANCES = true;

    /**
     * The handler for catalogue items.
     *
     * @return self
     */
    public static function instance(): self {
        return static::create();
    }

    #[\Override]
    public function can_configure(): bool {
        return has_capability('moodle/site:config', $this->get_configuration_context());
    }

    #[\Override]
    public function can_edit(field_controller $field, int $instanceid = 0): bool {
        // Item editors edit the custom fields of their item like the other metadata (F08, task84).
        if ($instanceid > 0) {
            return access::can_edit(item_manager::get($instanceid));
        }
        return has_capability(access::MANAGE, $this->get_instance_context($instanceid));
    }

    #[\Override]
    public function can_view(field_controller $field, int $instanceid): bool {
        if ($instanceid > 0) {
            return access::can_view(item_manager::get($instanceid));
        }
        return has_any_capability([access::MANAGE, access::REVIEW], $this->get_instance_context($instanceid));
    }

    #[\Override]
    public function get_configuration_context(): \context {
        return \context_system::instance();
    }

    #[\Override]
    public function get_configuration_url(): \moodle_url {
        return new \moodle_url('/local/nextpool/customfields.php');
    }

    #[\Override]
    public function get_instance_context(int $instanceid = 0): \context {
        if ($instanceid > 0) {
            return access::get_context(item_manager::get($instanceid));
        }
        return \context_system::instance();
    }

    /**
     * Move the custom field data of an item, and files of text area fields, to the item's new context (bug33).
     *
     * @param int $instanceid Item id.
     * @param int $tocontextid New visibility context of the item.
     */
    public function move_instance_data(int $instanceid, int $tocontextid): void {
        global $DB;
        $records = $DB->get_records_sql(
            'SELECT d.id, d.contextid, f.type
               FROM {customfield_data} d
               JOIN {customfield_field} f ON f.id = d.fieldid
               JOIN {customfield_category} c ON c.id = f.categoryid
              WHERE c.component = :component AND c.area = :area AND c.itemid = :itemid
                AND d.instanceid = :instanceid AND d.contextid <> :tocontextid',
            [
                'component' => $this->get_component(),
                'area' => $this->get_area(),
                'itemid' => $this->get_itemid(),
                'instanceid' => $instanceid,
                'tocontextid' => $tocontextid,
            ]
        );
        $fs = get_file_storage();
        foreach ($records as $record) {
            $component = 'customfield_' . $record->type;
            foreach ($fs->get_area_files((int) $record->contextid, $component, 'value', (int) $record->id, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['contextid' => $tocontextid], $file);
            }
            $fs->delete_area_files((int) $record->contextid, $component, 'value', (int) $record->id);
            $DB->set_field('customfield_data', 'contextid', $tocontextid, ['id' => $record->id]);
        }
    }
}
