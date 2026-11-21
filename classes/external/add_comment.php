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
 * Post a comment or a reply on a catalogue item (F08.AC01, AC02).
 *
 * Same checks as feedback.php through {@see feedback_service} (TD-304).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_comment extends feedback_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'courseid' => self::courseid_parameter(),
            'content' => new external_value(PARAM_RAW, 'Text of the comment'),
            'versionid' => new external_value(
                PARAM_INT,
                'Version the comment is about, 0 for the item in general',
                VALUE_DEFAULT,
                0
            ),
            'parentid' => new external_value(PARAM_INT, 'Comment to reply to, 0 for a new thread', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Run the action.
     *
     * @param int $itemid Item id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param string $content Text.
     * @param int $versionid Version, 0 for the item in general.
     * @param int $parentid Comment to reply to, 0 for a new thread.
     * @return array
     */
    public static function execute(int $itemid, int $courseid, string $content, int $versionid = 0, int $parentid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['itemid' => $itemid, 'courseid' => $courseid, 'content' => $content, 'versionid' => $versionid,
                'parentid' => $parentid]
        );
        self::validate_feedback_context($params['courseid']);
        $comment = feedback_service::add(
            $params['itemid'],
            $params['courseid'],
            $params['content'],
            $params['versionid'],
            $params['parentid']
        );
        return [
            'message' => get_string($comment->parentid ? 'replyposted' : 'commentposted', 'local_nextpool'),
            'commentid' => (int) $comment->id,
        ];
    }
}
