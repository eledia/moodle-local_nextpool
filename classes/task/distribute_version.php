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
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Distribute a published version without delaying the publishing request (F06, bug164).
 *
 * Custom data: versionid. Deleted, withdrawn and superseded versions do nothing.
 * Distribution is idempotent, so a retry does not repeat offers or notifications.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class distribute_version extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task_distributeversion', 'local_nextpool');
    }

    #[\Override]
    public function execute() {
        global $DB;
        $versionid = (int) $this->get_custom_data()->versionid;
        $version = $DB->get_record(version_manager::TABLE, ['id' => $versionid]);
        if (!$version || $version->status !== status::PUBLISHED) {
            return;
        }
        // Different versions of one item must not race and overwrite a newer pending offer.
        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')
            ->get_lock('distribute_' . (int) $version->itemid, 10);
        if (!$lock) {
            throw new \moodle_exception('error_placementlocked', 'local_nextpool');
        }
        try {
            // Recheck after waiting for an earlier distribution: publication and withdrawal may have changed it.
            if (!$DB->record_exists(version_manager::TABLE, ['id' => $versionid, 'status' => status::PUBLISHED])) {
                return;
            }
            if (!$DB->record_exists('local_nextpool_item', ['id' => $version->itemid, 'currentversionid' => $versionid])) {
                return;
            }
            placement_manager::distribute($versionid);
        } finally {
            $lock->release();
        }
    }
}
