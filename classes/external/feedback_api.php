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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared parts of the feedback web services (F08, task81).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class feedback_api extends external_api {
    /**
     * Check the login for the course view or the editorial view, as on feedback.php (TD-304).
     *
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     */
    protected static function validate_feedback_context(int $courseid): void {
        self::validate_context($courseid > 0 ? \context_course::instance($courseid) : \context_system::instance());
    }

    /**
     * Course parameter of every feedback function.
     *
     * @return external_value
     */
    protected static function courseid_parameter(): external_value {
        return new external_value(PARAM_INT, 'Course the user comes from, 0 for the editorial view');
    }

    /**
     * Return structure: the message to announce and the comment concerned.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => new external_value(PARAM_TEXT, 'Result to announce in the live region'),
            'commentid' => new external_value(PARAM_INT, 'Comment concerned, 0 if none or removed'),
        ]);
    }
}
