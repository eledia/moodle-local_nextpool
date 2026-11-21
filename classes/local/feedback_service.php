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
 * Feedback actions of the current user with their permission checks (F08, task81, TD-304, TD-305).
 *
 * feedback.php (forms without JavaScript) and the external functions (module local_nextpool/feedback) both call
 * these methods, so both ways check the same rules: access to the item from the given course or editorially
 * ({@see access::can_comment()}), then the rule of the action.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class feedback_service {
    /**
     * Post a comment or a reply.
     *
     * @param int $itemid Item id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param string $content Text.
     * @param int $versionid Version the comment is about, 0 for the item in general; ignored for replies.
     * @param int $parentid Comment to reply to, 0 for a new thread.
     * @return \stdClass The comment.
     */
    public static function add(int $itemid, int $courseid, string $content, int $versionid = 0, int $parentid = 0): \stdClass {
        $item = item_manager::get($itemid);
        access::require_comment($item, $courseid);
        if (!$parentid && $versionid) {
            $version = version_manager::get($versionid);
            // From a course, only versions a course can have: numbered, published or withdrawn (or applied there).
            if (
                $courseid > 0 && (int) $version->itemid === $itemid
                && ($version->versionnr === null
                    || (!in_array($version->status, [status::PUBLISHED, status::WITHDRAWN], true)
                        && comment_manager::get_default_versionid($item, $courseid) !== (int) $version->id))
            ) {
                throw new \moodle_exception('invalidrecord', 'error', '', version_manager::TABLE);
            }
        }
        return comment_manager::add($itemid, $content, FORMAT_MOODLE, $versionid ?: null, $parentid ?: null, 0, $courseid);
    }

    /**
     * Change the text of an own comment.
     *
     * @param int $commentid Comment id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param string $content New text.
     * @return \stdClass The comment.
     */
    public static function update(int $commentid, int $courseid, string $content): \stdClass {
        [$comment] = self::get_accessible($commentid, $courseid);
        if (!access::can_update_comment($comment)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('commentedit', 'local_nextpool'));
        }
        return comment_manager::update($commentid, $content);
    }

    /**
     * Remove a comment: own ones, or any as the editorial team (q70).
     *
     * @param int $commentid Comment id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @return bool True if a placeholder stays (the comment has replies).
     */
    public static function delete(int $commentid, int $courseid): bool {
        [$comment, $item] = self::get_accessible($commentid, $courseid);
        if (!access::can_delete_comment($item, $comment)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('commentdelete', 'local_nextpool'));
        }
        return comment_manager::delete($commentid);
    }

    /**
     * Mark a comment as done or open it again.
     *
     * @param int $commentid Comment id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param bool $resolved True for done.
     * @return \stdClass The comment.
     */
    public static function set_resolved(int $commentid, int $courseid, bool $resolved): \stdClass {
        [$comment, $item] = self::get_accessible($commentid, $courseid);
        if (!access::can_resolve_comment($item, $comment)) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('commentresolve', 'local_nextpool'));
        }
        return comment_manager::set_resolved($commentid, $resolved);
    }

    /**
     * Subscribe or unsubscribe the current user.
     *
     * @param int $itemid Item id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param bool $subscribed True to subscribe.
     * @return bool The new state.
     */
    public static function set_subscription(int $itemid, int $courseid, bool $subscribed): bool {
        global $USER;
        $item = item_manager::get($itemid);
        access::require_comment($item, $courseid);
        if ($subscribed) {
            subscription_manager::subscribe($itemid, (int) $USER->id, $courseid);
        } else {
            subscription_manager::unsubscribe($itemid, (int) $USER->id);
        }
        return $subscribed;
    }

    /**
     * A comment and its item, after checking that the user may read the item's feedback.
     *
     * @param int $commentid Comment id.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @return array{0:\stdClass,1:\stdClass} Comment and item.
     */
    public static function get_accessible(int $commentid, int $courseid): array {
        $comment = comment_manager::get($commentid);
        $item = item_manager::get((int) $comment->itemid);
        access::require_comment($item, $courseid);
        return [$comment, $item];
    }
}
