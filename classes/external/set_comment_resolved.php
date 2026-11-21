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
 * Mark a comment on a catalogue item as done or open it again (F08.AC03).
 *
 * Same checks as feedback.php through {@see feedback_service} (TD-304).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_comment_resolved extends feedback_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'commentid' => new external_value(PARAM_INT, 'Comment id'),
            'courseid' => self::courseid_parameter(),
            'resolved' => new external_value(PARAM_BOOL, 'True for done, false for open'),
        ]);
    }

    /**
     * Run the action.
     *
     * @param int $commentid Comment id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param bool $resolved True for done.
     * @return array
     */
    public static function execute(int $commentid, int $courseid, bool $resolved): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['commentid' => $commentid, 'courseid' => $courseid, 'resolved' => $resolved]
        );
        self::validate_feedback_context($params['courseid']);
        $comment = feedback_service::set_resolved($params['commentid'], $params['courseid'], $params['resolved']);
        return [
            'message' => get_string($params['resolved'] ? 'commentresolved' : 'commentreopened', 'local_nextpool'),
            'commentid' => (int) $comment->id,
        ];
    }
}
