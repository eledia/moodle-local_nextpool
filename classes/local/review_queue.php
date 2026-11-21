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
 * "Waiting for review": which versions wait, who reviews them, and who hears about them (F07, task85).
 *
 * A version waits for review while it is in review, or while it is a draft the storage location check imported
 * (q73, filter "New from the storage location"). Reviewers see the waiting versions of the items they may review
 * ({@see access::can_review()}, TD-310): items in contexts where they hold the review capability and, if they hold
 * it anywhere, items available site-wide (q77).
 *
 * Assignment is a hint for sharing the work (q72): every reviewer of the item may take a review, assign it to
 * another reviewer of the item or clear the assignment; others can still decide. Until a review is assigned, every
 * reviewer of the item hears about a submission; afterwards only the assigned person (F07.AC14).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class review_queue {
    /** @var string Cache of the number of waiting reviews per user (user menu, tab). */
    public const CACHE = 'reviewcount';

    /**
     * Contexts whose items the current user sees in the overview (TD-310, q77).
     *
     * @return int[] Context ids; empty if the user reviews nowhere.
     */
    public static function get_contextids(): array {
        $contextids = access::get_editorial_contextids([access::REVIEW]);
        if ($contextids) {
            // The review capability anywhere is enough for items available site-wide.
            $contextids[] = (int) \context_system::instance()->id;
        }
        return array_values(array_unique(array_map('intval', $contextids)));
    }

    /**
     * Whether the current user may open the overview.
     *
     * @return bool
     */
    public static function can_view(): bool {
        return (bool) access::get_editorial_contextids([access::REVIEW]);
    }

    /**
     * SQL condition for versions waiting for review.
     *
     * @param string $versionalias Alias of the version table.
     * @param string $prefix Prefix of the parameter names.
     * @return array [sql, params]
     */
    public static function get_waiting_sql(string $versionalias = 'v', string $prefix = 'rq'): array {
        $v = $versionalias;
        return [
            "({$v}.status = :{$prefix}review OR ({$v}.status = :{$prefix}draft AND {$v}.origin = :{$prefix}source))",
            [
                $prefix . 'review' => status::REVIEW,
                $prefix . 'draft' => status::DRAFT,
                $prefix . 'source' => version_manager::ORIGIN_SOURCE,
            ],
        ];
    }

    /**
     * SQL condition for items that wait for review themselves: submitted on the item level without a version that
     * waits for review (for example after the only version was rejected). An item with a waiting version is counted
     * through that version only.
     *
     * @param string $itemalias Alias of the item table.
     * @param string $prefix Prefix for the named parameters.
     * @return array SQL and parameters.
     */
    public static function get_waiting_items_sql(string $itemalias = 'i', string $prefix = 'rqi'): array {
        $i = $itemalias;
        [$waiting, $params] = self::get_waiting_sql('wv', $prefix);
        return [
            "({$i}.status = :{$prefix}itemreview AND NOT EXISTS (
                SELECT 1 FROM {" . version_manager::TABLE . "} wv WHERE wv.itemid = {$i}.id AND $waiting))",
            $params + [$prefix . 'itemreview' => status::REVIEW],
        ];
    }

    /**
     * Items the current user may review that wait for review without a waiting version, oldest change first.
     *
     * @return \stdClass[] Item records.
     */
    public static function get_waiting_items(): array {
        global $DB;
        $contextids = self::get_contextids();
        if (!$contextids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'rqictx');
        [$waiting, $waitingparams] = self::get_waiting_items_sql('i');
        return $DB->get_records_sql(
            "SELECT i.*
               FROM {" . item_manager::TABLE . "} i
              WHERE i.contextid $insql AND $waiting
           ORDER BY i.timemodified ASC, i.id ASC",
            $params + $waitingparams
        );
    }

    /**
     * Whether a version waits for review.
     *
     * @param \stdClass $version Version record.
     * @return bool
     */
    public static function is_waiting(\stdClass $version): bool {
        return $version->status === status::REVIEW
            || ($version->status === status::DRAFT && $version->origin === version_manager::ORIGIN_SOURCE);
    }

    /**
     * Number of waiting reviews assigned to the current user or to nobody (F07 "Einstieg").
     *
     * Cached per user; {@see invalidate()} clears it when a version changes status or assignment.
     *
     * @return int
     */
    public static function count_for_user(): int {
        global $DB, $USER;
        $cache = \cache::make('local_nextpool', self::CACHE);
        $cached = $cache->get((int) $USER->id);
        if ($cached !== false) {
            return (int) $cached;
        }
        $count = 0;
        $contextids = self::get_contextids();
        if ($contextids) {
            [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'rqctx');
            [$waiting, $waitingparams] = self::get_waiting_sql('v');
            $count = (int) $DB->count_records_sql(
                "SELECT COUNT(v.id)
                   FROM {" . version_manager::TABLE . "} v
                   JOIN {" . item_manager::TABLE . "} i ON i.id = v.itemid
                  WHERE i.contextid $insql AND $waiting AND (v.assignedto IS NULL OR v.assignedto = :rquser)",
                $params + $waitingparams + ['rquser' => (int) $USER->id]
            );
            $count += count(self::get_waiting_items());
        }
        $cache->set((int) $USER->id, $count);
        return $count;
    }

    /**
     * Forget the cached numbers of all users.
     */
    public static function invalidate(): void {
        \cache::make('local_nextpool', self::CACHE)->purge();
    }

    /**
     * Take a review or assign it to another reviewer of the item, or clear the assignment (q72, F07.AC13).
     *
     * The acting user and the assigned person must be allowed to review the item ({@see access::can_review()}).
     * The assignment is recorded in the review history; the assigned person gets "reviewassigned" unless they
     * took the review themselves.
     *
     * @param int $versionid Version id.
     * @param int $assigneeid Assigned person, 0 to clear the assignment.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The stored version.
     * @throws \moodle_exception If the version does not wait for review or a person may not review the item.
     */
    public static function assign(int $versionid, int $assigneeid, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $version = version_manager::get($versionid);
        $item = item_manager::get((int) $version->itemid);
        if (!self::is_waiting($version)) {
            throw new \moodle_exception('error_reviewnotwaiting', 'local_nextpool');
        }
        if (!access::can_review($item, $userid)) {
            throw new \required_capability_exception(access::get_context($item), access::REVIEW, 'nopermissions', '');
        }
        if ($assigneeid && !access::can_review($item, $assigneeid)) {
            throw new \moodle_exception('error_reviewassignee', 'local_nextpool');
        }
        if ((int) $version->assignedto === $assigneeid) {
            return $version;
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->set_field(version_manager::TABLE, 'assignedto', $assigneeid ?: null, ['id' => $versionid]);
        version_manager::add_log($versionid, version_manager::LOG_ASSIGNED, $userid, null, FORMAT_PLAIN, $assigneeid ?: null);
        $transaction->allow_commit();
        self::invalidate();

        $version = version_manager::get($versionid);
        if ($assigneeid && $assigneeid !== $userid) {
            $actor = \core_user::get_user($userid);
            self::send($item, $version, 'reviewassigned', $assigneeid, [
                'user' => $actor ? fullname($actor) : '-',
            ]);
        }
        return $version;
    }

    /**
     * People to notify about a submitted version (q72, F07.AC14).
     *
     * The assigned person if the review is assigned and may still review the item, otherwise every reviewer of the
     * item ({@see access::get_reviewers()}); never the submitting person.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version record.
     * @param int $userid Submitting user.
     * @return int[] User ids.
     */
    public static function get_recipients(\stdClass $item, \stdClass $version, int $userid): array {
        $assignee = (int) ($version->assignedto ?? 0);
        if ($assignee && access::can_review($item, $assignee)) {
            $recipients = [$assignee];
        } else {
            $recipients = array_map('intval', array_keys(access::get_reviewers($item)));
        }
        return array_values(array_filter($recipients, static fn(int $id): bool => $id !== $userid));
    }

    /**
     * Tell the reviewers about a version submitted for review (F07.AC14).
     *
     * For proposals, subscribers who see the item editorially already get "proposalsubmitted" (t82-e); they get no
     * second message.
     *
     * @param int $versionid Version id.
     * @param int $userid Submitting user.
     */
    public static function notify_submitted(int $versionid, int $userid): void {
        $version = version_manager::get($versionid);
        if ($version->status !== status::REVIEW) {
            return;
        }
        $item = item_manager::get((int) $version->itemid);
        $skip = [];
        if ($version->origin === version_manager::ORIGIN_PROPOSAL) {
            foreach (subscription_manager::get_subscribers((int) $item->id) as $subscriber => $subscription) {
                if (access::can_comment($item, 0, (int) $subscriber)) {
                    $skip[(int) $subscriber] = true;
                }
            }
        }
        $submitter = \core_user::get_user($userid);
        foreach (self::get_recipients($item, $version, $userid) as $recipient) {
            if (isset($skip[$recipient])) {
                continue;
            }
            self::send($item, $version, 'reviewsubmitted', $recipient, [
                'user' => $submitter ? fullname($submitter) : '-',
            ]);
        }
    }

    /**
     * Send one notification about a review.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version record.
     * @param string $name Message provider, also the prefix of the subject and body strings.
     * @param int $userid Recipient.
     * @param array $a Extra placeholders.
     */
    private static function send(\stdClass $item, \stdClass $version, string $name, int $userid, array $a): void {
        notification_sender::send($userid, static function () use ($item, $version, $name, $a) {
            $url = new \moodle_url('/local/nextpool/review.php', ['versionid' => $version->id]);
            $a = (object) ($a + [
                'item' => format_string($item->title, true, ['context' => access::get_context($item), 'escape' => false]),
                'version' => review_manager::get_version_name($version),
                'url' => $url->out(false),
                'overview' => (new \moodle_url('/local/nextpool/reviews.php'))->out(false),
            ]);
            $message = new \core\message\message();
            $message->component = 'local_nextpool';
            $message->name = $name;
            $message->userfrom = \core_user::get_noreply_user();
            $message->subject = get_string($name . '_subject', 'local_nextpool', $a);
            $message->fullmessage = get_string($name . '_body', 'local_nextpool', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $a->url;
            $message->contexturlname = $message->subject;
            $message->courseid = SITEID;
            return $message;
        });
    }
}
