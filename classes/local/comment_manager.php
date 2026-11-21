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

use local_nextpool\event\comment_created;
use local_nextpool\event\comment_deleted;

/**
 * Feedback on catalogue items: comments, one level of replies, done and open again (F08, task81, TD-307).
 *
 * Own tables instead of the core comment API (adr14): core knows no replies, no "done" and no versions. The
 * methods store and change data; callers check the permissions with {@see access}.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class comment_manager {
    /** @var string Table. */
    public const TABLE = 'local_nextpool_comment';

    /** @var string User preference: the person has seen the visibility notice and commented (q63). */
    public const PREF_NOTICE = 'local_nextpool_commentnotice';

    /**
     * Add a comment or a reply.
     *
     * A reply to a reply is attached to the same top-level comment (one level, F08.AC02) and takes over its
     * version. The author is subscribed automatically (q66) unless they unsubscribed before (q76); the other
     * subscribers are notified.
     *
     * @param int $itemid Item id.
     * @param string $content Text.
     * @param int $format Text format.
     * @param int|null $versionid Version the comment is about, null for the item in general.
     * @param int|null $parentid Comment this replies to, null for a top-level comment.
     * @param int $userid Author, 0 for the current user.
     * @param int $courseid Course the author comes from, 0 for the editorial view.
     * @return \stdClass The comment.
     * @throws \moodle_exception If the text is empty or the version or parent belongs to another item.
     */
    public static function add(
        int $itemid,
        string $content,
        int $format = FORMAT_MOODLE,
        ?int $versionid = null,
        ?int $parentid = null,
        int $userid = 0,
        int $courseid = 0
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $item = item_manager::get($itemid);
        self::require_content($content);

        if ($parentid) {
            $parent = self::get($parentid);
            if ((int) $parent->itemid !== $itemid) {
                throw new \moodle_exception('invalidrecord', 'error', '', self::TABLE);
            }
            if ($parent->parentid) {
                $parent = self::get((int) $parent->parentid);
            }
            $parentid = (int) $parent->id;
            $versionid = $parent->versionid !== null ? (int) $parent->versionid : null;
        } else if ($versionid) {
            $version = version_manager::get($versionid);
            if ((int) $version->itemid !== $itemid) {
                throw new \moodle_exception('invalidrecord', 'error', '', version_manager::TABLE);
            }
        }

        $now = time();
        $comment = (object) [
            'itemid' => $itemid,
            'versionid' => $versionid ?: null,
            'parentid' => $parentid ?: null,
            'userid' => $userid,
            'content' => $content,
            'contentformat' => $format,
            'deleted' => 0,
            'resolvedby' => null,
            'timeresolved' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        // Comment, subscription and preference together or not at all; messages only after the commit (bug110).
        $transaction = $DB->start_delegated_transaction();
        $comment->id = (int) $DB->insert_record(self::TABLE, $comment);
        comment_created::create_from_comment($comment, $item)->trigger();
        subscription_manager::subscribe_automatically($itemid, $userid, $courseid);
        set_user_preference(self::PREF_NOTICE, 1, $userid);
        $transaction->allow_commit();
        self::notify($item, $comment, $userid);
        return $comment;
    }

    /**
     * Change the text of a comment; the page then shows "edited" (timemodified after timecreated).
     *
     * @param int $commentid Comment id.
     * @param string $content New text.
     * @param int $format Text format.
     * @return \stdClass The comment.
     * @throws \moodle_exception If the comment was removed or the text is empty.
     */
    public static function update(int $commentid, string $content, int $format = FORMAT_MOODLE): \stdClass {
        global $DB;
        $comment = self::get($commentid);
        if ($comment->deleted) {
            throw new \moodle_exception('error_commentremoved', 'local_nextpool');
        }
        self::require_content($content);
        $comment->content = $content;
        $comment->contentformat = $format;
        // At least one second later, so "edited" shows even right after posting.
        $comment->timemodified = max(time(), (int) $comment->timecreated + 1);
        $DB->update_record(self::TABLE, $comment);
        return $comment;
    }

    /**
     * Remove a comment (q70).
     *
     * A comment with replies stays as a placeholder "Comment removed" without text; the replies stay. Otherwise the
     * row is deleted, and a placeholder left without replies goes with it.
     *
     * @param int $commentid Comment id.
     * @return bool True if a placeholder stays, false if the row was deleted.
     */
    public static function delete(int $commentid): bool {
        global $DB;
        $comment = self::get($commentid);
        $item = item_manager::get((int) $comment->itemid);
        $transaction = $DB->start_delegated_transaction();
        $placeholder = !$comment->parentid && $DB->record_exists(self::TABLE, ['parentid' => $comment->id]);
        if ($placeholder) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $comment->id,
                'content' => '',
                'deleted' => 1,
                'resolvedby' => null,
                'timeresolved' => 0,
            ]);
        } else {
            $DB->delete_records('local_nextpool_comment_link', ['commentid' => $comment->id]);
            $DB->delete_records(self::TABLE, ['id' => $comment->id]);
            if ($comment->parentid) {
                self::delete_empty_placeholder((int) $comment->parentid);
            }
        }
        comment_deleted::create_from_comment($comment, $item)->trigger();
        $transaction->allow_commit();
        return $placeholder;
    }

    /**
     * Mark a top-level comment as done or open it again (F08.AC03).
     *
     * @param int $commentid Comment id.
     * @param bool $resolved True for done, false for open.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The comment.
     * @throws \moodle_exception For replies and removed comments.
     */
    public static function set_resolved(int $commentid, bool $resolved, int $userid = 0): \stdClass {
        global $DB, $USER;
        $comment = self::get($commentid);
        if ($comment->deleted || $comment->parentid) {
            throw new \moodle_exception('error_commentnotresolvable', 'local_nextpool');
        }
        $comment->resolvedby = $resolved ? ($userid ?: (int) $USER->id) : null;
        $comment->timeresolved = $resolved ? time() : 0;
        $DB->update_record(self::TABLE, (object) [
            'id' => $comment->id,
            'resolvedby' => $comment->resolvedby,
            'timeresolved' => $comment->timeresolved,
        ]);
        return $comment;
    }

    /**
     * A comment.
     *
     * @param int $commentid Comment id.
     * @return \stdClass
     */
    public static function get(int $commentid): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $commentid], '*', MUST_EXIST);
    }

    /**
     * Comments of an item as threads: top-level comments, oldest first, each with its replies in ->replies.
     *
     * @param int $itemid Item id.
     * @param int|null $versionid Only threads about this version; 0 for the item in general; null for all.
     * @return \stdClass[] Top-level comments keyed by id.
     */
    public static function get_threads(int $itemid, ?int $versionid = null): array {
        global $DB;
        $threads = [];
        $replies = [];
        foreach ($DB->get_records(self::TABLE, ['itemid' => $itemid], 'timecreated, id') as $comment) {
            if ($comment->parentid) {
                $replies[(int) $comment->parentid][] = $comment;
                continue;
            }
            if ($versionid !== null && (int) $comment->versionid !== $versionid) {
                continue;
            }
            $comment->replies = [];
            $threads[(int) $comment->id] = $comment;
        }
        foreach ($replies as $parentid => $list) {
            if (isset($threads[$parentid])) {
                $threads[$parentid]->replies = $list;
            }
        }
        return $threads;
    }

    /**
     * Number of open comments per item, with one query for all items (task173).
     *
     * Open as in the rest of the feedback model (task81, review_manager, proposal_manager): a top-level comment that is
     * not marked as done and not removed. Replies and placeholders of removed comments do not count.
     *
     * @param int[] $itemids Item ids.
     * @return array<int,int> Item id => number of open comments; items without open comments are missing.
     */
    public static function count_open(array $itemids): array {
        global $DB;
        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (!$itemids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $counts = $DB->get_records_sql_menu(
            "SELECT itemid, COUNT(1)
               FROM {" . self::TABLE . "}
              WHERE itemid $insql
                    AND (parentid IS NULL OR parentid = 0)
                    AND deleted = 0
                    AND timeresolved = 0
           GROUP BY itemid",
            $params
        );
        return array_map('intval', $counts);
    }

    /**
     * Version a new comment is about by default: the one applied in the course, else the current one (F08).
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course the person comes from, 0 for the editorial view.
     * @return int|null Version id, null if the item has none.
     */
    public static function get_default_versionid(\stdClass $item, int $courseid = 0): ?int {
        global $DB;
        if ($courseid > 0) {
            $applied = $DB->get_field_sql(
                'SELECT MAX(appliedversionid)
                   FROM {' . placement_manager::TABLE . '}
                  WHERE itemid = :itemid AND courseid = :courseid',
                ['itemid' => $item->id, 'courseid' => $courseid]
            );
            if ($applied) {
                return (int) $applied;
            }
        }
        return !empty($item->currentversionid) ? (int) $item->currentversionid : null;
    }

    /**
     * Notify the subscribers of a new comment, except its author (F08.AC04).
     *
     * Only people who can still read the item's feedback are notified; the link leads to the course view when they
     * came from a course (TD-304).
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $comment The new comment.
     * @param int $authorid Author.
     */
    private static function notify(\stdClass $item, \stdClass $comment, int $authorid): void {
        global $DB;
        $subscribers = subscription_manager::get_subscribers((int) $item->id);
        unset($subscribers[$authorid]);
        if (!$subscribers) {
            return;
        }
        $author = \core_user::get_user($authorid);
        $context = access::get_context($item);
        $users = $DB->get_records_list('user', 'id', array_keys($subscribers));
        foreach ($subscribers as $userid => $subscription) {
            $user = $users[$userid] ?? null;
            if (!$user || $user->deleted || $user->suspended || !subscription_manager::can_receive($item, $subscription)) {
                continue;
            }
            $url = subscription_manager::get_item_url($item, $subscription);
            $url->set_anchor('local-nextpool-comment-' . $comment->id);
            notification_sender::send((int) $userid, static function () use ($item, $context, $comment, $author, $url) {
                $title = format_string($item->title, true, ['context' => $context, 'escape' => false]);
                $text = format_text($comment->content, (int) $comment->contentformat, ['context' => $context]);
                $a = (object) [
                    'title' => $title,
                    'name' => $author ? fullname($author) : '',
                    'text' => content_to_text($text, false),
                ];
                $message = new \core\message\message();
                $message->component = 'local_nextpool';
                $message->name = 'commentposted';
                $message->userfrom = \core_user::get_noreply_user();
                $message->subject = get_string('commentposted_subject', 'local_nextpool', $a);
                $message->fullmessage = get_string('commentposted_body', 'local_nextpool', $a) . "\n\n" . $url->out(false);
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = \html_writer::tag('p', s(get_string('commentposted_body', 'local_nextpool', $a)))
                    . \html_writer::tag('p', \html_writer::link($url, s(get_string('commentposted_link', 'local_nextpool'))));
                $message->smallmessage = get_string('commentposted_subject', 'local_nextpool', $a);
                $message->notification = 1;
                $message->contexturl = $url->out(false);
                $message->contexturlname = $title;
                return $message;
            });
        }
    }

    /**
     * Delete a removed comment once its last reply is gone.
     *
     * @param int $commentid Top-level comment id.
     */
    private static function delete_empty_placeholder(int $commentid): void {
        global $DB;
        if (
            $DB->record_exists(self::TABLE, ['id' => $commentid, 'deleted' => 1])
            && !$DB->record_exists(self::TABLE, ['parentid' => $commentid])
        ) {
            $DB->delete_records('local_nextpool_comment_link', ['commentid' => $commentid]);
            $DB->delete_records(self::TABLE, ['id' => $commentid]);
        }
    }

    /**
     * Throw if a text has nothing to show.
     *
     * @param string $content Text.
     * @throws \moodle_exception
     */
    private static function require_content(string $content): void {
        if (trim(html_to_text($content, 0, false)) === '') {
            throw new \moodle_exception('error_commentempty', 'local_nextpool');
        }
    }
}
