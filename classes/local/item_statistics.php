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
 * Statistics of a catalogue item for the editorial team: compute, store, read (F15, plan 21 §5, task126).
 *
 * The content type handler computes anonymous figures from the learning data of the item's placed activities
 * (base::get_learning_statistics(), p110-a); this class stores them once a day (scheduled task
 * compute_item_statistics, p110-b, q110) and reads them back with the live numbers of placements. The table holds no
 * personal data: only values the anonymity rule allows, flags instead of suppressed values (D9, plan 21 §5.5).
 * Pages never compute on opening (FR-F15-06).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_statistics {
    /** @var string Table of the stored statistics. */
    public const TABLE = 'local_nextpool_item_stats';

    /** @var int Version number of the row with the total. */
    public const TOTAL = 0;

    /** @var string[] Value names, in display order: counts, rates in percent, scores 0-100, seconds, attempts. */
    public const VALUES = [
        'started', 'completed', 'passed', 'completionrate', 'passrate',
        'scoremedian', 'scorelow', 'scorehigh', 'durationmedian', 'attemptsmedian',
    ];

    /** @var string[] Values stored as decimal numbers; the others are integers. */
    private const DECIMALS = ['completionrate', 'passrate', 'scoremedian', 'scorelow', 'scorehigh'];

    /** @var string Flag: fewer than 5 people, no value yet (D9; local_eledia_lrs\local\anonymity::FLAG_SMALL). */
    public const FLAG_SMALL = 'small';

    /** @var string Flag: not shown so that small groups cannot be worked out (anonymity::FLAG_DERIVED). */
    public const FLAG_DERIVED = 'derived';

    /** @var string Flag: nobody has this value, e.g. the content reports no result (pass rate "–", not 0 %). */
    public const FLAG_NONE = 'none';

    /**
     * Whether the type of an item has learning data (q111, F15.AC06).
     *
     * @param \stdClass $item Item record.
     * @return bool
     */
    public static function supports(\stdClass $item): bool {
        $handler = item_types::get_handler((string) $item->type);
        return $handler !== null && $handler->supports_learning_data();
    }

    /**
     * Placements of an item now, not from the stored statistics (plan 21 §5.2).
     *
     * @param int $itemid Item id.
     * @return \stdClass courses (int, distinct courses) and activities (int, placed course modules).
     */
    public static function get_placement_counts(int $itemid): \stdClass {
        global $DB;
        $counts = $DB->get_record_sql(
            'SELECT COUNT(DISTINCT courseid) AS courses, COUNT(1) AS activities
               FROM {' . placement_manager::TABLE . '}
              WHERE itemid = :itemid',
            ['itemid' => $itemid]
        );
        return (object) ['courses' => (int) $counts->courses, 'activities' => (int) $counts->activities];
    }

    /**
     * Compute the statistics of one item and replace the stored rows (one transaction).
     *
     * Without placements, or for a type without learning data, the item keeps no rows.
     *
     * @param int $itemid Item id.
     * @param int|null $now Time of the calculation, null for now.
     * @return bool Whether rows were stored.
     */
    public static function compute(int $itemid, ?int $now = null): bool {
        global $DB;
        $item = item_manager::get($itemid);
        $cmids = array_map('intval', $DB->get_fieldset(placement_manager::TABLE, 'cmid', ['itemid' => $itemid]));
        $handler = item_types::get_handler((string) $item->type);
        $figures = null;
        if ($cmids && $handler !== null && $handler->supports_learning_data()) {
            $figures = $handler->get_learning_statistics($item, $cmids);
        }
        $now = $now ?? time();

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE, ['itemid' => $itemid]);
        if ($figures !== null) {
            $rows = [self::to_row($itemid, self::TOTAL, $figures['total'] ?? [], $now)];
            $rows[0]->coursecount = isset($figures['coursecount']) ? (int) $figures['coursecount'] : null;
            foreach ($figures['versions'] ?? [] as $versionnr => $set) {
                if ((int) $versionnr > 0) {
                    $rows[] = self::to_row($itemid, (int) $versionnr, $set, $now);
                }
            }
            $DB->insert_records(self::TABLE, $rows);
        }
        $transaction->allow_commit();
        return $figures !== null;
    }

    /**
     * Compute the statistics of every item with placements, and clear those of items without any (scheduled task).
     *
     * A failure in one item is reported and does not stop the others.
     *
     * @param int|null $now Time of the calculation, null for now.
     * @return int Number of items with stored statistics.
     */
    public static function compute_all(?int $now = null): int {
        global $DB;
        $itemids = $DB->get_fieldset_sql(
            'SELECT itemid FROM {' . placement_manager::TABLE . '}
              UNION
             SELECT itemid FROM {' . self::TABLE . '}'
        );
        $itemids = array_unique(array_map('intval', $itemids));
        sort($itemids);
        $stored = 0;
        foreach ($itemids as $itemid) {
            try {
                if (!$DB->record_exists(item_manager::TABLE, ['id' => $itemid])) {
                    self::delete_for_item($itemid);
                } else if (self::compute($itemid, $now)) {
                    $stored++;
                }
            } catch (\Throwable $e) {
                mtrace('Item ' . $itemid . ': statistics not computed: ' . $e->getMessage());
            }
        }
        return $stored;
    }

    /**
     * Statistics of an item as stored by the last calculation, with the live placements.
     *
     * Figure sets (total and per version) are objects with one property per name of VALUES (int or float; null if
     * suppressed or without value) and flags (value name => FLAG_SMALL, FLAG_DERIVED or FLAG_NONE for every null
     * value).
     *
     * @param int $itemid Item id.
     * @return \stdClass With
     *     itemid (int);
     *     supported (bool, whether the type has learning data, see supports());
     *     courses, activities (int, live placements, see get_placement_counts());
     *     expectedduration (int seconds or null, expected learning time of the item);
     *     timecalculated (int or null: null if never calculated);
     *     coursecount (int or null: courses with learning data, only from 2 on);
     *     total (figure set or null if never calculated);
     *     versions (list, by version number ascending, of objects with versionnr (int), versionid (int or null if the
     *     version no longer exists), label (string), withdrawn (bool), used (bool, false: nobody learned with it yet)
     *     and figures (figure set, null if not used)). Lists every published or withdrawn version and every version
     *     with figures.
     */
    public static function get(int $itemid): \stdClass {
        global $DB;
        $item = item_manager::get($itemid);
        $rows = [];
        foreach ($DB->get_records(self::TABLE, ['itemid' => $itemid], 'versionnr ASC') as $row) {
            $rows[(int) $row->versionnr] = $row;
        }
        $totalrow = $rows[self::TOTAL] ?? null;
        unset($rows[self::TOTAL]);
        $placements = self::get_placement_counts($itemid);

        $versions = [];
        foreach (array_reverse(version_manager::get_versions($itemid)) as $version) {
            $versionnr = (int) $version->versionnr;
            if (empty($version->timepublished) && !isset($rows[$versionnr])) {
                continue;
            }
            $versions[$versionnr] = (object) [
                'versionnr' => $versionnr,
                'versionid' => (int) $version->id,
                'label' => (string) $version->label,
                'withdrawn' => $version->status === status::WITHDRAWN,
                'used' => isset($rows[$versionnr]),
                'figures' => isset($rows[$versionnr]) ? self::from_row($rows[$versionnr]) : null,
            ];
        }
        foreach ($rows as $versionnr => $row) {
            if (!isset($versions[$versionnr])) {
                $versions[$versionnr] = (object) [
                    'versionnr' => $versionnr,
                    'versionid' => null,
                    'label' => '',
                    'withdrawn' => false,
                    'used' => true,
                    'figures' => self::from_row($row),
                ];
            }
        }
        ksort($versions);

        return (object) [
            'itemid' => $itemid,
            'supported' => self::supports($item),
            'courses' => $placements->courses,
            'activities' => $placements->activities,
            'expectedduration' => (int) $item->duration > 0 ? (int) $item->duration : null,
            'timecalculated' => $totalrow ? (int) $totalrow->timecalculated : null,
            'coursecount' => $totalrow && $totalrow->coursecount !== null ? (int) $totalrow->coursecount : null,
            'total' => $totalrow ? self::from_row($totalrow) : null,
            'versions' => array_values($versions),
        ];
    }

    /**
     * Delete the statistics of an item, e.g. with the item (plan 21 §5.1).
     *
     * @param int $itemid Item id.
     */
    public static function delete_for_item(int $itemid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['itemid' => $itemid]);
    }

    /**
     * Table row from a figure set of the handler. Unknown value names are ignored, a value without a number keeps
     * its flag (FLAG_SMALL if the handler gave none).
     *
     * @param int $itemid Item id.
     * @param int $versionnr Version number, TOTAL for the total.
     * @param array $set Figure set with values and flags.
     * @param int $now Time of the calculation.
     * @return \stdClass
     */
    private static function to_row(int $itemid, int $versionnr, array $set, int $now): \stdClass {
        $row = (object) ['itemid' => $itemid, 'versionnr' => $versionnr, 'coursecount' => null, 'timecalculated' => $now];
        $flags = [];
        foreach (self::VALUES as $name) {
            $value = $set['values'][$name] ?? null;
            if ($value === null) {
                $flag = $set['flags'][$name] ?? self::FLAG_SMALL;
                $flags[] = $name . ':' . (in_array($flag, [self::FLAG_DERIVED, self::FLAG_NONE], true) ? $flag : self::FLAG_SMALL);
                $row->$name = null;
            } else {
                $row->$name = in_array($name, self::DECIMALS, true) ? round((float) $value, 2) : (int) round((float) $value);
            }
        }
        $row->suppressed = $flags ? implode(',', $flags) : null;
        return $row;
    }

    /**
     * Figure set from a table row.
     *
     * @param \stdClass $row Table row.
     * @return \stdClass
     */
    private static function from_row(\stdClass $row): \stdClass {
        $set = new \stdClass();
        foreach (self::VALUES as $name) {
            if ($row->$name === null) {
                $set->$name = null;
            } else {
                $set->$name = in_array($name, self::DECIMALS, true) ? (float) $row->$name : (int) $row->$name;
            }
        }
        $set->flags = [];
        foreach (array_filter(explode(',', (string) $row->suppressed)) as $entry) {
            [$name, $flag] = array_pad(explode(':', $entry, 2), 2, self::FLAG_SMALL);
            if (in_array($name, self::VALUES, true) && $set->$name === null) {
                $set->flags[$name] = $flag;
            }
        }
        foreach (self::VALUES as $name) {
            if ($set->$name === null && !isset($set->flags[$name])) {
                $set->flags[$name] = self::FLAG_SMALL;
            }
        }
        return $set;
    }
}
