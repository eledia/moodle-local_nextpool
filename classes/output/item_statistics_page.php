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
use local_nextpool\local\item_statistics;
use local_nextpool\local\status;

/**
 * Tab "Statistics" of a catalogue item: placements, key figures in total and per version, notes (F15, task127).
 *
 * Only formats what item_statistics::get() returns; it computes nothing (FR-F15-06) and names no course, group or
 * person (FR-F15-05). A value the anonymity rule suppressed is shown as text, never as a number or a symbol alone
 * (F15.AC08).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_statistics_page implements \renderable, \templatable {
    /** @var string[] Columns of the table after the version, in display order; "scorerange" joins scorelow and scorehigh. */
    public const COLUMNS = [
        'started', 'completed', 'passed', 'completionrate', 'passrate',
        'scoremedian', 'scorerange', 'durationmedian', 'attemptsmedian',
    ];

    /** @var string[] Values in percent. */
    private const PERCENT = ['completionrate', 'passrate', 'scoremedian'];

    /** @var array<string,string> Text for FLAG_NONE per column: the content reports no such value. */
    private const NONE_STRINGS = [
        'passrate' => 'statsnone_result',
        'scoremedian' => 'statsnone_score',
        'scorerange' => 'statsnone_score',
        'durationmedian' => 'statsnone_duration',
    ];

    /** @var string[] Flags found while exporting, for the notes below the table. */
    private array $flagsused = [];

    /**
     * Constructor.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $statistics Statistics as returned by item_statistics::get().
     */
    public function __construct(
        /** @var \stdClass Item record. */
        private readonly \stdClass $item,
        /** @var \stdClass Statistics of the item. */
        private readonly \stdClass $statistics,
    ) {
    }

    /**
     * Data for the template local_nextpool/item_statistics_page.
     *
     * @param \renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $stats = $this->statistics;
        $this->flagsused = [];
        $calculated = $stats->supported && $stats->total !== null;

        $placements = [
            ['label' => get_string('statscourses', 'local_nextpool'), 'value' => self::number($stats->courses)],
            ['label' => get_string('statsactivities', 'local_nextpool'), 'value' => self::number($stats->activities)],
        ];
        if ($calculated && $stats->coursecount !== null) {
            // Only from 2 courses on, so that a single course cannot be told from the placements (q113).
            $placements[] = ['label' => get_string('statscoursecount', 'local_nextpool'),
                'value' => self::number($stats->coursecount)];
        }
        if ($stats->supported && $stats->expectedduration !== null) {
            $placements[] = ['label' => get_string('statsexpectedduration', 'local_nextpool'),
                'value' => self::duration($stats->expectedduration)];
        }

        $notice = '';
        if (!$stats->supported) {
            $notice = get_string('statsnolearningdata', 'local_nextpool');
        } else if (!$calculated) {
            $notice = get_string($stats->courses > 0 ? 'statsnotcalculated' : 'statsnotplaced', 'local_nextpool');
        }

        $rows = [];
        if ($calculated) {
            $rows[] = $this->export_row(
                get_string('statstotal', 'local_nextpool'),
                false,
                $stats->total,
                $stats->expectedduration
            );
            foreach ($stats->versions as $version) {
                $rows[] = $this->export_row(
                    get_string('versionnr', 'local_nextpool', $version->versionnr),
                    (bool) $version->withdrawn,
                    $version->used ? $version->figures : null,
                    null
                );
            }
        }

        $legend = [];
        foreach ([item_statistics::FLAG_SMALL, item_statistics::FLAG_DERIVED, item_statistics::FLAG_NONE] as $flag) {
            if (in_array($flag, $this->flagsused, true)) {
                $legend[] = get_string('statslegend_' . $flag, 'local_nextpool');
            }
        }
        if ($rows) {
            $legend[] = get_string('statslegend_rates', 'local_nextpool');
        }

        return [
            'title' => format_string($this->item->title, true, ['context' => access::get_context($this->item)]),
            'asof' => $calculated ? get_string(
                'statsasof',
                'local_nextpool',
                userdate((int) $stats->timecalculated, get_string('strftimedatetime', 'langconfig'))
            ) : '',
            'notice' => $notice,
            'placements' => $placements,
            'hasrows' => !empty($rows),
            'columns' => array_map(
                static fn(string $column) => ['name' => $column, 'label' => get_string('stats' . $column, 'local_nextpool')],
                self::COLUMNS
            ),
            'columncount' => count(self::COLUMNS),
            'rows' => $rows,
            'legend' => $legend,
            'haslegend' => !empty($legend),
            'deletionhint' => $calculated ? get_string('statsdeletionhint', 'local_nextpool') : '',
            'anonymoushint' => $calculated ? get_string('statsanonymoushint', 'local_nextpool') : '',
        ];
    }

    /**
     * One row of the table: the total or a version.
     *
     * A row whose values are all suppressed for the same reason, or a version nobody learned with yet, gets one
     * sentence across the row instead of the same short text in every cell.
     *
     * @param string $label Row heading.
     * @param bool $withdrawn Whether the version is withdrawn (badge "Withdrawn").
     * @param \stdClass|null $figures Figure set, null for a version not used yet.
     * @param int|null $expected Expected learning time in seconds, compared in this row; null for none.
     * @return array {label, withdrawn, withdrawnlabel, withdrawnclass, message, cells: [{label, value (HTML)}]}
     */
    private function export_row(string $label, bool $withdrawn, ?\stdClass $figures, ?int $expected): array {
        $row = [
            'label' => $label,
            'withdrawn' => $withdrawn,
            'withdrawnlabel' => $withdrawn ? status::get_name(status::WITHDRAWN) : '',
            'withdrawnclass' => status::get_badge_class(status::WITHDRAWN),
            'message' => '',
            'cells' => [],
        ];
        if ($figures === null) {
            $row['message'] = get_string('statsunused', 'local_nextpool');
            return $row;
        }
        $flags = array_values(array_unique($figures->flags));
        $allsame = count($figures->flags) === count(item_statistics::VALUES) && count($flags) === 1;
        if ($allsame && $flags[0] !== item_statistics::FLAG_NONE) {
            $this->flagsused[] = $flags[0];
            $row['message'] = get_string('statsrow_' . $flags[0], 'local_nextpool');
            return $row;
        }
        foreach (self::COLUMNS as $column) {
            $row['cells'][] = [
                'label' => get_string('stats' . $column, 'local_nextpool'),
                'value' => $this->cell($column, $figures, $expected),
            ];
        }
        return $row;
    }

    /**
     * Content of one cell: the formatted value or the text of its flag.
     *
     * @param string $column Column name, see COLUMNS.
     * @param \stdClass $figures Figure set.
     * @param int|null $expected Expected learning time in seconds for the duration cell, null for none.
     * @return string HTML.
     */
    private function cell(string $column, \stdClass $figures, ?int $expected): string {
        $names = $column === 'scorerange' ? ['scorelow', 'scorehigh'] : [$column];
        foreach ($names as $name) {
            if ($figures->$name === null) {
                return $this->flag_text($column, $figures->flags[$name] ?? item_statistics::FLAG_SMALL);
            }
        }
        if ($column === 'scorerange') {
            return s(get_string('statsrange', 'local_nextpool', (object) [
                'low' => self::percent($figures->scorelow),
                'high' => self::percent($figures->scorehigh),
            ]));
        }
        if (in_array($column, self::PERCENT, true)) {
            return s(self::percent($figures->$column));
        }
        if ($column === 'durationmedian') {
            $html = self::duration((int) $figures->durationmedian);
            if ($expected !== null) {
                $html .= ' ' . \html_writer::span(
                    get_string('statsexpected', 'local_nextpool', self::duration($expected)),
                    'text-muted'
                );
            }
            return $html;
        }
        return self::number($figures->$column);
    }

    /**
     * Short text instead of a value, remembered for the notes.
     *
     * @param string $column Column name.
     * @param string $flag FLAG_SMALL, FLAG_DERIVED or FLAG_NONE.
     * @return string HTML.
     */
    private function flag_text(string $column, string $flag): string {
        if (!in_array($flag, [item_statistics::FLAG_DERIVED, item_statistics::FLAG_NONE], true)) {
            $flag = item_statistics::FLAG_SMALL;
        }
        $this->flagsused[] = $flag;
        $text = $flag === item_statistics::FLAG_NONE
            ? get_string(self::NONE_STRINGS[$column] ?? 'statsnone', 'local_nextpool')
            : get_string('statsflag_' . $flag, 'local_nextpool');
        return \html_writer::span(s($text), 'local-nextpool-stats__flag text-muted fst-italic');
    }

    /**
     * A whole number in the format of the current language.
     *
     * @param int|float $value Number.
     * @return string
     */
    public static function number(int|float $value): string {
        return format_float((float) $value, 0, true);
    }

    /**
     * A percentage in the format of the current language, with at most one decimal (82.5 %, 80 %).
     *
     * @param int|float $value Percentage 0-100.
     * @return string
     */
    public static function percent(int|float $value): string {
        return get_string('statspercent', 'local_nextpool', format_float((float) $value, 1, true, true));
    }

    /**
     * A duration as h:mm, with the time in words for screen readers (F15.AC08).
     *
     * Rounded to minutes; a time under a minute counts as one minute so that it does not read as nothing.
     *
     * @param int $seconds Duration in seconds.
     * @return string HTML.
     */
    public static function duration(int $seconds): string {
        $minutes = (int) round($seconds / MINSECS);
        if ($seconds > 0 && $minutes === 0) {
            $minutes = 1;
        }
        $short = sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
        return \html_writer::span($short, '', ['aria-hidden' => 'true'])
            . \html_writer::span(format_time($minutes * MINSECS), 'visually-hidden');
    }
}
