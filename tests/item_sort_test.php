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

namespace local_nextpool;

use local_nextpool\local\access;
use local_nextpool\local\category_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\status;
use local_nextpool\output\item_list;

/**
 * Sorting of the editorial item list (task168): order in the query, whitelist and the sort controls.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_list::class)]
final class item_sort_test extends \advanced_testcase {
    /** @var int[] Item ids by short name. */
    private array $ids = [];

    #[\Override]
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/items.php');
        $PAGE->set_context(\context_system::instance());

        // Category names go through the multilang filter, as the list shows them: "Zebra" in English, while the raw
        // name or its text without tags ("ApfelZebra") would sort first.
        filter_set_global_state('multilang', TEXTFILTER_ON);
        // Headings and names are filtered only by filters that apply to strings ("Content and headings").
        filter_set_applies_to_strings('multilang', true);
        \core\di::get(\core\formatting::class)->set_filterall(true);
        $zebra = category_manager::create(
            '<span lang="de" class="multilang">Apfel</span><span lang="en" class="multilang">Zebra</span>'
        );
        $mango = category_manager::create('Mango');
        $banana = category_manager::create('Banana', (int) $mango->id);

        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        // Short name => title, status, category, time modified. Ties: titles a2/a5, times d4/g3, statuses b1/a5.
        $rows = [
            'b1' => ['beta', status::DRAFT, $zebra->id, 3000],
            'a2' => ['Alpha', status::PUBLISHED, $mango->id, 1000],
            'g3' => ['gamma', status::REVIEW, null, 2000],
            'd4' => ['Delta', status::WITHDRAWN, $banana->id, 2000],
            'a5' => ['alpha', status::DRAFT, $mango->id, 4000],
        ];
        foreach ($rows as $name => [$title, $itemstatus, $categoryid, $time]) {
            $item = $generator->create_item(['title' => $title, 'categoryid' => $categoryid]);
            $DB->update_record(item_manager::TABLE, (object) ['id' => $item->id, 'status' => $itemstatus,
                'timemodified' => $time]);
            $this->ids[$name] = (int) $item->id;
        }
    }

    /**
     * Short names of the found items in their order.
     *
     * @param array $filters Filters for search().
     * @param string $sort Sort key.
     * @param string $dir Direction.
     * @param int $limitfrom First record.
     * @param int $limitnum Number of records.
     * @return string[]
     */
    private function names(array $filters, string $sort, string $dir, int $limitfrom = 0, int $limitnum = 0): array {
        $byid = array_flip($this->ids);
        $found = item_manager::search(access::get_editorial_contextids(), $filters, $limitfrom, $limitnum, $sort, $dir);
        return array_values(array_map(static fn($item) => $byid[(int) $item->id], $found));
    }

    /**
     * Each sortable column in both directions, equal values in the order of the item id.
     *
     * @return array
     */
    public static function sort_provider(): array {
        return [
            'title ascending, case-insensitive' => ['title', 'asc', ['a2', 'a5', 'b1', 'd4', 'g3']],
            'title descending' => ['title', 'desc', ['g3', 'd4', 'b1', 'a5', 'a2']],
            'status in workflow order' => ['status', 'asc', ['b1', 'a5', 'g3', 'a2', 'd4']],
            'status in reverse workflow order' => ['status', 'desc', ['d4', 'a2', 'g3', 'a5', 'b1']],
            'category by displayed name, none last' => ['category', 'asc', ['a2', 'a5', 'd4', 'b1', 'g3']],
            'category descending, none first' => ['category', 'desc', ['g3', 'b1', 'd4', 'a5', 'a2']],
            'last modified, newest first' => ['timemodified', 'desc', ['a5', 'b1', 'd4', 'g3', 'a2']],
            'last modified, oldest first' => ['timemodified', 'asc', ['a2', 'g3', 'd4', 'b1', 'a5']],
            'default: last modified, newest first' => ['', '', ['a5', 'b1', 'd4', 'g3', 'a2']],
            'unknown key falls back to the default' => ['id DESC; DROP', 'asc', ['a5', 'b1', 'd4', 'g3', 'a2']],
            'unknown direction: first direction of the column' => ['title', 'sideways', ['a2', 'a5', 'b1', 'd4', 'g3']],
        ];
    }

    /**
     * The query sorts by the requested column and direction.
     *
     * @param string $sort Sort key.
     * @param string $dir Direction.
     * @param string[] $expected Short names in order.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sort_provider')]
    public function test_search_sorts(string $sort, string $dir, array $expected): void {
        $this->assertSame($expected, $this->names([], $sort, $dir));
    }

    /**
     * The sorting applies across pages and together with a filter.
     */
    public function test_sort_with_paging_and_filter(): void {
        $this->assertSame(['b1', 'd4'], $this->names([], 'title', 'asc', 2, 2));
        $this->assertSame(['g3'], $this->names([], 'title', 'asc', 4, 2));
        $this->assertSame(['b1', 'a5'], $this->names(['status' => status::DRAFT], 'title', 'desc'));
        $this->assertSame(['d4', 'a5', 'a2'], $this->names(['search' => 'l'], 'category', 'desc'));
    }

    /**
     * Only whitelisted keys and directions pass.
     */
    public function test_normalise_sort(): void {
        $this->assertSame(['timemodified', 'desc'], item_manager::normalise_sort('', ''));
        $this->assertSame(['timemodified', 'desc'], item_manager::normalise_sort('i.title', 'asc'));
        $this->assertSame(['title', 'asc'], item_manager::normalise_sort('title', ''));
        $this->assertSame(['status', 'desc'], item_manager::normalise_sort('status', 'desc'));
        $this->assertSame(['timemodified', 'asc'], item_manager::normalise_sort('timemodified', 'asc'));
        $this->assertSame(['category', 'asc'], item_manager::normalise_sort('category', 'DESC'));
    }

    /**
     * The heading of the sorted column has aria-sort, an arrow and a link to the other direction; other columns
     * link to their first direction. The link is hidden below 768 px, the plain name stays.
     */
    public function test_sort_header(): void {
        $baseurl = new \moodle_url('/local/nextpool/items.php', ['status' => 'draft', 'page' => 2]);
        $sorted = item_list::sort_header('Title', 'title', 'title', 'asc', $baseurl);
        $this->assertTrue($sorted->header);
        $this->assertSame('ascending', $sorted->attributes['aria-sort']);
        $this->assertStringContainsString('sort=title&amp;dir=desc', $sorted->text);
        $this->assertStringContainsString('status=draft', $sorted->text);
        $this->assertStringNotContainsString('page=', $sorted->text);
        $this->assertStringContainsString('aria-label="' . s(get_string('sortbyxreverse', 'moodle', 'Title')) . '"', $sorted->text);
        $this->assertStringContainsString('d-none d-md-inline', $sorted->text);
        $this->assertStringContainsString('<span class="d-md-none">Title</span>', $sorted->text);
        $this->assertStringContainsString('title="' . get_string('asc') . '"', $sorted->text);

        $other = item_list::sort_header('Last modified', 'timemodified', 'title', 'asc', $baseurl);
        $this->assertArrayNotHasKey('aria-sort', $other->attributes);
        $this->assertStringContainsString('sort=timemodified&amp;dir=desc', $other->text);
        $this->assertStringNotContainsString('title="' . get_string('asc') . '"', $other->text);
        $this->assertStringNotContainsString('title="' . get_string('desc') . '"', $other->text);
    }

    /**
     * The menu for narrow screens names the current sorting and offers every column in both directions.
     */
    public function test_sort_menu(): void {
        $html = item_list::sort_menu('status', 'desc', new \moodle_url('/local/nextpool/items.php', ['search' => 'x']));
        $current = get_string('itemsort_status_desc', 'local_nextpool');
        $this->assertStringContainsString(s(get_string('itemsort_current', 'local_nextpool', $current)), $html);
        $this->assertSame(8, substr_count($html, 'class="dropdown-item'));
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $this->assertMatchesRegularExpression('~aria-current="true"[^>]*>.*?' . preg_quote(s($current), '~') . '~', $html);
        $this->assertStringContainsString('search=x&amp;sort=title&amp;dir=asc', $html);
        $this->assertStringContainsString('d-md-none', $html);
    }
}
