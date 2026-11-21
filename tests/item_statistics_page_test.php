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

use local_nextpool\local\item_statistics;
use local_nextpool\output\area_nav;
use local_nextpool\output\item_statistics_page;

/**
 * Tests for the tab "Statistics" of a catalogue item (F15, task127).
 *
 * The figures come as item_statistics::get() returns them; the class only formats them.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_statistics_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(area_nav::class)]
final class item_statistics_page_test extends \advanced_testcase {
    /** @var \stdClass Item record. */
    private \stdClass $item;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->item = $this->getDataGenerator()->get_plugin_generator('local_nextpool')
            ->create_item(['title' => 'Pump basics']);
    }

    /**
     * A figure set as item_statistics::get() returns it.
     *
     * @param array $values Values that differ from the defaults (null: suppressed).
     * @param array $flags Flags per suppressed value; suppressed values without a flag are small.
     * @return \stdClass
     */
    private static function figures(array $values = [], array $flags = []): \stdClass {
        $set = (object) ($values + [
            'started' => 10,
            'completed' => 8,
            'passed' => 6,
            'completionrate' => 80.0,
            'passrate' => 75.0,
            'scoremedian' => 82.5,
            'scorelow' => 70.0,
            'scorehigh' => 90.0,
            'durationmedian' => 840,
            'attemptsmedian' => 1,
        ]);
        $set->flags = [];
        foreach (item_statistics::VALUES as $name) {
            if ($set->$name === null) {
                $set->flags[$name] = $flags[$name] ?? item_statistics::FLAG_SMALL;
            }
        }
        return $set;
    }

    /**
     * Statistics as item_statistics::get() returns them.
     *
     * @param array $fields Fields that differ from a calculated xAPI item in two courses.
     * @return \stdClass
     */
    private function statistics(array $fields = []): \stdClass {
        return (object) ($fields + [
            'itemid' => (int) $this->item->id,
            'supported' => true,
            'courses' => 2,
            'activities' => 3,
            'expectedduration' => null,
            'timecalculated' => 1763168400,
            'coursecount' => 2,
            'total' => self::figures(),
            'versions' => [],
        ]);
    }

    /**
     * Export the page.
     *
     * @param \stdClass $statistics Statistics.
     * @return array Template context.
     */
    private function export(\stdClass $statistics): array {
        global $PAGE;
        return (new item_statistics_page($this->item, $statistics))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Cell values of a row by column name, as plain text.
     *
     * @param array $row Row of the template context.
     * @return array<string,string>
     */
    private static function cells(array $row): array {
        $text = array_map(static fn($cell) => trim(html_to_text($cell['value'], 0, false)), $row['cells']);
        return array_combine(item_statistics_page::COLUMNS, $text);
    }

    /**
     * The key figures of AC01: placements, counts and rates, the data date, and no course name (F15.AC01, test136).
     */
    public function test_key_figures(): void {
        $version = (object) ['versionnr' => 1, 'versionid' => 5, 'label' => '', 'withdrawn' => false, 'used' => true,
            'figures' => self::figures()];
        $data = $this->export($this->statistics(['versions' => [$version]]));

        $this->assertSame('Pump basics', $data['title']);
        $this->assertStringStartsWith('As of ', $data['asof']);
        $this->assertSame('', $data['notice']);
        $this->assertSame(
            ['Courses' => '2', 'Activities' => '3', 'Courses with learning data' => '2'],
            array_column($data['placements'], 'value', 'label')
        );
        $this->assertTrue($data['hasrows']);
        $this->assertCount(2, $data['rows']);
        $this->assertSame('Total', $data['rows'][0]['label']);
        $this->assertSame('Version 1', $data['rows'][1]['label']);
        $cells = self::cells($data['rows'][0]);
        $this->assertSame('10', $cells['started']);
        $this->assertSame('8', $cells['completed']);
        $this->assertSame('6', $cells['passed']);
        $this->assertSame('80%', $cells['completionrate']);
        $this->assertSame('75%', $cells['passrate']);
        $this->assertSame('82.5%', $cells['scoremedian']);
        $this->assertSame('70% to 90%', $cells['scorerange']);
        $this->assertSame('1', $cells['attemptsmedian']);
        $this->assertSame(count(item_statistics_page::COLUMNS), $data['columncount']);
        $this->assertSame(['Completion rate = completed ÷ started. Pass rate = passed ÷ learners with a result. ' .
            'Middle half of scores: from the 25th to the 75th percentile.'], $data['legend']);
        $this->assertNotSame('', $data['deletionhint']);
    }

    /**
     * Learning time as h:mm with the time in words for screen readers, compared with the expected time in the total.
     */
    public function test_duration_with_expected_time(): void {
        $version = (object) ['versionnr' => 1, 'versionid' => 5, 'label' => '', 'withdrawn' => false, 'used' => true,
            'figures' => self::figures(['durationmedian' => 4500])];
        $data = $this->export($this->statistics(['expectedduration' => 900, 'versions' => [$version]]));

        $total = $data['rows'][0]['cells'][7]['value'];
        $this->assertStringContainsString('<span aria-hidden="true">0:14</span>', $total);
        $this->assertStringContainsString('<span class="visually-hidden">14 mins</span>', $total);
        $this->assertStringContainsString('(expected <span aria-hidden="true">0:15</span>', $total);
        $versioncell = $data['rows'][1]['cells'][7]['value'];
        $this->assertStringContainsString('<span aria-hidden="true">1:15</span>', $versioncell);
        $this->assertStringNotContainsString('expected', $versioncell);
        $this->assertSame('Expected learning time', end($data['placements'])['label']);

        // Under a minute does not read as nothing.
        $this->assertStringContainsString('>0:01<', item_statistics_page::duration(20));
    }

    /**
     * Numbers follow the language: decimal separator of the language pack and the percent sign as the language writes it.
     */
    public function test_number_format_follows_language(): void {
        $this->assertSame('82.5%', item_statistics_page::percent(82.5));
        $this->assertSame('80%', item_statistics_page::percent(80.0));
        $this->assertSame('77.8%', item_statistics_page::percent(77.777));
        $this->assertSame('1234', item_statistics_page::number(1234.4));
    }

    /**
     * Suppressed values are text, not numbers: small groups, derived values and values nobody has (F15.AC02, US-F15-3).
     */
    public function test_flags_as_text(): void {
        $derived = array_fill_keys(item_statistics::VALUES, null);
        $total = self::figures($derived, array_fill_keys(item_statistics::VALUES, item_statistics::FLAG_DERIVED));
        $v1 = self::figures(
            ['passrate' => null, 'scoremedian' => null, 'scorelow' => null, 'scorehigh' => null, 'durationmedian' => null,
                'attemptsmedian' => null],
            ['passrate' => item_statistics::FLAG_NONE, 'scoremedian' => item_statistics::FLAG_NONE,
                'scorelow' => item_statistics::FLAG_NONE, 'scorehigh' => item_statistics::FLAG_NONE,
                'durationmedian' => item_statistics::FLAG_DERIVED]
        );
        $v2 = self::figures($derived);
        $data = $this->export($this->statistics([
            'coursecount' => null,
            'total' => $total,
            'versions' => [
                (object) ['versionnr' => 1, 'versionid' => 5, 'label' => '', 'withdrawn' => false, 'used' => true,
                    'figures' => $v1],
                (object) ['versionnr' => 2, 'versionid' => 6, 'label' => '', 'withdrawn' => false, 'used' => true,
                    'figures' => $v2],
            ],
        ]));

        $this->assertSame('Not shown so that small groups cannot be worked out', $data['rows'][0]['message']);
        $this->assertSame([], $data['rows'][0]['cells']);
        $cells = self::cells($data['rows'][1]);
        $this->assertSame('10', $cells['started']);
        $this->assertSame('No result reported', $cells['passrate']);
        $this->assertSame('No score reported', $cells['scoremedian']);
        $this->assertSame('No score reported', $cells['scorerange']);
        $this->assertSame('Hidden for privacy', $cells['durationmedian']);
        $this->assertSame('Fewer than 5 learners', $cells['attemptsmedian']);
        $this->assertSame('Fewer than 5 learners, no statistics yet', $data['rows'][2]['message']);
        $this->assertNotContains('Courses with learning data', array_column($data['placements'], 'label'));
        // One note per kind of flag on the page, then how the rates are calculated.
        $this->assertCount(4, $data['legend']);
        $this->assertStringStartsWith('Fewer than 5 learners:', $data['legend'][0]);
        $this->assertStringStartsWith('Hidden for privacy:', $data['legend'][1]);
        $this->assertStringStartsWith('No result or score reported:', $data['legend'][2]);
    }

    /**
     * A version nobody learned with yet and a withdrawn version stay in the table, marked as such.
     */
    public function test_unused_and_withdrawn_versions(): void {
        $data = $this->export($this->statistics(['versions' => [
            (object) ['versionnr' => 1, 'versionid' => 5, 'label' => '', 'withdrawn' => true, 'used' => true,
                'figures' => self::figures()],
            (object) ['versionnr' => 2, 'versionid' => 6, 'label' => '', 'withdrawn' => false, 'used' => false,
                'figures' => null],
        ]]));

        $this->assertTrue($data['rows'][1]['withdrawn']);
        $this->assertSame('Withdrawn', $data['rows'][1]['withdrawnlabel']);
        $this->assertCount(count(item_statistics_page::COLUMNS), $data['rows'][1]['cells']);
        $this->assertFalse($data['rows'][2]['withdrawn']);
        $this->assertSame('Not used yet', $data['rows'][2]['message']);
    }

    /**
     * A type without learning data shows the placements and a notice, no figures (F15.AC06, q111).
     */
    public function test_type_without_learning_data(): void {
        $data = $this->export($this->statistics([
            'supported' => false,
            'courses' => 1,
            'activities' => 1,
            'timecalculated' => null,
            'coursecount' => null,
            'total' => null,
            'expectedduration' => 900,
        ]));

        $this->assertSame(
            'Learning data is not recorded for this type of content. The statistics show the placements only.',
            $data['notice']
        );
        $this->assertSame(['Courses', 'Activities'], array_column($data['placements'], 'label'));
        $this->assertFalse($data['hasrows']);
        $this->assertSame([], $data['legend']);
        $this->assertSame('', $data['asof']);
        $this->assertSame('', $data['deletionhint']);
    }

    /**
     * Before the first calculation the page says when figures come, with or without placements (p110-b).
     */
    public function test_not_calculated(): void {
        $data = $this->export($this->statistics(['timecalculated' => null, 'coursecount' => null, 'total' => null]));
        $this->assertSame('No statistics yet. They are calculated overnight.', $data['notice']);
        $this->assertFalse($data['hasrows']);

        $data = $this->export($this->statistics(['courses' => 0, 'activities' => 0, 'timecalculated' => null,
            'coursecount' => null, 'total' => null]));
        $this->assertStringStartsWith('This item is not placed in any course yet.', $data['notice']);
    }

    /**
     * The real data source renders: a stored row of the scheduled task appears in the template.
     */
    public function test_renders_stored_statistics(): void {
        global $DB, $PAGE;
        $DB->insert_record(item_statistics::TABLE, (object) [
            'itemid' => $this->item->id, 'versionnr' => 0, 'started' => 10, 'completed' => 8, 'passed' => 6,
            'completionrate' => 80, 'passrate' => 75, 'scoremedian' => 82.5, 'scorelow' => 70, 'scorehigh' => 90,
            'durationmedian' => 840, 'attemptsmedian' => 1, 'coursecount' => null, 'timecalculated' => 1000,
        ]);
        $output = $PAGE->get_renderer('core');
        $page = new item_statistics_page($this->item, item_statistics::get((int) $this->item->id));
        $html = $output->render_from_template('local_nextpool/item_statistics_page', $page->export_for_template($output));

        // Type file has no learning data: the stored row is not shown (q111).
        $this->assertStringContainsString('Learning data is not recorded for this type of content.', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /**
     * The template has a caption, column and row headings, and labels for the stacked view (F15.AC08).
     */
    public function test_template_markup(): void {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $page = new item_statistics_page($this->item, $this->statistics(['versions' => [
            (object) ['versionnr' => 1, 'versionid' => 5, 'label' => '', 'withdrawn' => false, 'used' => false,
                'figures' => null],
        ]]));
        $html = $output->render_from_template('local_nextpool/item_statistics_page', $page->export_for_template($output));

        $this->assertStringContainsString('<caption>Key figures in total and per version</caption>', $html);
        $this->assertSame(1 + count(item_statistics_page::COLUMNS), substr_count($html, '<th scope="col">'));
        $this->assertSame(2, substr_count($html, '<th scope="row">'));
        $this->assertStringContainsString('<td data-label="Started">10</td>', $html);
        $this->assertStringContainsString('colspan="' . count(item_statistics_page::COLUMNS) . '"', $html);
    }

    /**
     * The tab row of an item links both pages and names itself; the current tab is not a link (p110-e).
     */
    public function test_item_tab_row(): void {
        global $PAGE;
        $PAGE->set_url('/local/nextpool/statistics.php', ['id' => $this->item->id]);
        $html = area_nav::item($this->item, area_nav::ITEM_STATISTICS);

        $this->assertStringContainsString('aria-label="Catalogue item"', $html);
        $this->assertStringContainsString('/local/nextpool/item.php?id=' . $this->item->id, $html);
        $this->assertStringContainsString('Statistics', $html);
        $this->assertStringNotContainsString('/local/nextpool/statistics.php', $html);

        $html = area_nav::item($this->item, area_nav::ITEM);
        $this->assertStringContainsString('/local/nextpool/statistics.php?id=' . $this->item->id, $html);
    }
}
