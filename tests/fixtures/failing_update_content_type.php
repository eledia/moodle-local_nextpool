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

namespace local_nextpool\fixtures;

/**
 * URL handler whose in-place update fails inside its own transaction, like the built-in handlers can (bug160).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_update_content_type extends \local_nextpool\local\content_type\url {
    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->update_record('url', (object) ['id' => $cm->instance, 'externalurl' => 'https://example.com/half-written']);
        throw new \moodle_exception('error_bookempty', 'local_nextpool');
    }
}
