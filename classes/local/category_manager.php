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
 * Admin-maintained catalogue category tree (F10).
 *
 * Catalogue categories are independent of course categories: course
 * categories decide where an item is visible (item contextid), catalogue
 * categories only organise the catalogue. Each row stores its materialised
 * path of ids ("/3/7"), so subtrees can be found without recursion.
 *
 * This is the domain layer; it does not check capabilities.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class category_manager {
    /** @var string Category table. */
    public const TABLE = 'local_nextpool_category';

    /** @var int Maximum length of the path column. */
    private const PATH_LENGTH = 255;

    /**
     * Create a category as the last child of its parent.
     *
     * @param string $name Display name.
     * @param int $parent Parent category id, 0 for a top-level category.
     * @return \stdClass The stored category.
     */
    public static function create(string $name, int $parent = 0): \stdClass {
        global $DB;
        $name = self::validate_name($name);
        $parentpath = self::get_parent_path($parent);
        $now = time();

        $transaction = $DB->start_delegated_transaction();
        $id = $DB->insert_record(self::TABLE, (object) [
            'parent' => $parent,
            'name' => $name,
            'path' => '',
            'sortorder' => self::next_sortorder($parent),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $path = $parentpath . '/' . $id;
        self::validate_path_length($path);
        $DB->set_field(self::TABLE, 'path', $path, ['id' => $id]);
        $transaction->allow_commit();

        return self::get($id);
    }

    /**
     * Rename a category and optionally move it to another parent.
     *
     * A moved category becomes the last child of its new parent and takes its subtree along.
     *
     * @param int $id Category id.
     * @param string $name New display name.
     * @param int $parent New parent id, 0 for top level.
     * @return \stdClass The stored category.
     * @throws \moodle_exception If the new parent is the category itself or one of its descendants.
     */
    public static function update(int $id, string $name, int $parent): \stdClass {
        global $DB;
        $category = self::get($id);
        $name = self::validate_name($name);
        $update = (object) ['id' => $id, 'name' => $name, 'timemodified' => time()];

        $transaction = $DB->start_delegated_transaction();
        if ($parent !== (int) $category->parent) {
            $parentpath = self::get_parent_path($parent);
            if (str_starts_with($parentpath . '/', $category->path . '/')) {
                throw new \moodle_exception('error_categoryloop', 'local_nextpool');
            }
            $newpath = $parentpath . '/' . $id;
            self::move_subtree($category->path, $newpath);
            $update->parent = $parent;
            $update->path = $newpath;
            $update->sortorder = self::next_sortorder($parent);
        }
        $DB->update_record(self::TABLE, $update);
        $transaction->allow_commit();

        return self::get($id);
    }

    /**
     * Swap a category with its previous or next sibling.
     *
     * @param int $id Category id.
     * @param bool $up True to move before the previous sibling, false to move after the next one.
     * @return bool Whether the category moved (false at the start or end of the list).
     */
    public static function move(int $id, bool $up): bool {
        global $DB;
        $siblings = array_values(self::get_children((int) self::get($id)->parent));
        $ids = array_map(static fn($c) => (int) $c->id, $siblings);
        $pos = array_search($id, $ids, true);
        $swap = $up ? $pos - 1 : $pos + 1;
        if (!isset($ids[$swap])) {
            return false;
        }
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];

        $transaction = $DB->start_delegated_transaction();
        foreach ($ids as $sortorder => $categoryid) {
            $DB->set_field(self::TABLE, 'sortorder', $sortorder + 1, ['id' => $categoryid]);
        }
        $transaction->allow_commit();
        return true;
    }

    /**
     * Whether a category can be deleted: it has no subcategories and no items.
     *
     * @param int $id Category id.
     * @return bool
     */
    public static function can_delete(int $id): bool {
        global $DB;
        return !$DB->record_exists(self::TABLE, ['parent' => $id])
            && !$DB->record_exists(item_manager::TABLE, ['categoryid' => $id]);
    }

    /**
     * Delete an empty category.
     *
     * @param int $id Category id.
     * @throws \moodle_exception If it has subcategories or items.
     */
    public static function delete(int $id): void {
        global $DB;
        self::get($id);
        if (!self::can_delete($id)) {
            throw new \moodle_exception('error_categorynotempty', 'local_nextpool');
        }
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Load a category.
     *
     * @param int $id Category id.
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Direct children of a category, in display order.
     *
     * @param int $parent Parent id, 0 for top level.
     * @return \stdClass[] Keyed by id.
     */
    public static function get_children(int $parent): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['parent' => $parent], 'sortorder, id');
    }

    /**
     * All categories in tree order (depth first, siblings in display order).
     *
     * Each record gets a 'depth' property (0 for top level).
     *
     * @return \stdClass[]
     */
    public static function get_tree(): array {
        global $DB;
        $byparent = [];
        foreach ($DB->get_records(self::TABLE, null, 'sortorder, id') as $category) {
            $byparent[(int) $category->parent][] = $category;
        }
        $tree = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$tree, $byparent): void {
            foreach ($byparent[$parent] ?? [] as $category) {
                $category->depth = $depth;
                $tree[] = $category;
                $walk((int) $category->id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $tree;
    }

    /**
     * Options for a select element: id => full name ("Parent / Child").
     *
     * @param int $excludeid Leave out this category and its subtree (for "move to" menus).
     * @return array<int,string>
     */
    public static function get_menu(int $excludeid = 0): array {
        $excludepath = $excludeid ? self::get($excludeid)->path : null;
        $context = \context_system::instance();
        $names = [];
        $menu = [];
        foreach (self::get_tree() as $category) {
            $names[(int) $category->id] = format_string($category->name, true, ['context' => $context]);
            if ($excludepath !== null && str_starts_with($category->path . '/', $excludepath . '/')) {
                continue;
            }
            $ids = array_map('intval', explode('/', trim($category->path, '/')));
            $menu[(int) $category->id] = implode(' / ', array_map(static fn($i) => $names[$i] ?? '', $ids));
        }
        return $menu;
    }

    /**
     * Full display name of a category ("Parent / Child").
     *
     * @param int $id Category id.
     * @return string
     */
    public static function get_full_name(int $id): string {
        global $DB;
        $ids = array_map('intval', explode('/', trim(self::get($id)->path, '/')));
        $names = $DB->get_records_list(self::TABLE, 'id', $ids, '', 'id, name');
        $context = \context_system::instance();
        return implode(' / ', array_map(
            static fn($i) => isset($names[$i]) ? format_string($names[$i]->name, true, ['context' => $context]) : '',
            $ids
        ));
    }

    /**
     * Trim and check a category name.
     *
     * @param string $name Raw name.
     * @return string
     * @throws \moodle_exception If empty or too long.
     */
    private static function validate_name(string $name): string {
        $name = trim($name);
        if ($name === '') {
            throw new \moodle_exception('error_required', 'local_nextpool', '', get_string('categoryname', 'local_nextpool'));
        }
        if (\core_text::strlen($name) > 255) {
            throw new \moodle_exception('error_toolong', 'local_nextpool', '', get_string('categoryname', 'local_nextpool'));
        }
        return $name;
    }

    /**
     * Materialised path of a parent ('' for the top level).
     *
     * @param int $parent Parent id or 0.
     * @return string
     * @throws \moodle_exception If the parent does not exist.
     */
    private static function get_parent_path(int $parent): string {
        global $DB;
        if ($parent === 0) {
            return '';
        }
        $path = $DB->get_field(self::TABLE, 'path', ['id' => $parent]);
        if ($path === false) {
            throw new \moodle_exception('error_invalidcategory', 'local_nextpool');
        }
        return $path;
    }

    /**
     * Next free sort order among the children of a parent.
     *
     * @param int $parent Parent id or 0.
     * @return int
     */
    private static function next_sortorder(int $parent): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {' . self::TABLE . '} WHERE parent = :parent',
            ['parent' => $parent]
        );
    }

    /**
     * Rewrite the paths of all descendants after a move.
     *
     * @param string $oldpath Old path of the moved category.
     * @param string $newpath New path of the moved category.
     */
    private static function move_subtree(string $oldpath, string $newpath): void {
        global $DB;
        self::validate_path_length($newpath);
        $like = $DB->sql_like('path', ':path');
        $params = ['path' => $DB->sql_like_escape($oldpath) . '/%'];
        $descendants = $DB->get_records_select(self::TABLE, $like, $params, '', 'id, path');
        foreach ($descendants as $descendant) {
            $path = $newpath . substr($descendant->path, strlen($oldpath));
            self::validate_path_length($path);
            $DB->set_field(self::TABLE, 'path', $path, ['id' => $descendant->id]);
        }
    }

    /**
     * Reject trees deeper than the path column can hold.
     *
     * @param string $path Materialised path.
     * @throws \moodle_exception If too long.
     */
    private static function validate_path_length(string $path): void {
        if (strlen($path) > self::PATH_LENGTH) {
            throw new \moodle_exception('error_categorydepth', 'local_nextpool');
        }
    }
}
