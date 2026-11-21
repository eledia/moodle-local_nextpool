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

use local_nextpool\local\item_manager;

/**
 * Compact cells of the editorial item list (`items.php`, task166): pills for the origin course and the category,
 * and the sort controls (task168).
 *
 * Pills are read-only tags; the origin pill is a link to the course when the course is there and accessible.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_list {
    /** @var string Classes of a neutral pill (contrast like the badge "Unplaced"). */
    private const PILL = 'badge rounded-pill bg-light text-dark border text-wrap text-start';

    /**
     * Origin course of an item as a short pill (M06.AC04): the course name in tooltip and accessible name.
     *
     * @param \stdClass $item Item record with origincourseid.
     * @param \stdClass[] $courses Origin courses by id, loaded once for the page.
     * @return string HTML, '' for items not uploaded from a course.
     */
    public static function origin_pill(\stdClass $item, array $courses): string {
        global $OUTPUT;
        if (empty($item->origincourseid)) {
            return '';
        }
        $course = $courses[(int) $item->origincourseid] ?? null;
        $context = $course ? \context_course::instance((int) $course->id, IGNORE_MISSING) : null;
        $coursename = $course && $context
            ? format_string($course->fullname, true, ['context' => $context, 'escape' => false])
            : get_string('origincourse_deleted', 'local_nextpool');
        $full = get_string('origincourse_named', 'local_nextpool', $coursename);
        $content = $OUTPUT->pix_icon('i/course', '') .
            \html_writer::span(s(get_string('origincourse_short', 'local_nextpool')), '', ['aria-hidden' => 'true']);
        $classes = self::PILL . ' local-nextpool-originpill';
        if ($course && $context && can_access_course($course, null, '', true)) {
            return \html_writer::link(new \moodle_url('/course/view.php', ['id' => $course->id]), $content, [
                'class' => $classes,
                'aria-label' => $full,
                'title' => $full,
                'data-bs-toggle' => 'tooltip',
            ]);
        }
        // Deleted or not accessible: the same pill without a link (TD-409).
        return \html_writer::span($content . \html_writer::span(s($full), 'visually-hidden'), $classes, [
            'title' => $full,
            'data-bs-toggle' => 'tooltip',
        ]);
    }

    /**
     * The catalogue category of an item as pill; an item has exactly one category.
     *
     * @param string $name Category path, already formatted (category_manager::get_menu()).
     * @return string HTML
     */
    public static function category_pill(string $name): string {
        global $OUTPUT;
        return \html_writer::span($OUTPUT->pix_icon('i/folder', '') . $name, self::PILL . ' local-nextpool-categorypill');
    }

    /**
     * Heading cell of a sortable column (task168), following core flexible_table: the heading is a link that sorts
     * by the column, the sorted column has an arrow and aria-sort.
     *
     * The link is shown from 768 px only. Below, the heading row is visually hidden for the stacked rows and a
     * focusable link in it would get an invisible focus (WCAG 2.4.7); the plain heading text stays for screen
     * readers and sort_menu() offers the sorting instead.
     *
     * @param string $label Column name, plain text.
     * @param string $key Sort key of the column, see item_manager::SORT_FIELDS.
     * @param string $sort Current sort key, already checked.
     * @param string $dir Current direction, already checked.
     * @param \moodle_url $baseurl List URL with the filters.
     * @return \html_table_cell
     */
    public static function sort_header(
        string $label,
        string $key,
        string $sort,
        string $dir,
        \moodle_url $baseurl
    ): \html_table_cell {
        global $OUTPUT;
        $sorted = $key === $sort;
        // The sorted column changes direction, any other starts with its own first direction.
        $target = $sorted ? ($dir === item_manager::SORT_ASC ? item_manager::SORT_DESC : item_manager::SORT_ASC)
            : item_manager::SORT_FIELDS[$key];
        $icon = '';
        if ($sorted) {
            $icon = ' ' . ($dir === item_manager::SORT_ASC
                ? $OUTPUT->pix_icon('t/sort_asc', '', 'moodle', ['title' => get_string('asc')])
                : $OUTPUT->pix_icon('t/sort_desc', '', 'moodle', ['title' => get_string('desc')]));
        }
        $text = s($label);
        $cell = new \html_table_cell(
            \html_writer::link(self::sort_url($baseurl, $key, $target), $text . $icon, [
                'class' => 'local-nextpool-sortlink d-none d-md-inline',
                'aria-label' => get_string($target === item_manager::SORT_ASC ? 'sortbyx' : 'sortbyxreverse', 'moodle', $label),
            ]) .
            \html_writer::span($text, 'd-md-none')
        );
        $cell->header = true;
        if ($sorted) {
            $cell->attributes['aria-sort'] = $dir === item_manager::SORT_ASC ? 'ascending' : 'descending';
        }
        return $cell;
    }

    /**
     * Sort menu below 768 px, where the heading row is hidden (task168): a dropdown "Sort by" with one choice per
     * column and direction, the current one marked (MDS Dropdown with select items).
     *
     * @param string $sort Current sort key, already checked.
     * @param string $dir Current direction, already checked.
     * @param \moodle_url $baseurl List URL with the filters.
     * @return string HTML
     */
    public static function sort_menu(string $sort, string $dir, \moodle_url $baseurl): string {
        global $OUTPUT;
        $current = get_string('itemsort_' . $sort . '_' . $dir, 'local_nextpool');
        $items = '';
        foreach (array_keys(item_manager::SORT_FIELDS) as $key) {
            // The first direction of the column first, like a click on its heading.
            $first = item_manager::SORT_FIELDS[$key];
            $second = $first === item_manager::SORT_ASC ? item_manager::SORT_DESC : item_manager::SORT_ASC;
            foreach ([$first, $second] as $direction) {
                $selected = $key === $sort && $direction === $dir;
                $attributes = ['class' => 'dropdown-item d-flex align-items-center'];
                if ($selected) {
                    $attributes['aria-current'] = 'true';
                }
                $items .= \html_writer::tag('li', \html_writer::link(
                    self::sort_url($baseurl, $key, $direction),
                    \html_writer::span($selected ? $OUTPUT->pix_icon('i/checked', '') : '', 'local-nextpool-sortmenu__check') .
                        s(get_string('itemsort_' . $key . '_' . $direction, 'local_nextpool')),
                    $attributes
                ));
            }
        }
        return \html_writer::div(
            \html_writer::tag('button', s(get_string('itemsort_current', 'local_nextpool', $current)), [
                'type' => 'button',
                'id' => 'local_nextpool_sortmenu',
                'class' => 'btn btn-secondary dropdown-toggle mw-100 text-wrap text-start',
                'data-bs-toggle' => 'dropdown',
                'aria-expanded' => 'false',
            ]) .
            \html_writer::tag('ul', $items, ['class' => 'dropdown-menu', 'aria-labelledby' => 'local_nextpool_sortmenu']),
            'dropdown local-nextpool-sortmenu d-md-none mb-3'
        );
    }

    /**
     * List URL sorted by a column; paging starts again on the first page.
     *
     * @param \moodle_url $baseurl List URL with the filters.
     * @param string $key Sort key.
     * @param string $dir Direction.
     * @return \moodle_url
     */
    public static function sort_url(\moodle_url $baseurl, string $key, string $dir): \moodle_url {
        $url = new \moodle_url($baseurl);
        $url->remove_params('page');
        $url->params(['sort' => $key, 'dir' => $dir]);
        return $url;
    }
}
