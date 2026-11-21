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
 * A newer catalogue version was written into a placed course module.
 *
 * other[competencies] as in placement_created.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placement_updated extends \core\event\base {
    #[\Override]
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_nextpool_placement';
    }

    /**
     * Create the event from a placement after the update.
     *
     * @param \stdClass $placement Row from local_nextpool_placement.
     * @param \stdClass $item Row from local_nextpool_item.
     * @param int $fromversionid Version id before the update.
     * @return self
     */
    public static function create_from_placement(\stdClass $placement, \stdClass $item, int $fromversionid): self {
        return self::create([
            'objectid' => $placement->id,
            'contextid' => \context_module::instance((int) $placement->cmid)->id,
            'courseid' => $placement->courseid,
            'other' => [
                'itemid' => $item->id,
                'uuid' => $item->uuid,
                'fromversionid' => $fromversionid,
                'versionid' => $placement->appliedversionid,
                // Competency, level and function of the item for Skills and ContentHub (F14, Skills q04/q16).
                'competencies' => \local_nextpool\local\item_competencies::get_event_data((int) $item->id, (int) $placement->cmid),
            ],
        ]);
    }

    #[\Override]
    public static function get_name() {
        return get_string('event_placement_updated', 'local_nextpool');
    }

    #[\Override]
    public function get_description() {
        return "The course module with id '$this->contextinstanceid' was updated from version id "
            . "'{$this->other['fromversionid']}' to version id '{$this->other['versionid']}' "
            . "of the catalogue item with id '{$this->other['itemid']}'.";
    }

    #[\Override]
    public function get_url() {
        return new \moodle_url('/local/nextpool/placements.php', ['courseid' => $this->courseid]);
    }

    #[\Override]
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->objectid) || !isset($this->other['itemid'], $this->other['versionid'])) {
            throw new \coding_exception('The objectid, other[itemid] and other[versionid] must be set.');
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
