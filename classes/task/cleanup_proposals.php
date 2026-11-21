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

use local_nextpool\local\proposal_manager;

/**
 * Delete rejected and cancelled proposals 12 months after the decision (q67, task86).
 *
 * With each proposal go its snapshot, review history and comment links; a proposed new item without any other
 * version goes too (t86-b). The editorial team can delete such proposals earlier on the review page.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_proposals extends \core\task\scheduled_task {
    #[\Override]
    public function get_name() {
        return get_string('task_cleanupproposals', 'local_nextpool');
    }

    #[\Override]
    public function execute() {
        $deleted = proposal_manager::cleanup();
        mtrace('Deleted ' . $deleted . ' rejected or cancelled proposals.');
    }
}
