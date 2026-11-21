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

use core_external\external_function_parameters;
use core_external\external_value;
use local_nextpool\local\feedback_service;

/**
 * Subscribe to or unsubscribe from notifications about a catalogue item (F08.AC05, q76).
 *
 * Same checks as feedback.php through {@see feedback_service} (TD-304).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_subscription extends feedback_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'courseid' => self::courseid_parameter(),
            'subscribed' => new external_value(PARAM_BOOL, 'True to subscribe, false to unsubscribe'),
        ]);
    }

    /**
     * Run the action.
     *
     * @param int $itemid Item id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param bool $subscribed True to subscribe.
     * @return array
     */
    public static function execute(int $itemid, int $courseid, bool $subscribed): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['itemid' => $itemid, 'courseid' => $courseid, 'subscribed' => $subscribed]
        );
        self::validate_feedback_context($params['courseid']);
        $subscribed = feedback_service::set_subscription($params['itemid'], $params['courseid'], $params['subscribed']);
        return ['message' => get_string($subscribed ? 'subscribed' : 'unsubscribed', 'local_nextpool'), 'commentid' => 0];
    }
}
