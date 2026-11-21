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

namespace local_nextpool\event;

/**
 * Event: a comment on a catalogue item was removed (F08, task81).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comment_deleted extends \core\event\base {
    #[\Override]
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_nextpool_comment';
    }

    /**
     * Create the event from a comment and its item.
     *
     * @param \stdClass $comment Row from local_nextpool_comment.
     * @param \stdClass $item Row from local_nextpool_item.
     * @return self
     */
    public static function create_from_comment(\stdClass $comment, \stdClass $item): self {
        return self::create([
            'objectid' => $comment->id,
            'contextid' => \local_nextpool\local\access::get_context($item)->id,
            'other' => [
                'itemid' => (int) $item->id,
                'versionid' => $comment->versionid !== null ? (int) $comment->versionid : null,
                'parentid' => $comment->parentid !== null ? (int) $comment->parentid : null,
            ],
        ]);
    }

    #[\Override]
    public static function get_name() {
        return get_string('event_comment_deleted', 'local_nextpool');
    }

    #[\Override]
    public function get_description() {
        return "The user with id '$this->userid' removed the comment with id '$this->objectid' "
            . "on the catalogue item with id '{$this->other['itemid']}'.";
    }

    #[\Override]
    public function get_url() {
        $url = new \moodle_url('/local/nextpool/feedback.php', ['itemid' => $this->other['itemid']]);
        $url->set_anchor('local-nextpool-comment-' . $this->objectid);
        return $url;
    }

    #[\Override]
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->objectid)) {
            throw new \coding_exception('The objectid must be set.');
        }
    }

    #[\Override]
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Other is restored unchanged: its ids point to site-wide catalogue data, not to course backups (Q-22).
     *
     * @return false
     */
    #[\Override]
    public static function get_other_mapping() {
        return false;
    }
}
