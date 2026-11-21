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
 * Remove a comment on a catalogue item; with replies a placeholder stays (F08.AC06, q70).
 *
 * Same checks as feedback.php through {@see feedback_service} (TD-304).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_comment extends feedback_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'commentid' => new external_value(PARAM_INT, 'Comment id'),
            'courseid' => self::courseid_parameter(),
        ]);
    }

    /**
     * Run the action.
     *
     * @param int $commentid Comment id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @return array
     */
    public static function execute(int $commentid, int $courseid): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['commentid' => $commentid, 'courseid' => $courseid]
        );
        self::validate_feedback_context($params['courseid']);
        $placeholder = feedback_service::delete($params['commentid'], $params['courseid']);
        return [
            'message' => get_string('commentdeleted', 'local_nextpool'),
            'commentid' => $placeholder ? $params['commentid'] : 0,
        ];
    }
}
