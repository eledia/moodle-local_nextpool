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

use local_nextpool\local\item_statistics;

/**
 * Compute the anonymous statistics of all placed catalogue items once a day (F15, plan 21 p110-b, q110).
 *
 * The page of the statistics shows the time of this calculation and never computes on opening (FR-F15-06).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compute_item_statistics extends \core\task\scheduled_task {
    #[\Override]
    public function get_name() {
        return get_string('task_computeitemstatistics', 'local_nextpool');
    }

    #[\Override]
    public function execute() {
        $stored = item_statistics::compute_all();
        mtrace('Computed the statistics of ' . $stored . ' catalogue items.');
    }
}
