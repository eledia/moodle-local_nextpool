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

namespace local_nextpool\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_nextpool\local\access;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;

/**
 * Search the competencies an editor can give a catalogue item (F14, bug52).
 *
 * The competency field of the item form loads its choices here, so a site with many
 * competencies does not put all of them into every row of the form. Only competencies of
 * visible frameworks are found: placing refuses those of hidden ones.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_competencies extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Part of the idnumber or name of the competency', VALUE_DEFAULT, ''),
            'itemid' => new external_value(PARAM_INT, 'Item the form edits, 0 for a new item', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Find the competencies.
     *
     * @param string $query Part of the idnumber or name.
     * @param int $itemid Item the form edits, 0 for a new item.
     * @return array
     */
    public static function execute(string $query = '', int $itemid = 0): array {
        ['query' => $query, 'itemid' => $itemid] = self::validate_parameters(
            self::execute_parameters(),
            ['query' => $query, 'itemid' => $itemid]
        );
        self::validate_context(\context_system::instance());
        // Whoever may create items somewhere or edit this item uses the item form; reviewers alone do not.
        if ($itemid > 0) {
            access::require_edit(item_manager::get($itemid));
        } else {
            access::require_create();
        }
        return ['competencies' => item_competencies::search($query)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'competencies' => new external_multiple_structure(
                new external_single_structure([
                    'idnumber' => new external_value(PARAM_RAW, 'ID number of the competency, the value the form stores'),
                    'label' => new external_value(PARAM_RAW, 'Name, ID number and framework (HTML, already formatted)'),
                ])
            ),
        ]);
    }
}
