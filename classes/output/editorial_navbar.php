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

/**
 * Breadcrumb of the editorial pages (U-20).
 *
 * Every editorial page shows the same trail: Nextpool Catalogue › Catalogue items › item › page. The
 * pages do not use admin_externalpage_setup() because editors may hold their rights
 * in a course category only, so the trail does not start in the site administration.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class editorial_navbar {
    /**
     * Add the trail to the navbar of the page.
     *
     * The last node is the current page and has no link.
     *
     * @param \moodle_page $page Page.
     * @param \stdClass|null $item Item the page belongs to, null for the item list or a new item.
     * @param string|null $current Name of the current page below the list or the item; null if the
     *     page is the list or the item itself.
     */
    public static function add(\moodle_page $page, ?\stdClass $item = null, ?string $current = null): void {
        $listlast = $item === null && $current === null;
        // The catalogue area is the root, so the trail has a link even on the list (task64).
        $page->navbar->add(get_string('pluginname', 'local_nextpool'), new \moodle_url('/local/nextpool/index.php'));
        if ($item !== null && !access::can_use_editorial()) {
            // Item editors without editorial rights come from "My items" (F08, task84).
            $page->navbar->add(get_string('myitems', 'local_nextpool'), new \moodle_url('/local/nextpool/myitems.php'));
        } else {
            $page->navbar->add(
                get_string('items', 'local_nextpool'),
                $listlast ? null : new \moodle_url('/local/nextpool/items.php')
            );
        }
        if ($item !== null) {
            $page->navbar->add(
                format_string($item->title, true, ['context' => access::get_context($item)]),
                $current === null ? null : new \moodle_url('/local/nextpool/item.php', ['id' => $item->id])
            );
        }
        if ($current !== null) {
            $page->navbar->add($current);
        }
    }
}
