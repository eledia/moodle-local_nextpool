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

use local_nextpool\event\item_created;
use local_nextpool\event\item_deleted;
use local_nextpool\event\item_updated;

/**
 * Catalogue items: metadata, authors and editorial status (F01, F09 core fields).
 *
 * This is the domain layer; it validates data but does not check capabilities.
 * Pages and web services check capabilities before calling it.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_manager {
    /** @var string Item table. */
    public const TABLE = 'local_nextpool_item';
    /** @var string Tag item type of catalogue items, see db/tag.php. */
    public const TAG_ITEMTYPE = 'local_nextpool_item';
    /** @var string Author table. */
    public const AUTHOR_TABLE = 'local_nextpool_author';

    /** @var string[] Metadata fields that can be set on create and update. */
    private const FIELDS = [
        'title', 'description', 'descriptionformat', 'type', 'language', 'duration',
        'audience', 'origin', 'license', 'categoryid', 'contextid', 'sourceid', 'origincourseid',
    ];

    /** @var string Filter value of the editorial list for items uploaded from a course (TD-402). */
    public const ORIGIN_COURSE = 'course';

    /** @var string Sort key of the editorial list: title. */
    public const SORT_TITLE = 'title';

    /** @var string Sort key of the editorial list: status in workflow order. */
    public const SORT_STATUS = 'status';

    /** @var string Sort key of the editorial list: catalogue category by its displayed name. */
    public const SORT_CATEGORY = 'category';

    /** @var string Sort key of the editorial list: time of the last change (default). */
    public const SORT_TIMEMODIFIED = 'timemodified';

    /** @var string Sort direction: ascending. */
    public const SORT_ASC = 'asc';

    /** @var string Sort direction: descending. */
    public const SORT_DESC = 'desc';

    /** @var array<string,string> Sort keys of the editorial list with the direction of the first click (task168). */
    public const SORT_FIELDS = [
        self::SORT_TITLE => self::SORT_ASC,
        self::SORT_STATUS => self::SORT_ASC,
        self::SORT_CATEGORY => self::SORT_ASC,
        self::SORT_TIMEMODIFIED => self::SORT_DESC,
    ];

    /**
     * Create an item in status draft.
     *
     * @param \stdClass $data Metadata (see FIELDS). contextid defaults to the system context.
     * @param array $authors At least one author.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The stored item.
     */
    public static function create(\stdClass $data, array $authors, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $now = time();

        $record = self::extract_fields($data);
        $record->contextid = $record->contextid ?? \context_system::instance()->id;
        self::validate($record);
        $authors = self::validate_authors($authors);

        $record->uuid = \core\uuid::generate();
        $record->status = status::DRAFT;
        $record->currentversionid = null;
        $record->createdby = $userid;
        $record->usermodified = $userid;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $transaction = $DB->start_delegated_transaction();
        $record->id = $DB->insert_record(self::TABLE, $record);
        self::store_authors($record->id, $authors);
        $transaction->allow_commit();

        $item = self::get($record->id);
        item_created::create_from_item($item)->trigger();
        return $item;
    }

    /**
     * Update item metadata and optionally replace the authors.
     *
     * Type and backend cannot change once the item has versions, because the
     * versions were imported from that backend for that type.
     *
     * @param int $id Item id.
     * @param \stdClass $data Fields to change (subset of FIELDS).
     * @param array|null $authors New author list, or null to keep the current one.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The stored item.
     */
    public static function update(int $id, \stdClass $data, ?array $authors = null, int $userid = 0): \stdClass {
        global $DB, $USER;
        $current = self::get($id);
        $changes = self::extract_fields($data);
        // The origin course is set once, when the item is uploaded from a course (adr30).
        unset($changes->origincourseid);
        $record = (object) array_merge((array) $current, (array) $changes);
        self::validate($record);
        self::require_context_change($current, (int) $record->contextid, $userid ?: (int) $USER->id);

        $hasversions = $DB->record_exists(version_manager::TABLE, ['itemid' => $id]);
        if ($hasversions && ($record->type !== $current->type || (int) $record->sourceid !== (int) $current->sourceid)) {
            throw new \moodle_exception('error_itemlocked', 'local_nextpool');
        }
        $authors = $authors === null ? null : self::validate_authors($authors);

        $changes->id = $id;
        $changes->usermodified = $userid ?: (int) $USER->id;
        $changes->timemodified = time();

        $transaction = $DB->start_delegated_transaction();
        $DB->update_record(self::TABLE, $changes);
        if ($authors !== null) {
            $DB->delete_records(self::AUTHOR_TABLE, ['itemid' => $id]);
            self::store_authors($id, $authors);
        }
        if ((int) $record->contextid !== (int) $current->contextid) {
            // Inside the transaction, so a failing move leaves the item where it was (bug63, Q-21).
            self::move_attached_data($id, (int) $current->contextid, (int) $record->contextid);
        }
        $transaction->allow_commit();

        $item = self::get($id);
        item_updated::create_from_item($item)->trigger();
        return $item;
    }

    /**
     * Check a change of the visibility context of an item (TD-403).
     *
     * An item never moves into a course context, and an item uploaded from a course leaves its
     * course context only for a context in which the user may review versions (q54). Other
     * changes are checked by the pages that call update().
     *
     * @param \stdClass $current Stored item.
     * @param int $tocontextid New visibility context.
     * @param int $userid Acting user.
     * @throws \moodle_exception If the change is not allowed.
     */
    private static function require_context_change(\stdClass $current, int $tocontextid, int $userid): void {
        if ($tocontextid === (int) $current->contextid) {
            return;
        }
        $to = \context::instance_by_id($tocontextid);
        if ($to->contextlevel == CONTEXT_COURSE) {
            throw new \moodle_exception('error_invalidcontext', 'local_nextpool');
        }
        $from = \context::instance_by_id((int) $current->contextid, IGNORE_MISSING);
        if ($from && $from->contextlevel == CONTEXT_COURSE && !has_capability(access::REVIEW, $to, $userid)) {
            throw new \required_capability_exception($to, access::REVIEW, 'nopermissions', '');
        }
    }

    /**
     * Move the items of course category contexts that are about to be deleted to another context (bug33).
     *
     * Core deletes a category context together with its files, so the items, their tags, unpacked packages and
     * custom field data must leave it before. Called from the category deletion callbacks in lib.php.
     *
     * @param int[] $fromcontextids Contexts whose items move.
     * @param \context $to New visibility context (system or course category).
     * @return int Number of moved items.
     */
    public static function move_items_from_contexts(array $fromcontextids, \context $to): int {
        global $DB;
        if (!$fromcontextids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($fromcontextids, SQL_PARAMS_NAMED);
        $items = $DB->get_records_select(self::TABLE, "contextid $insql AND contextid <> :to", $params + ['to' => $to->id]);
        foreach ($items as $item) {
            self::move_to_context($item, $to);
        }
        return count($items);
    }

    /**
     * Move items whose visibility context no longer exists to the system context (bug33).
     *
     * Safety net after a category deletion that bypassed the deletion callbacks; their files are lost by then.
     *
     * @return int Number of moved items.
     */
    public static function move_orphaned_items(): int {
        global $DB;
        $items = $DB->get_records_sql(
            'SELECT i.*
               FROM {' . self::TABLE . '} i
          LEFT JOIN {context} ctx ON ctx.id = i.contextid
              WHERE ctx.id IS NULL'
        );
        foreach ($items as $item) {
            self::move_to_context($item, \context_system::instance());
        }
        return count($items);
    }

    /**
     * Give one item a new visibility context, with everything stored in that context.
     *
     * @param \stdClass $item Item record.
     * @param \context $to New visibility context.
     */
    private static function move_to_context(\stdClass $item, \context $to): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->set_field(self::TABLE, 'contextid', $to->id, ['id' => $item->id]);
        self::move_attached_data((int) $item->id, (int) $item->contextid, (int) $to->id);
        $transaction->allow_commit();
        item_updated::create_from_item(self::get((int) $item->id))->trigger();
    }

    /**
     * Move tags, unpacked packages and custom field data of an item from one context to another.
     *
     * @param int $id Item id.
     * @param int $fromcontextid Previous visibility context (may no longer exist).
     * @param int $tocontextid New visibility context.
     */
    private static function move_attached_data(int $id, int $fromcontextid, int $tocontextid): void {
        \core_tag_tag::change_items_context('local_nextpool', self::TAG_ITEMTYPE, [$id], \context::instance_by_id($tocontextid));
        version_manager::move_packages($id, $fromcontextid, $tocontextid);
        \local_nextpool\customfield\item_handler::instance()->move_instance_data($id, $tocontextid);
    }

    /**
     * Replace the tags of an item (F10). Tags live in the collection of the catalogue.
     *
     * @param int $id Item id.
     * @param string[] $tags Tag names.
     */
    public static function set_tags(int $id, array $tags): void {
        $item = self::get($id);
        \core_tag_tag::set_item_tags('local_nextpool', self::TAG_ITEMTYPE, $id, access::get_context($item), $tags);
    }

    /**
     * Tag names of an item, sorted as core shows them.
     *
     * @param int $id Item id.
     * @return string[]
     */
    public static function get_tags(int $id): array {
        return array_values(\core_tag_tag::get_item_tags_array('local_nextpool', self::TAG_ITEMTYPE, $id));
    }

    /**
     * Change the editorial status of an item.
     *
     * Publishing requires a published current version. Submitting an item for review also submits its newest
     * draft version, so the review runs on that version (history, notification, review queue). Without such a draft
     * the item itself waits in the review queue, see review_queue::get_waiting_items_sql().
     *
     * @param int $id Item id.
     * @param string $newstatus Target status.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The stored item.
     */
    public static function set_status(int $id, string $newstatus, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $item = self::get($id);
        status::require_transition($item->status, $newstatus, true);
        if ($newstatus === status::PUBLISHED && empty($item->currentversionid)) {
            throw new \moodle_exception('error_nopublishedversion', 'local_nextpool');
        }
        $draft = $newstatus === status::REVIEW ? self::get_submittable_draft($id) : null;
        $transaction = $DB->start_delegated_transaction();
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => $newstatus,
            'usermodified' => $userid,
            'timemodified' => time(),
        ]);
        if ($draft) {
            version_manager::set_status((int) $draft->id, status::REVIEW, $userid);
        }
        $transaction->allow_commit();
        review_queue::invalidate();
        $item = self::get($id);
        item_updated::create_from_item($item)->trigger();
        return $item;
    }

    /**
     * The newest draft version an editor can submit for review together with the item.
     *
     * Proposals from courses are left out: only the person who proposed them submits them again (TD-302).
     *
     * @param int $id Item id.
     * @return \stdClass|null Version record, null without such a draft.
     */
    public static function get_submittable_draft(int $id): ?\stdClass {
        global $DB;
        $drafts = $DB->get_records_select(
            version_manager::TABLE,
            'itemid = :itemid AND status = :draft AND origin <> :proposal',
            ['itemid' => $id, 'draft' => status::DRAFT, 'proposal' => version_manager::ORIGIN_PROPOSAL],
            'id DESC',
            '*',
            0,
            1
        );
        return $drafts ? reset($drafts) : null;
    }

    /**
     * Delete an item that was never published, including its draft versions.
     *
     * Published or withdrawn items stay, because courses may reference them.
     *
     * @param int $id Item id.
     */
    public static function delete(int $id): void {
        global $DB;
        $item = self::get($id);
        $everpublished = $DB->record_exists_select(
            version_manager::TABLE,
            'itemid = :itemid AND timepublished > 0',
            ['itemid' => $id]
        );
        if ($item->status === status::PUBLISHED || $item->status === status::WITHDRAWN || $everpublished) {
            throw new \moodle_exception('error_itemnotdeletable', 'local_nextpool');
        }
        $event = item_deleted::create_from_item($item);
        $transaction = $DB->start_delegated_transaction();
        version_manager::delete_all_for_item($id);
        \core_tag_tag::remove_all_item_tags('local_nextpool', self::TAG_ITEMTYPE, $id);
        \local_nextpool\customfield\item_handler::instance()->delete_instance($id);
        item_competencies::delete_for_item($id);
        $DB->delete_records(self::AUTHOR_TABLE, ['itemid' => $id]);
        // Feedback, subscriptions and editors of the item (F08).
        $DB->delete_records('local_nextpool_comment', ['itemid' => $id]);
        $DB->delete_records('local_nextpool_subscription', ['itemid' => $id]);
        $DB->delete_records('local_nextpool_item_editor', ['itemid' => $id]);
        // Anonymous statistics (F15) go with the item.
        item_statistics::delete_for_item($id);
        $DB->delete_records(self::TABLE, ['id' => $id]);
        $transaction->allow_commit();
        $event->trigger();
    }

    /**
     * Load an item.
     *
     * @param int $id Item id.
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Check a requested sort of the editorial list against the whitelist (task168).
     *
     * An unknown key falls back to the default (last modified, newest first); an unknown direction to the
     * direction of the first click on that column (SORT_FIELDS).
     *
     * @param string $sort Requested sort key.
     * @param string $dir Requested direction.
     * @return string[] Valid sort key and direction.
     */
    public static function normalise_sort(string $sort, string $dir): array {
        if (!array_key_exists($sort, self::SORT_FIELDS)) {
            return [self::SORT_TIMEMODIFIED, self::SORT_FIELDS[self::SORT_TIMEMODIFIED]];
        }
        if ($dir !== self::SORT_ASC && $dir !== self::SORT_DESC) {
            $dir = self::SORT_FIELDS[$sort];
        }
        return [$sort, $dir];
    }

    /**
     * ORDER BY of the editorial list, built from the whitelist only (task168).
     *
     * Status sorts in workflow order (draft, review, published, withdrawn; status::all()), not by the internal
     * key. Title sorts case-insensitively. Category sorts by the full name as the list shows it ("Parent / Child",
     * filtered for the current language), mapped to a rank before the query; items without a category come last in
     * ascending order.
     * The item id is the second key, in the same direction, so equal values keep a stable order across pages.
     *
     * @param string $sort Sort key, see SORT_FIELDS.
     * @param string $dir SORT_ASC or SORT_DESC.
     * @return array SQL and its parameters.
     */
    private static function search_order(string $sort, string $dir): array {
        [$sort, $dir] = self::normalise_sort($sort, $dir);
        $direction = $dir === self::SORT_ASC ? 'ASC' : 'DESC';
        $params = [];
        switch ($sort) {
            case self::SORT_TITLE:
                // Case-insensitive on every database.
                $first = 'LOWER(i.title)';
                break;
            case self::SORT_STATUS:
                $cases = [];
                foreach (status::all(true) as $rank => $status) {
                    $cases[] = 'WHEN :sortstatus' . $rank . ' THEN ' . (int) $rank;
                    $params['sortstatus' . $rank] = $status;
                }
                $first = 'CASE i.status ' . implode(' ', $cases) . ' ELSE ' . count($cases) . ' END';
                break;
            case self::SORT_CATEGORY:
                $names = category_manager::get_menu();
                \core_collator::asort($names);
                $cases = [];
                foreach (array_keys($names) as $rank => $categoryid) {
                    $cases[] = 'WHEN ' . (int) $categoryid . ' THEN ' . (int) $rank;
                }
                // NULL matches no WHEN, so items without a category get the last rank.
                $first = $cases ? 'CASE i.categoryid ' . implode(' ', $cases) . ' ELSE ' . count($cases) . ' END' : '0';
                break;
            default:
                $first = 'i.timemodified';
        }
        return [$first . ' ' . $direction . ', i.id ' . $direction, $params];
    }

    /**
     * Items for the editorial list, limited to the given visibility contexts.
     *
     * @param int[] $contextids Visibility contexts the user may see, see access::get_editorial_contextids().
     * @param array $filters Optional 'status', 'categoryid' (-1 for none), 'search' (part of the title),
     *     'origin' (ORIGIN_COURSE for items uploaded from a course) and 'unplaced' (true for items without placement).
     * @param int $limitfrom First record.
     * @param int $limitnum Number of records, 0 for all.
     * @param string $sort Sort key (SORT_FIELDS); unknown or empty: last modified, newest first (task168).
     * @param string $dir SORT_ASC or SORT_DESC; unknown or empty: the default direction of the sort key.
     * @return \stdClass[] Item records with currentversionnr, sourcename, reviewcount (versions waiting for review,
     *     as in review_queue), placementcount and the latest numbered version (latestversionid, latestversionnr,
     *     latestversionstatus; null without version, task102).
     */
    public static function search(
        array $contextids,
        array $filters = [],
        int $limitfrom = 0,
        int $limitnum = 0,
        string $sort = '',
        string $dir = ''
    ): array {
        global $DB;
        if (!$contextids) {
            return [];
        }
        [$wheresql, $params] = self::search_where($contextids, $filters);
        [$ordersql, $orderparams] = self::search_order($sort, $dir);
        // The same condition as the review queue, so the list marks every version that waits for review,
        // including drafts from the storage location.
        [$waitingsql, $waitingparams] = review_queue::get_waiting_sql('r', 'lsrq');
        return $DB->get_records_sql(
            'SELECT i.*, v.versionnr AS currentversionnr, s.name AS sourcename,
                    (SELECT COUNT(1) FROM {' . version_manager::TABLE . '} r
                      WHERE r.itemid = i.id AND ' . $waitingsql . ') AS reviewcount,
                    (SELECT COUNT(1) FROM {' . placement_manager::TABLE . '} pc
                      WHERE pc.itemid = i.id) AS placementcount,
                    lv.id AS latestversionid, lv.versionnr AS latestversionnr, lv.status AS latestversionstatus
               FROM {' . self::TABLE . '} i
          LEFT JOIN {' . version_manager::TABLE . '} v ON v.id = i.currentversionid
          LEFT JOIN {' . version_manager::TABLE . '} lv ON lv.itemid = i.id
                    AND lv.versionnr = (SELECT MAX(lm.versionnr) FROM {' . version_manager::TABLE . '} lm
                                         WHERE lm.itemid = i.id)
          LEFT JOIN {local_nextpool_source} s ON s.id = i.sourceid
              WHERE ' . $wheresql . '
           ORDER BY ' . $ordersql,
            $params + $orderparams + $waitingparams,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * Number of items search() finds without paging.
     *
     * @param int[] $contextids Visibility contexts the user may see.
     * @param array $filters See search().
     * @return int
     */
    public static function count_search(array $contextids, array $filters = []): int {
        global $DB;
        if (!$contextids) {
            return 0;
        }
        [$wheresql, $params] = self::search_where($contextids, $filters);
        return $DB->count_records_sql('SELECT COUNT(1) FROM {' . self::TABLE . "} i WHERE $wheresql", $params);
    }

    /**
     * WHERE clause of the editorial list.
     *
     * Items in a course context (uploaded from a course, adr30) belong to the editors of the
     * categories above that course and of the site (TD-402).
     *
     * @param int[] $contextids Visibility contexts, not empty.
     * @param array $filters See search().
     * @return array SQL and its parameters.
     */
    private static function search_where(array $contextids, array $filters): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $coursecontexts = [];
        foreach (array_values($contextids) as $i => $contextid) {
            $context = \context::instance_by_id((int) $contextid, IGNORE_MISSING);
            if ($context) {
                $coursecontexts[] = $DB->sql_like('cc.path', ':cpath' . $i, true, false);
                $params['cpath' . $i] = $DB->sql_like_escape($context->path) . '/%';
            }
        }
        $contextwhere = 'i.contextid ' . $insql;
        if ($coursecontexts) {
            $contextwhere = '(' . $contextwhere . ' OR EXISTS (SELECT 1 FROM {context} cc
                                 WHERE cc.id = i.contextid AND cc.contextlevel = :courselevel
                                       AND (' . implode(' OR ', $coursecontexts) . ')))';
            $params['courselevel'] = CONTEXT_COURSE;
        }
        $where = [$contextwhere];
        if (($filters['origin'] ?? '') === self::ORIGIN_COURSE) {
            $where[] = 'i.origincourseid IS NOT NULL';
        }
        if (!empty($filters['unplaced'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {' . placement_manager::TABLE . '} up WHERE up.itemid = i.id)';
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $where[] = 'i.status = :status';
            $params['status'] = $status;
        }
        $categoryid = (int) ($filters['categoryid'] ?? 0);
        if ($categoryid > 0) {
            $where[] = 'i.categoryid = :categoryid';
            $params['categoryid'] = $categoryid;
        } else if ($categoryid < 0) {
            $where[] = 'i.categoryid IS NULL';
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = $DB->sql_like('i.title', ':search', false, false);
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Load an item by its stable identifier.
     *
     * @param string $uuid Item UUID.
     * @return \stdClass|null
     */
    public static function get_by_uuid(string $uuid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['uuid' => $uuid]) ?: null;
    }

    /**
     * Authors of an item, in display order.
     *
     * @param int $itemid Item id.
     * @return \stdClass[]
     */
    public static function get_authors(int $itemid): array {
        global $DB;
        return array_values($DB->get_records(self::AUTHOR_TABLE, ['itemid' => $itemid], 'sortorder, id'));
    }

    /**
     * Names of the authors of an item, in display order (bug63, Q-13).
     *
     * An author with an account shows the current full name of that user; an account that no longer
     * exists and an empty free-text name are left out. Names and roles are plain text, not escaped.
     *
     * @param int $itemid Item id.
     * @return array[] Each with name (string) and role (string, null if none).
     */
    public static function get_author_names(int $itemid): array {
        global $DB;
        $authors = self::get_authors($itemid);
        $userids = array_values(array_unique(array_filter(array_map(static fn($a) => (int) $a->userid, $authors))));
        $userfields = 'id' . \core_user\fields::for_name()->get_sql()->selects;
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', $userfields) : [];
        $result = [];
        foreach ($authors as $author) {
            if ($author->userid) {
                $name = isset($users[$author->userid]) ? fullname($users[$author->userid]) : '';
            } else {
                $name = (string) $author->name;
            }
            if ($name !== '') {
                $result[] = ['name' => $name, 'role' => (string) $author->role !== '' ? (string) $author->role : null];
            }
        }
        return $result;
    }

    /**
     * Copy the known metadata fields from input data.
     *
     * @param \stdClass $data Input.
     * @return \stdClass
     */
    private static function extract_fields(\stdClass $data): \stdClass {
        $record = new \stdClass();
        foreach (self::FIELDS as $field) {
            if (property_exists($data, $field)) {
                $record->$field = $data->$field;
            }
        }
        foreach (['title', 'type', 'language', 'audience', 'origin', 'license'] as $field) {
            if (isset($record->$field)) {
                $record->$field = trim((string) $record->$field);
            }
        }
        if (isset($record->categoryid) && empty($record->categoryid)) {
            $record->categoryid = null;
        }
        if (property_exists($record, 'origincourseid')) {
            $record->origincourseid = empty($record->origincourseid) ? null : (int) $record->origincourseid;
        }
        return $record;
    }

    /**
     * Validate item metadata.
     *
     * @param \stdClass $record Complete item record.
     * @throws \moodle_exception On the first invalid field.
     */
    private static function validate(\stdClass $record): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/licenselib.php');
        if (($record->title ?? '') === '') {
            throw new \moodle_exception('error_required', 'local_nextpool', '', get_string('title', 'local_nextpool'));
        }
        if (!item_types::is_valid($record->type ?? '')) {
            throw new \moodle_exception('error_invalidtype', 'local_nextpool');
        }
        if (($record->license ?? '') === '' || \license_manager::get_license_by_shortname($record->license) === null) {
            throw new \moodle_exception('error_invalidlicense', 'local_nextpool');
        }
        $language = $record->language ?? '';
        if ($language !== '' && !array_key_exists($language, get_string_manager()->get_list_of_languages())) {
            throw new \moodle_exception('error_invalidlanguage', 'local_nextpool');
        }
        if (isset($record->duration) && (int) $record->duration < 0) {
            throw new \moodle_exception('error_invalidduration', 'local_nextpool');
        }
        if (empty($record->sourceid) || !$DB->record_exists('local_nextpool_source', ['id' => $record->sourceid])) {
            throw new \moodle_exception('error_invalidsource', 'local_nextpool');
        }
        if (!empty($record->categoryid) && !$DB->record_exists('local_nextpool_category', ['id' => $record->categoryid])) {
            throw new \moodle_exception('error_invalidcategory', 'local_nextpool');
        }
        $context = \context::instance_by_id((int) $record->contextid, IGNORE_MISSING);
        if (!$context || !in_array($context->contextlevel, [CONTEXT_SYSTEM, CONTEXT_COURSECAT, CONTEXT_COURSE], true)) {
            throw new \moodle_exception('error_invalidcontext', 'local_nextpool');
        }
        // Only xAPI packages uploaded from that very course live in a course context (adr30, TD-401).
        if (
            $context->contextlevel == CONTEXT_COURSE
            && (!in_array($record->type ?? '', direct_upload::get_types(), true)
                || (int) ($record->origincourseid ?? 0) !== (int) $context->instanceid)
        ) {
            throw new \moodle_exception('error_invalidcontext', 'local_nextpool');
        }
    }

    /**
     * Validate and normalise an author list.
     *
     * @param array $authors Raw author entries.
     * @return array<int,array{userid:int|null,name:string|null,role:string|null}>
     * @throws \moodle_exception If empty or an entry has neither user nor name.
     */
    private static function validate_authors(array $authors): array {
        global $DB;
        $clean = [];
        foreach ($authors as $author) {
            $author = (array) $author;
            $userid = empty($author['userid']) ? null : (int) $author['userid'];
            $name = trim((string) ($author['name'] ?? ''));
            $role = trim((string) ($author['role'] ?? ''));
            if ($userid === null && $name === '') {
                continue;
            }
            if ($userid !== null && !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
                throw new \moodle_exception('error_invalidauthor', 'local_nextpool');
            }
            $clean[] = [
                'userid' => $userid,
                // A Moodle user's name is resolved at display time, not copied.
                'name' => $userid === null ? $name : null,
                'role' => $role === '' ? null : $role,
            ];
        }
        if (!$clean) {
            throw new \moodle_exception('error_noauthor', 'local_nextpool');
        }
        return $clean;
    }

    /**
     * Insert author rows.
     *
     * @param int $itemid Item id.
     * @param array $authors Normalised authors.
     */
    private static function store_authors(int $itemid, array $authors): void {
        global $DB;
        foreach (array_values($authors) as $sortorder => $author) {
            $DB->insert_record(self::AUTHOR_TABLE, (object) ($author + ['itemid' => $itemid, 'sortorder' => $sortorder]));
        }
    }
}
