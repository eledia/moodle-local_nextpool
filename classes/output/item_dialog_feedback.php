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

namespace local_nextpool\output;

use local_nextpool\local\access;
use local_nextpool\local\comment_manager;

/**
 * Area "Feedback" of the item dialog in the catalogue browser (task173).
 *
 * The latest comments in short, newest first, a link to all comments on feedback.php and the place for the form
 * {@see \local_nextpool\form\item_dialog_feedback_form}. The caller checks {@see access::can_comment()}.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_dialog_feedback implements \renderable, \templatable {
    /** @var int How many comments the area shows. */
    public const LIMIT = 5;

    /**
     * Constructor.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course the person comes from, 0 for the editorial view.
     */
    public function __construct(
        /** @var \stdClass Item record. */
        private readonly \stdClass $item,
        /** @var int Course the person comes from. */
        private readonly int $courseid = 0,
    ) {
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        global $DB;
        $context = access::get_context($this->item);
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        // Top-level comments that are not removed, newest first; replies stay on feedback.php.
        $rows = $DB->get_records_sql(
            "SELECT c.id, c.content, c.contentformat, c.timecreated, c.timeresolved, c.userid, u.deleted AS userdeleted,
                    $userfields
               FROM {" . comment_manager::TABLE . "} c
          LEFT JOIN {user} u ON u.id = c.userid
              WHERE c.itemid = :itemid AND (c.parentid IS NULL OR c.parentid = 0) AND c.deleted = 0
           ORDER BY c.timecreated DESC, c.id DESC",
            ['itemid' => $this->item->id],
            0,
            self::LIMIT
        );
        $comments = [];
        foreach ($rows as $row) {
            $text = format_text((string) $row->content, (int) $row->contentformat, ['context' => $context]);
            $comments[] = [
                'id' => (int) $row->id,
                'author' => $row->userid && empty($row->userdeleted) ? fullname($row) : get_string('unknownuser'),
                'date' => userdate((int) $row->timecreated, get_string('strftimedatefullshort', 'core_langconfig')),
                'datefull' => userdate((int) $row->timecreated, get_string('strftimedatetime', 'core_langconfig')),
                'dateiso' => date('c', (int) $row->timecreated),
                'text' => shorten_text(content_to_text($text, false), 200),
                'done' => (bool) $row->timeresolved,
            ];
        }
        $url = new \moodle_url('/local/nextpool/feedback.php', ['itemid' => $this->item->id, 'courseid' => $this->courseid]);
        return [
            'itemid' => (int) $this->item->id,
            'courseid' => $this->courseid,
            'comments' => $comments,
            'hascomments' => !empty($comments),
            'allurl' => $url->out(false),
        ];
    }
}
