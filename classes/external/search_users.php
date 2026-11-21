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
use local_nextpool\local\item_manager;

/**
 * Search the accounts of the site for the person fields of the catalogue (F08 item editors, F09 authors).
 *
 * The core user selector needs moodle/user:viewalldetails in the system context, which editors of a course
 * category and item editors usually lack (t84-f). This search needs the right to edit the item (or to create
 * items, without one) and returns names; identity fields only as far as core shows them to the person.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users extends external_api {
    /** @var int Most results returned. */
    public const LIMIT = 30;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Part of the name or an identity field', VALUE_DEFAULT, ''),
            'itemid' => new external_value(PARAM_INT, 'Item the field belongs to, 0 for a new item', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Find the accounts.
     *
     * @param string $query Part of the name or an identity field.
     * @param int $itemid Item the field belongs to, 0 for a new item.
     * @return array
     */
    public static function execute(string $query = '', int $itemid = 0): array {
        global $DB;
        ['query' => $query, 'itemid' => $itemid] = self::validate_parameters(
            self::execute_parameters(),
            ['query' => $query, 'itemid' => $itemid]
        );
        $system = \context_system::instance();
        self::validate_context($system);
        if ($itemid > 0) {
            access::require_edit(item_manager::get($itemid));
        } else {
            access::require_create();
        }
        $query = trim($query);
        if ($query === '') {
            return ['users' => []];
        }

        $identity = \core_user\fields::get_identity_fields($system, false);
        [$where, $params] = users_search_sql($query, 'u', USER_SEARCH_CONTAINS, $identity);
        $fields = \core_user\fields::for_name()->with_identity($system, false)->get_sql('u', false, '', '', false)->selects;
        $users = $DB->get_records_sql(
            "SELECT u.id, $fields FROM {user} u WHERE $where AND u.suspended = 0 ORDER BY u.lastname, u.firstname, u.id",
            $params,
            0,
            self::LIMIT
        );
        $result = [];
        foreach ($users as $user) {
            $details = [];
            foreach ($identity as $field) {
                if (!empty($user->$field)) {
                    $details[] = s($user->$field);
                }
            }
            $label = s(fullname($user));
            $result[] = [
                'id' => (int) $user->id,
                'label' => $details ? $label . ' (' . implode(', ', $details) . ')' : $label,
            ];
        }
        return ['users' => $result];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User id'),
                    'label' => new external_value(PARAM_RAW, 'Full name and the identity fields the person may see (HTML)'),
                ])
            ),
        ]);
    }
}
