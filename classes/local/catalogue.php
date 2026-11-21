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
 * What teachers find in the catalogue from a course (F05, F10).
 *
 * A course sees published items that are available site-wide, in the
 * course's category or one of its parent categories, or only in this course (M06).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalogue {
    /**
     * Contexts whose items a course can use: the course itself, its parent categories and the system.
     *
     * The course context holds the items uploaded from this course (adr30, q90); other courses never
     * see them, because their own context path does not contain this course.
     *
     * @param int $courseid Course id.
     * @return int[] Context ids.
     */
    public static function get_visibility_contextids(int $courseid): array {
        return array_map('intval', \context_course::instance($courseid)->get_parent_context_ids(true));
    }

    /**
     * Whether an item may be placed in a course (published and visible there).
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course id.
     * @return bool
     */
    public static function is_available_in_course(\stdClass $item, int $courseid): bool {
        return $item->status === status::PUBLISHED
            && in_array((int) $item->contextid, self::get_visibility_contextids($courseid), true);
    }

    /**
     * Published items available in a course.
     *
     * @param int $courseid Course id.
     * @param string $search Text in title or description.
     * @param int $categoryid Catalogue category including its subcategories, 0 (or an unknown id) for all.
     * @param string $type Content type, '' for all.
     * @param string $tag Tag name (as core normalises it), '' for all.
     * @param int $limitfrom First record, for paging.
     * @param int $limitnum Number of records, 0 for all.
     * @return \stdClass[] Items with currentversionnr, ordered by title.
     */
    public static function get_items(
        int $courseid,
        string $search = '',
        int $categoryid = 0,
        string $type = '',
        string $tag = '',
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        global $DB;
        [$from, $params] = self::items_from($courseid, $search, $categoryid, $type, $tag);
        return $DB->get_records_sql(
            "SELECT i.*, v.versionnr AS currentversionnr $from ORDER BY i.title, i.id",
            $params,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * Number of published items available in a course, with the filters of get_items().
     *
     * @param int $courseid Course id.
     * @param string $search Text in title or description.
     * @param int $categoryid Catalogue category, 0 for all.
     * @param string $type Content type, '' for all.
     * @param string $tag Tag name, '' for all.
     * @return int
     */
    public static function count_items(
        int $courseid,
        string $search = '',
        int $categoryid = 0,
        string $type = '',
        string $tag = ''
    ): int {
        global $DB;
        [$from, $params] = self::items_from($courseid, $search, $categoryid, $type, $tag);
        return (int) $DB->count_records_sql("SELECT COUNT(1) $from", $params);
    }

    /**
     * Number of items that match the filters but are not published yet (draft or in review), for the hint to
     * editors in the course catalogue (task104).
     *
     * @param int $courseid Course id.
     * @param int[] $contextids Visibility contexts whose items the user may edit; only these are counted.
     * @param string $search Text in title or description.
     * @param int $categoryid Catalogue category, 0 for all.
     * @param string $type Content type, '' for all.
     * @param string $tag Tag name, '' for all.
     * @return int
     */
    public static function count_unpublished(
        int $courseid,
        array $contextids,
        string $search = '',
        int $categoryid = 0,
        string $type = '',
        string $tag = ''
    ): int {
        global $DB;
        $contextids = array_values(array_intersect(self::get_visibility_contextids($courseid), $contextids));
        if (!$contextids) {
            return 0;
        }
        [$from, $params] = self::items_from(
            $courseid,
            $search,
            $categoryid,
            $type,
            $tag,
            [status::DRAFT, status::REVIEW],
            $contextids
        );
        return (int) $DB->count_records_sql("SELECT COUNT(1) $from", $params);
    }

    /**
     * FROM and WHERE part shared by get_items(), count_items() and count_unpublished().
     *
     * @param int $courseid Course id.
     * @param string $search Text in title or description.
     * @param int $categoryid Catalogue category, 0 for all.
     * @param string $type Content type, '' for all.
     * @param string $tag Tag name, '' for all.
     * @param string[] $statuses Item statuses; only published items have a current version for sure.
     * @param int[]|null $contextids Visibility contexts, null for all the course can use.
     * @return array [SQL, params]
     */
    private static function items_from(
        int $courseid,
        string $search,
        int $categoryid,
        string $type,
        string $tag,
        array $statuses = [status::PUBLISHED],
        ?array $contextids = null
    ): array {
        global $DB;
        $contextids ??= self::get_visibility_contextids($courseid);
        [$ctxsql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        [$statussql, $statusparams] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'status');
        $where = ["i.status $statussql", "i.contextid $ctxsql"];
        $params += $statusparams;
        if ($search !== '') {
            $where[] = '(' . $DB->sql_like('i.title', ':search1', false, false) . ' OR '
                . $DB->sql_like('i.description', ':search2', false, false) . ')';
            $params['search1'] = $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        // The id comes from the URL: a category that does not exist (any more) means no filter.
        $path = $categoryid > 0 ? $DB->get_field(category_manager::TABLE, 'path', ['id' => $categoryid]) : false;
        if ($path !== false) {
            $where[] = '(c.id = :categoryid OR ' . $DB->sql_like('c.path', ':categorypath') . ')';
            $params['categoryid'] = $categoryid;
            $params['categorypath'] = $DB->sql_like_escape($path) . '/%';
        }
        if ($type !== '') {
            $where[] = 'i.type = :type';
            $params['type'] = $type;
        }
        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1
                                  FROM {tag_instance} ti
                                  JOIN {tag} t ON t.id = ti.tagid
                                 WHERE ti.component = :tagcomponent AND ti.itemtype = :tagitemtype
                                       AND ti.itemid = i.id AND t.name = :tagname)';
            $params += ['tagcomponent' => 'local_nextpool', 'tagitemtype' => item_manager::TAG_ITEMTYPE,
                'tagname' => \core_text::strtolower($tag)];
        }
        $join = $statuses === [status::PUBLISHED] ? 'JOIN' : 'LEFT JOIN';
        $from = 'FROM {' . item_manager::TABLE . '} i
             ' . $join . ' {' . version_manager::TABLE . '} v ON v.id = i.currentversionid
             LEFT JOIN {' . category_manager::TABLE . '} c ON c.id = i.categoryid
                 WHERE ' . implode(' AND ', $where);
        return [$from, $params];
    }

    /**
     * Tags of the published items available in a course, for the catalogue filter (F10).
     *
     * @param int $courseid Course id.
     * @return array<string,string> Normalised name => display name, sorted by display name.
     */
    public static function get_tags(int $courseid): array {
        global $DB;
        [$ctxsql, $params] = $DB->get_in_or_equal(self::get_visibility_contextids($courseid), SQL_PARAMS_NAMED, 'ctx');
        $params += ['published' => status::PUBLISHED, 'component' => 'local_nextpool', 'itemtype' => item_manager::TAG_ITEMTYPE];
        $tags = $DB->get_records_sql_menu(
            'SELECT DISTINCT t.name, t.rawname
               FROM {tag} t
               JOIN {tag_instance} ti ON ti.tagid = t.id
               JOIN {' . item_manager::TABLE . '} i ON i.id = ti.itemid
              WHERE ti.component = :component AND ti.itemtype = :itemtype
                    AND i.status = :published AND i.contextid ' . $ctxsql,
            $params
        );
        \core_collator::asort($tags);
        return $tags;
    }
}
