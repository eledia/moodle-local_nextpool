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
 * Subscriptions to notifications about a catalogue item (F08, task81).
 *
 * People who create an item, edit it, comment on it or propose a version are subscribed automatically (q66);
 * teachers who only placed it are not. Unsubscribing is remembered: commenting again does not subscribe the person
 * again (q76). The course a person last came from is kept, so notifications link to the course view of the item
 * (TD-304).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class subscription_manager {
    /** @var string Table. */
    public const TABLE = 'local_nextpool_subscription';

    /**
     * Subscribe a person on request; also undoes an earlier unsubscribe.
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @param int $courseid Course the person comes from, 0 to keep the stored one.
     * @return \stdClass The subscription.
     */
    public static function subscribe(int $itemid, int $userid, int $courseid = 0): \stdClass {
        return self::save($itemid, $userid, $courseid, ['auto' => 0, 'unsubscribed' => 0]);
    }

    /**
     * Unsubscribe a person; remembered so automatic subscriptions do not come back (q76).
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @return \stdClass The subscription row, marked as unsubscribed.
     */
    public static function unsubscribe(int $itemid, int $userid): \stdClass {
        return self::save($itemid, $userid, 0, ['auto' => 0, 'unsubscribed' => 1]);
    }

    /**
     * Subscribe a person because of what they did (created, edited, commented, proposed; q66).
     *
     * Leaves an earlier unsubscribe in place (q76); only the course is updated.
     *
     * @param int $itemid Item id.
     * @param int $userid User id; 0 (system) is ignored.
     * @param int $courseid Course the person comes from, 0 to keep the stored one.
     * @return \stdClass|null The subscription, null for user 0.
     */
    public static function subscribe_automatically(int $itemid, int $userid, int $courseid = 0): ?\stdClass {
        global $DB;
        if ($userid <= 0) {
            return null;
        }
        $existing = $DB->get_record(self::TABLE, ['itemid' => $itemid, 'userid' => $userid]);
        if ($existing) {
            return self::save($itemid, $userid, $courseid, []);
        }
        return self::save($itemid, $userid, $courseid, ['auto' => 1, 'unsubscribed' => 0]);
    }

    /**
     * Whether a person is subscribed (and has not unsubscribed).
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @return bool
     */
    public static function is_subscribed(int $itemid, int $userid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['itemid' => $itemid, 'userid' => $userid, 'unsubscribed' => 0]);
    }

    /**
     * Active subscriptions of an item.
     *
     * @param int $itemid Item id.
     * @return \stdClass[] Subscription rows keyed by user id.
     */
    public static function get_subscribers(int $itemid): array {
        global $DB;
        $subscribers = [];
        $records = $DB->get_records(self::TABLE, ['itemid' => $itemid, 'unsubscribed' => 0], 'id');
        foreach ($records as $record) {
            $subscribers[(int) $record->userid] = $record;
        }
        return $subscribers;
    }

    /**
     * Link for a subscriber: the course view of the item when they came from a course, else the item page.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $subscription Subscription row.
     * @return \moodle_url
     */
    public static function get_item_url(\stdClass $item, \stdClass $subscription): \moodle_url {
        $courseid = (int) ($subscription->courseid ?? 0);
        if ($courseid > 0 && access::can_comment($item, $courseid, (int) $subscription->userid)) {
            return new \moodle_url('/local/nextpool/feedback.php', ['itemid' => $item->id, 'courseid' => $courseid]);
        }
        return new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]);
    }

    /**
     * Whether a subscriber may still read the item's feedback, from their stored course or editorially.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $subscription Subscription row.
     * @return bool
     */
    public static function can_receive(\stdClass $item, \stdClass $subscription): bool {
        $userid = (int) $subscription->userid;
        $courseid = (int) ($subscription->courseid ?? 0);
        return ($courseid > 0 && access::can_comment($item, $courseid, $userid)) || access::can_comment($item, 0, $userid);
    }

    /**
     * Insert or update a subscription row.
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @param int $courseid Course, 0 to keep the stored one.
     * @param array $fields Fields to set (auto, unsubscribed).
     * @return \stdClass
     */
    private static function save(int $itemid, int $userid, int $courseid, array $fields): \stdClass {
        global $DB;
        $now = time();
        $record = $DB->get_record(self::TABLE, ['itemid' => $itemid, 'userid' => $userid]);
        if ($courseid > 0) {
            $fields['courseid'] = $courseid;
        }
        if ($record) {
            foreach ($fields as $name => $value) {
                $record->$name = $value;
            }
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
            return $record;
        }
        $record = (object) ($fields + [
            'itemid' => $itemid,
            'userid' => $userid,
            'courseid' => null,
            'auto' => 0,
            'unsubscribed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $record->id = $DB->insert_record(self::TABLE, $record);
        return $record;
    }
}
