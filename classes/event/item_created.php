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

namespace local_nextpool\event;

/**
 * Event: a catalogue item was created.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_created extends \core\event\base {
    #[\Override]
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_nextpool_item';
    }

    /**
     * Create the event from an item record.
     *
     * @param \stdClass $item Row from local_nextpool_item.
     * @return self
     */
    public static function create_from_item(\stdClass $item): self {
        return self::create([
            'objectid' => $item->id,
            'contextid' => \local_nextpool\local\access::get_context($item)->id,
            'other' => ['uuid' => $item->uuid, 'status' => $item->status],
        ]);
    }

    #[\Override]
    public static function get_name() {
        return get_string('event_item_created', 'local_nextpool');
    }

    #[\Override]
    public function get_description() {
        return "The user with id '$this->userid' created the catalogue item with id '$this->objectid'.";
    }

    #[\Override]
    public function get_url() {
        return new \moodle_url('/local/nextpool/item.php', ['id' => $this->objectid]);
    }

    #[\Override]
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->objectid)) {
            throw new \coding_exception('The objectid must be set.');
        }
    }

    #[\Override]
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Other is restored unchanged (bug63, Q-22).
     *
     * Its ids point to catalogue items and versions, which are site-wide and not part of course backups: a
     * restore on the same site, such as a course copy or import, keeps them valid, and other[uuid] identifies
     * the item on any site.
     *
     * @return false
     */
    #[\Override]
    public static function get_other_mapping() {
        return false;
    }
}
