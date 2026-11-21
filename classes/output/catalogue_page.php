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

use local_nextpool\local\comment_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;

/**
 * Catalogue as seen from a course: published items with metadata and a place action (F05).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue_page implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param int $courseid Course id.
     * @param \stdClass[] $items Items from catalogue::get_items().
     * @param int[] $placedcounts Item id => number of placements in this course.
     * @param bool $canplace Whether the user may add activities to the course.
     * @param int|null $section Section number to preselect when placing, null for none.
     * @param bool $filtered Whether a filter is set, for the empty state.
     * @param \moodle_url|null $reseturl Catalogue without filter.
     */
    public function __construct(
        /** @var int Course id. */
        private readonly int $courseid,
        /** @var \stdClass[] Items. */
        private readonly array $items,
        /** @var array<int,int> Placements per item in this course. */
        private readonly array $placedcounts,
        /** @var bool Whether the user may place items. */
        private readonly bool $canplace,
        /** @var int|null Section to preselect when placing. */
        private readonly ?int $section = null,
        /** @var bool Whether a filter is set. */
        private readonly bool $filtered = false,
        /** @var \moodle_url|null Catalogue without filter. */
        private readonly ?\moodle_url $reseturl = null,
    ) {
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/licenselib.php');

        $authors = $this->load_author_names(array_keys($this->items));
        // Tags of all cards with one query (bug56).
        $tags = $this->items
            ? \core_tag_tag::get_items_tags('local_nextpool', item_manager::TAG_ITEMTYPE, array_keys($this->items))
            : [];

        // Open comments of all cards with one query (task173).
        $opencounts = comment_manager::count_open(array_keys($this->items));

        $categories = \local_nextpool\local\category_manager::get_menu();
        $cards = [];
        foreach ($this->items as $item) {
            $placeable = item_types::is_placeable($item->type);
            $placed = $this->placedcounts[(int) $item->id] ?? 0;
            $summary = item_summary::data($item, $output, $placed, $authors[(int) $item->id] ?? [], array_map(
                static fn(\core_tag_tag $tag) => $tag->get_display_name(),
                $tags[(int) $item->id] ?? []
            ), $categories);
            $title = $summary['title'];
            $params = ['itemid' => $item->id, 'courseid' => $this->courseid] +
                \local_nextpool\local\catalogue_return::read();
            if ($this->section !== null) {
                $params['section'] = $this->section;
            }
            $cards[] = $summary + [
                'previewurl' => (new \moodle_url('/local/nextpool/preview.php', $params))->out(false),
                'courseid' => $this->courseid,
                'contextid' => \context_course::instance($this->courseid)->id,
                'section' => $this->section,
                'plain_title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'canplace' => $this->canplace && $placeable,
                'placeaction' => $this->canplace && $placeable ? $this->render_place_action($item, strip_tags($title)) : '',
                // Feedback in the item dialog, with the course view of the item as the link (F08, task81, task173).
                'feedbackaction' => $this->render_feedback_action($item, strip_tags($title), $opencounts[(int) $item->id] ?? 0),
                'notplaceable' => !$placeable,
            ];
        }
        return [
            'items' => $cards,
            'hasitems' => !empty($cards),
            'filtered' => $this->filtered,
            'reseturl' => $this->reseturl?->out(false),
        ];
    }

    /**
     * Icon link that opens the placement dialog; its accessible name is "Place in course: <title>".
     *
     * On devices without hover the short label is shown beside the icon (CSS), because a tooltip cannot be reached there.
     *
     * @param \stdClass $item Item.
     * @param string $title Plain title.
     * @return string HTML
     */
    private function render_place_action(\stdClass $item, string $title): string {
        global $OUTPUT;
        $url = new \moodle_url('/local/nextpool/place.php', [
            'courseid' => $this->courseid,
            'itemid' => $item->id,
        ] + ($this->section !== null ? ['section' => $this->section] : []));
        $label = get_string('placeincourse', 'local_nextpool');
        $content = $OUTPUT->pix_icon('t/add', '') .
            \html_writer::span(
                get_string('placeshort', 'local_nextpool'),
                'local-nextpool-placelabel',
                ['aria-hidden' => 'true']
            ) .
            \html_writer::span(s(table_actions::name($label, $title)), 'visually-hidden');
        return \html_writer::link($url, $content, [
            'class' => 'btn btn-icon local-nextpool-iconaction local-nextpool-place',
            'title' => $label,
            'data-bs-toggle' => 'tooltip',
        ]);
    }

    /**
     * Icon link that opens the item dialog in the area "Feedback" (task173), with the number of open comments.
     *
     * The href leads to the course view of the item (feedback.php) without JavaScript, in a new tab and from the context
     * menu. The accessible name says the title and the open comments ("Feedback on <title>, 2 open"); the badge only
     * shows the number and is hidden from assistive technology. With no open comments the badge is hidden; the module
     * local_nextpool/item_dialog updates both after a new comment.
     *
     * @param \stdClass $item Item.
     * @param string $title Title without tags (entities are decoded here).
     * @param int $open Open comments.
     * @return string HTML
     */
    private function render_feedback_action(\stdClass $item, string $title, int $open): string {
        global $OUTPUT;
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = new \moodle_url(
            '/local/nextpool/feedback.php',
            ['itemid' => $item->id, 'courseid' => $this->courseid] + \local_nextpool\local\catalogue_return::read()
        );
        $name = $open > 0
            ? get_string('feedbackforopen', 'local_nextpool', (object) ['title' => $title, 'count' => $open])
            : get_string('feedbackfor', 'local_nextpool', $title);
        $badge = \html_writer::span($open, 'badge rounded-pill local-nextpool-feedbackcount', [
            'aria-hidden' => 'true',
            'data-region' => 'local-nextpool-feedbackcount',
        ] + ($open > 0 ? [] : ['hidden' => 'hidden']));
        $content = $OUTPUT->pix_icon('t/message', '') . $badge .
            \html_writer::span(s($name), 'visually-hidden', ['data-region' => 'local-nextpool-feedbackname']);
        return \html_writer::link($url, $content, [
            'class' => 'btn btn-icon local-nextpool-iconaction local-nextpool-feedbackaction',
            'title' => get_string('feedback', 'local_nextpool'),
            'data-bs-toggle' => 'tooltip',
            'data-local-nextpool-itemdialog' => 'feedback',
            'data-itemid' => (int) $item->id,
            'data-courseid' => $this->courseid,
            'data-contextid' => \context_course::instance($this->courseid)->id,
            'data-title' => $title,
        ]);
    }

    /**
     * Author display names per item.
     *
     * @param int[] $itemids Item ids.
     * @return array<int,string[]> Item id => names in display order.
     */
    private function load_author_names(array $itemids): array {
        global $DB;
        if (!$itemids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $rows = $DB->get_records_sql(
            "SELECT a.id, a.itemid, a.userid, a.name, $userfields
               FROM {" . item_manager::AUTHOR_TABLE . "} a
          LEFT JOIN {user} u ON u.id = a.userid
              WHERE a.itemid $insql
           ORDER BY a.itemid, a.sortorder, a.id",
            $params
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row->itemid][] = $row->userid ? fullname($row) : (string) $row->name;
        }
        return $names;
    }
}
