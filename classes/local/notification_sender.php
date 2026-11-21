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

namespace local_nextpool\local;

/**
 * Send catalogue notifications in the recipient's language (bug169).
 *
 * @package local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notification_sender {
    /**
     * Build all translated and filtered content after switching the language.
     *
     * @param int $userid Recipient id.
     * @param callable $build Factory returning a message in the current language.
     */
    public static function send(int $userid, callable $build): void {
        global $CFG;
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended) {
            return;
        }
        $previous = force_current_language($user->lang ?: $CFG->lang);
        try {
            $message = $build();
            $message->component = 'local_nextpool';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            message_send($message);
        } finally {
            force_current_language($previous);
        }
    }
}
