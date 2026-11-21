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
 * Item editors: people who may edit one catalogue item without editorial rights (F08, adr25, q68).
 *
 * Domain layer, checks no capabilities: the item page lets only the editorial team
 * ({@see access::can_manage()}) add and remove them. Any active account of the site can be added (q68).
 * Adding subscribes the person automatically (q66) and tells them with the message "editoradded".
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_editor_manager {
    /** @var string Table. */
    public const TABLE = 'local_nextpool_item_editor';

    /**
     * Add a person as item editor; adding someone twice changes nothing.
     *
     * @param int $itemid Item id.
     * @param int $userid Person to add.
     * @param int $addedby Person who adds, 0 for the current user.
     * @return bool True if the person was added now, false if already an item editor.
     * @throws \moodle_exception If the account does not exist, is deleted, suspended or the guest.
     */
    public static function add(int $itemid, int $userid, int $addedby = 0): bool {
        global $DB, $USER;
        $item = item_manager::get($itemid);
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended || isguestuser($user) || !\core_user::is_real_user($userid)) {
            throw new \moodle_exception('error_invaliditemeditor', 'local_nextpool');
        }
        if ($DB->record_exists(self::TABLE, ['itemid' => $itemid, 'userid' => $userid])) {
            return false;
        }
        $addedby = $addedby ?: (int) $USER->id;
        $DB->insert_record(self::TABLE, (object) [
            'itemid' => $itemid,
            'userid' => $userid,
            'addedby' => $addedby,
            'timecreated' => time(),
        ]);
        subscription_manager::subscribe_automatically($itemid, $userid);
        self::notify_added($item, $user, $addedby);
        return true;
    }

    /**
     * Remove a person from the item editors. The subscription stays; messages need access anyway.
     *
     * @param int $itemid Item id.
     * @param int $userid Person to remove.
     * @return bool True if the person was an item editor.
     */
    public static function remove(int $itemid, int $userid): bool {
        global $DB;
        if (!$DB->record_exists(self::TABLE, ['itemid' => $itemid, 'userid' => $userid])) {
            return false;
        }
        $DB->delete_records(self::TABLE, ['itemid' => $itemid, 'userid' => $userid]);
        return true;
    }

    /**
     * Item editors of an item with their name fields, sorted by name.
     *
     * @param int $itemid Item id.
     * @return \stdClass[] User records (id, name fields, addedby, timeadded), keyed by user id.
     */
    public static function get_editors(int $itemid): array {
        global $DB;
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $users = $DB->get_records_sql(
            "SELECT u.id, $fields, e.addedby, e.timecreated AS timeadded
               FROM {" . self::TABLE . "} e
               JOIN {user} u ON u.id = e.userid AND u.deleted = 0
              WHERE e.itemid = :itemid",
            ['itemid' => $itemid]
        );
        $names = array_map(static fn($user) => fullname($user), $users);
        \core_collator::asort($names);
        return array_replace($names, $users);
    }

    /**
     * Items a person is item editor of ("My items"), last changed first.
     *
     * @param int $userid User id.
     * @return \stdClass[] Item records keyed by id.
     */
    public static function get_items(int $userid): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT i.*
               FROM {" . item_manager::TABLE . "} i
               JOIN {" . self::TABLE . "} e ON e.itemid = i.id
              WHERE e.userid = :userid
           ORDER BY i.timemodified DESC, i.id DESC",
            ['userid' => $userid]
        );
    }

    /**
     * Whether a person is item editor of any item, for the user menu.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function has_items(int $userid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Tell a new item editor (message provider "editoradded").
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $user Added person.
     * @param int $addedby Person who added them.
     */
    private static function notify_added(\stdClass $item, \stdClass $user, int $addedby): void {
        $context = access::get_context($item);
        $title = format_string($item->title, true, ['context' => $context, 'escape' => false]);
        $from = $addedby ? \core_user::get_user($addedby) : null;
        $a = (object) ['title' => $title, 'user' => $from ? fullname($from) : get_string('unknownuser')];
        $url = new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]);

        $message = new \core\message\message();
        $message->component = 'local_nextpool';
        $message->name = 'editoradded';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('editoradded_subject', 'local_nextpool', $a);
        $message->fullmessage = get_string('editoradded_body', 'local_nextpool', $a) . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s(get_string('editoradded_body', 'local_nextpool', $a)))
            . \html_writer::tag('p', \html_writer::link($url, s(get_string('editoradded_link', 'local_nextpool'))));
        $message->smallmessage = get_string('editoradded_subject', 'local_nextpool', $a);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $title;
        message_send($message);
    }
}
