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
use local_nextpool\local\review_queue;

/**
 * Section navigation of the catalogue area (task64).
 *
 * The catalogue has two areas, each with its own row of tabs below the page heading:
 * the editorial area (items, reviews, categories, storage locations, custom fields) in the system
 * context and the course area (catalogue, content in this course). A tab is shown only to
 * users who may open its page; a row with fewer than two tabs is not shown at all.
 *
 * Sub-pages (an item, a form, a confirmation) keep their area's tab row with the parent
 * tab selected and still linked, so the way back is always one click.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class area_nav {
    /** @var string Tab of the item list and every item page. */
    public const ITEMS = 'items';
    /** @var string Tab of the catalogue categories. */
    public const CATEGORIES = 'categories';
    /** @var string Tab of the storage locations. */
    public const SOURCES = 'sources';
    /** @var string Tab of the item custom fields. */
    public const CUSTOMFIELDS = 'customfields';
    /** @var string Tab of the review overview (F07, task85). */
    public const REVIEWS = 'reviews';
    /** @var string Tab of the course catalogue. */
    public const CATALOGUE = 'catalogue';
    /** @var string Tab of the catalogue content placed in a course. */
    public const PLACEMENTS = 'placements';
    /** @var string Tab of the user's own proposals (F07, task82). */
    public const MYPROPOSALS = 'myproposals';
    /** @var string Tab of an item's page in the row of the item (F15, p110-e). */
    public const ITEM = 'item';
    /** @var string Tab of an item's statistics in the row of the item (F15, p110-e). */
    public const ITEM_STATISTICS = 'itemstatistics';

    /** @var array<string, string> Capability in the system context per system-level tab. */
    private const SYSTEM_CAPABILITIES = [
        self::CATEGORIES => 'local/nextpool:managecategories',
        self::SOURCES => 'local/nextpool:managesources',
        self::CUSTOMFIELDS => 'moodle/site:config',
    ];

    /** @var array<string, string> Page per editorial tab. */
    private const EDITORIAL_PAGES = [
        self::ITEMS => '/local/nextpool/items.php',
        self::REVIEWS => '/local/nextpool/reviews.php',
        self::CATEGORIES => '/local/nextpool/categories.php',
        self::SOURCES => '/local/nextpool/sources.php',
        self::CUSTOMFIELDS => '/local/nextpool/customfields.php',
    ];

    /**
     * Editorial tabs the current user may open, in display order.
     *
     * @return array<string, \moodle_url> Page URL per tab key.
     */
    public static function get_editorial_tabs(): array {
        $system = \context_system::instance();
        $tabs = [];
        foreach (self::EDITORIAL_PAGES as $key => $path) {
            $allowed = match ($key) {
                self::ITEMS => access::can_use_editorial(),
                self::REVIEWS => review_queue::can_view(),
                default => has_capability(self::SYSTEM_CAPABILITIES[$key], $system),
            };
            if ($allowed) {
                $tabs[$key] = new \moodle_url($path);
            }
        }
        return $tabs;
    }

    /**
     * Set up a system-level page of the editorial area.
     *
     * Call after require_login(). Checks the same capability in the system context as the former
     * admin_externalpage did.
     *
     * @param \moodle_page $page Page.
     * @param \moodle_url $url Page URL.
     * @param string $tab One of CATEGORIES, SOURCES, CUSTOMFIELDS.
     * @param string $title Page title.
     */
    public static function setup_system_page(\moodle_page $page, \moodle_url $url, string $tab, string $title): void {
        global $SITE;
        $system = \context_system::instance();
        require_capability(self::SYSTEM_CAPABILITIES[$tab], $system);
        $page->set_context($system);
        $page->set_url($url);
        $page->set_pagelayout('admin');
        $page->set_title($title);
        $page->set_heading(format_string($SITE->fullname));
        $page->navbar->add(get_string('pluginname', 'local_nextpool'), new \moodle_url('/local/nextpool/index.php'));
        $page->navbar->add($title);
    }

    /**
     * Tab row of the editorial area.
     *
     * @param string $current Tab key of the page.
     * @param bool $subpage True if the page sits below the tab's page; the tab then stays a link.
     * @return string HTML, empty if the user sees fewer than two tabs.
     */
    public static function editorial(string $current, bool $subpage = false): string {
        $tabs = [];
        foreach (self::get_editorial_tabs() as $key => $url) {
            // The review tab shows the number of reviews waiting for the user (F07 "Einstieg").
            $name = $key === self::REVIEWS
                ? get_string('reviewqueuecount', 'local_nextpool', review_queue::count_for_user())
                : get_string($key, 'local_nextpool');
            $tabs[] = new \tabobject($key, $url, $name, '', $subpage);
        }
        return self::render($tabs, $current);
    }

    /**
     * Tab row of the course area.
     *
     * @param int $courseid Course id.
     * @param string $current CATALOGUE, PLACEMENTS or MYPROPOSALS.
     * @param int $placementcount Number of catalogue items placed in the course, shown in the tab.
     * @param bool $subpage True if the page sits below the tab's page; the tab then stays a link.
     * @return string HTML.
     */
    public static function course(int $courseid, string $current, int $placementcount, bool $subpage = false): string {
        $tabs = [
            new \tabobject(
                self::CATALOGUE,
                new \moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid]),
                get_string('coursecatalogue', 'local_nextpool'),
                '',
                $subpage
            ),
            new \tabobject(
                self::PLACEMENTS,
                new \moodle_url('/local/nextpool/placements.php', ['courseid' => $courseid]),
                get_string('placementsincourse', 'local_nextpool', $placementcount),
                '',
                $subpage
            ),
        ];
        // Own proposals for everyone who may propose in the course (F07 "Meine Vorschläge").
        if (has_capability(access::PROPOSE, \context_course::instance($courseid))) {
            $tabs[] = new \tabobject(
                self::MYPROPOSALS,
                new \moodle_url('/local/nextpool/myproposals.php', ['courseid' => $courseid]),
                get_string('myproposals', 'local_nextpool'),
                '',
                $subpage
            );
        }
        return self::render($tabs, $current);
    }

    /**
     * Tab row of one item below the editorial tab row: "Item" and "Statistics" (F15, p110-e, q108).
     *
     * Both pages need the right to view the item (access::require_view(), q112), so the caller has checked it already.
     *
     * @param \stdClass $item Item record.
     * @param string $current ITEM or ITEM_STATISTICS.
     * @return string HTML.
     */
    public static function item(\stdClass $item, string $current): string {
        $tabs = [
            new \tabobject(
                self::ITEM,
                new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]),
                get_string('itemtab', 'local_nextpool')
            ),
            new \tabobject(
                self::ITEM_STATISTICS,
                new \moodle_url('/local/nextpool/statistics.php', ['id' => $item->id]),
                get_string('statistics', 'local_nextpool')
            ),
        ];
        return self::render($tabs, $current, get_string('itemnav', 'local_nextpool'));
    }

    /**
     * Page heading with an optional page action on the right.
     *
     * @param string $title Heading (HTML, already formatted by the caller).
     * @param string $actionhtml HTML of the single page action, e.g. a primary single_button.
     * @return string HTML.
     */
    public static function heading(string $title, string $actionhtml = ''): string {
        global $OUTPUT;
        $heading = $OUTPUT->heading($title, 2, 'mb-0');
        if ($actionhtml === '') {
            return \html_writer::div($heading, 'local-nextpool-pagehead mb-3');
        }
        return \html_writer::div(
            $heading . \html_writer::div($actionhtml, 'local-nextpool-pagehead__action'),
            'local-nextpool-pagehead d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3'
        );
    }

    /**
     * Render a tab row.
     *
     * @param \tabobject[] $tabs Tabs.
     * @param string $current Selected tab key.
     * @param string $label Accessible name of the row, default "Catalogue sections".
     * @return string HTML, empty for fewer than two tabs.
     */
    private static function render(array $tabs, string $current, string $label = ''): string {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        if (count($tabs) < 2) {
            return '';
        }
        return \html_writer::tag(
            'nav',
            $output->render_from_template(
                'local_nextpool/area_tabs',
                (new \tabtree($tabs, $current))->export_for_template($output)
            ),
            [
                'class' => 'local-nextpool-areanav mb-4',
                'aria-label' => $label !== '' ? $label : get_string('areanav', 'local_nextpool'),
            ]
        );
    }
}
