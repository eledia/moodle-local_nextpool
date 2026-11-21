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

namespace local_nextpool\task;

use local_nextpool\local\placement_manager;

/**
 * Apply a published version to one placement (policy auto, F06).
 *
 * Custom data: placementid, versionid. Skips placements that were deleted or
 * already carry this or a newer version, so a retried task does no harm. If
 * the version cannot be written in place, it is offered to the teachers instead.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_update extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task_applyupdate', 'local_nextpool');
    }

    #[\Override]
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $placement = $DB->get_record(placement_manager::TABLE, ['id' => (int) $data->placementid]);
        if (!$placement) {
            mtrace('Placement ' . (int) $data->placementid . ' no longer exists.');
            return;
        }
        // Only for the message: placement_manager::apply() checks again inside the placement lock and never goes back.
        if (placement_manager::is_up_to_date($placement, (int) $data->versionid)) {
            mtrace('Placement ' . (int) $placement->id . ' is already up to date.');
            return;
        }
        $target = $DB->get_field('local_nextpool_version', 'versionnr', ['id' => (int) $data->versionid]);
        if (placement_manager::apply_automatically((int) $placement->id, (int) $data->versionid)) {
            $current = placement_manager::get((int) $placement->id);
            if (placement_manager::is_up_to_date($current, (int) $data->versionid)) {
                mtrace('Placement ' . (int) $placement->id . ' updated to version ' . (int) $target . '.');
            } else {
                mtrace('Placement ' . (int) $placement->id . ' kept its current version.');
            }
        } else {
            mtrace('Placement ' . (int) $placement->id . ': version ' . (int) $target . ' offered to the teachers instead.');
        }
    }
}
