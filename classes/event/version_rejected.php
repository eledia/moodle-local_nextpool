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
 * Event: a version of a catalogue item was rejected (F07, task79).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version_rejected extends \core\event\base {
    #[\Override]
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_nextpool_version';
    }

    /**
     * Create the event from a version and its item.
     *
     * @param \stdClass $version Row from local_nextpool_version.
     * @param \stdClass $item Row from local_nextpool_item.
     * @return self
     */
    public static function create_from_version(\stdClass $version, \stdClass $item): self {
        return self::create([
            'objectid' => $version->id,
            'contextid' => \local_nextpool\local\access::get_context($item)->id,
            'other' => ['itemid' => $item->id, 'uuid' => $item->uuid, 'versionnr' => $version->versionnr],
        ]);
    }

    #[\Override]
    public static function get_name() {
        return get_string('event_version_rejected', 'local_nextpool');
    }

    #[\Override]
    public function get_description() {
        return "The user with id '$this->userid' rejected the version with id '$this->objectid' "
            . "of the catalogue item with id '{$this->other['itemid']}'.";
    }

    #[\Override]
    public function get_url() {
        return new \moodle_url('/local/nextpool/review.php', ['versionid' => $this->objectid]);
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
