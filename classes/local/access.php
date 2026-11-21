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
 * Editorial permissions on catalogue items.
 *
 * Capabilities are checked in the item's visibility context (system or course
 * category), so a category-level role can be given editorial rights for the
 * items of that category only.
 *
 * - manageitems: create and edit items, import versions, submit for review.
 *   Item editors (F08, adr25) may do the same for their items only, except deleting the item and adding editors.
 * - reviewversions: publish, withdraw and send back to draft.
 * - proposeversions (course): propose new versions from a course (F07, q62).
 *
 * Review decisions on a version in review (publish, return, reject) follow
 * {@see can_review()}: for items available site-wide, the review capability in
 * any course category is enough (TD-310, q77).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string Capability to create and edit items. */
    public const MANAGE = 'local/nextpool:manageitems';
    /** @var string Capability to review and publish. */
    public const REVIEW = 'local/nextpool:reviewversions';
    /** @var string Capability to propose new versions from a course (q62). */
    public const PROPOSE = 'local/nextpool:proposeversions';

    /** @var array<string,string[]> Capabilities allowing a status change into a status (any of them). */
    private const STATUS_CAPABILITIES = [
        status::REVIEW => [self::MANAGE],
        status::DRAFT => [self::MANAGE, self::REVIEW],
        status::PUBLISHED => [self::REVIEW],
        status::WITHDRAWN => [self::REVIEW],
        status::REJECTED => [self::REVIEW],
    ];

    /** @var string[] Targets of a version in review that are review decisions (TD-310). */
    private const REVIEW_DECISIONS = [status::PUBLISHED, status::DRAFT, status::REJECTED];

    /**
     * Visibility context of an item.
     *
     * Falls back to the system context if the item's context is gone, e.g. while its course category is being
     * deleted (bug33): only system-wide editors and reviewers reach such an item, and nothing throws.
     *
     * @param \stdClass $item Item record.
     * @return \context
     */
    public static function get_context(\stdClass $item): \context {
        return \context::instance_by_id((int) $item->contextid, IGNORE_MISSING) ?: \context_system::instance();
    }

    /**
     * Contexts in which the current user holds any of the given capabilities.
     *
     * Items live in the system context or a course category context, so these
     * are the contexts whose items the user may see in the editorial pages.
     *
     * @param string[] $capabilities Capabilities, any of them is enough.
     * @return int[] Context ids.
     */
    public static function get_editorial_contextids(array $capabilities = [self::MANAGE, self::REVIEW]): array {
        $contextids = [];
        $system = \context_system::instance();
        if (has_any_capability($capabilities, $system)) {
            $contextids[$system->id] = (int) $system->id;
        }
        foreach ($capabilities as $capability) {
            foreach (array_keys(\core_course_category::make_categories_list($capability)) as $categoryid) {
                $context = \context_coursecat::instance((int) $categoryid);
                $contextids[$context->id] = (int) $context->id;
            }
        }
        return array_values($contextids);
    }

    /**
     * Whether the current user may use the editorial pages at all.
     *
     * @return bool
     */
    public static function can_use_editorial(): bool {
        return (bool) self::get_editorial_contextids();
    }

    /**
     * Whether the current user may create items, in the system context or in at least one category.
     *
     * @return bool
     */
    public static function can_create(): bool {
        return (bool) self::get_editorial_contextids([self::MANAGE]);
    }

    /**
     * Throw unless the current user may use the editorial pages.
     *
     * @throws \required_capability_exception
     */
    public static function require_editorial(): void {
        if (!self::can_use_editorial()) {
            throw new \required_capability_exception(\context_system::instance(), self::MANAGE, 'nopermissions', '');
        }
    }

    /**
     * Throw unless the current user may create items.
     *
     * @throws \required_capability_exception
     */
    public static function require_create(): void {
        if (!self::can_create()) {
            throw new \required_capability_exception(\context_system::instance(), self::MANAGE, 'nopermissions', '');
        }
    }

    /**
     * Whether the user may see the item in the editorial pages: the editorial team, reviewers and its item editors.
     *
     * Reviewers include everyone who may review the item ({@see can_review()}): for an item available site-wide,
     * reviewers of any course category, who are offered its reviews in the overview (TD-310, task85).
     *
     * @param \stdClass $item Item record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_view(\stdClass $item, ?int $userid = null): bool {
        return has_any_capability([self::MANAGE, self::REVIEW], self::get_context($item), $userid)
            || self::is_item_editor($item, $userid)
            || self::can_review($item, $userid);
    }

    /**
     * Whether the user may edit the item's metadata, import versions and submit drafts (F08.AC08, adr25).
     *
     * The edit right in the item's context, or an entry as item editor of this item.
     *
     * @param \stdClass $item Item record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_edit(\stdClass $item, ?int $userid = null): bool {
        return self::can_manage($item, $userid) || self::is_item_editor($item, $userid);
    }

    /**
     * Whether the user may import a file of the storage location as a new version of the item (F08, q68, q69).
     *
     * The editorial team may import any file below the base folder of the item's storage location and browse it.
     * Item editors work on their item only (F08.AC08): they may import the file of the latest numbered version again
     * and write an upload to that path, nothing else. Without a version, the editorial team imports the first one.
     *
     * @param \stdClass $item Item record.
     * @param string $path Normalised path relative to the base folder of the storage location.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_import_path(\stdClass $item, string $path, ?int $userid = null): bool {
        if (self::can_manage($item, $userid)) {
            return true;
        }
        if (!self::is_item_editor($item, $userid)) {
            return false;
        }
        $latest = version_manager::get_latest((int) $item->id);
        return $latest !== null && $path !== '' && (string) $latest->sourceref === $path;
    }

    /**
     * Throw unless the user may import the file at the path as a new version of the item.
     *
     * @param \stdClass $item Item record.
     * @param string $path Normalised path relative to the base folder of the storage location.
     * @throws \required_capability_exception
     */
    public static function require_import_path(\stdClass $item, string $path): void {
        if (!self::can_import_path($item, $path)) {
            throw new \required_capability_exception(self::get_context($item), self::MANAGE, 'nopermissions', '');
        }
    }

    /**
     * Whether the user holds the edit right in the item's context (editorial team).
     *
     * Needed for what item editors may not do: delete the item, add or remove item editors (q68), widen the
     * availability and remove other people's comments.
     *
     * @param \stdClass $item Item record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_manage(\stdClass $item, ?int $userid = null): bool {
        return has_capability(self::MANAGE, self::get_context($item), $userid);
    }

    /**
     * Whether the user may move an item or version from one status to another.
     *
     * Checks both the workflow and the capability, not item-specific
     * preconditions such as "a published version exists". With a version, review decisions on it follow
     * {@see can_review()} (TD-310) and only the person who proposed it may cancel a proposal (TD-302);
     * without one, the item's statuses and the capabilities in its context apply.
     *
     * @param \stdClass $item Item record (gives the context).
     * @param string $from Current status.
     * @param string $to Target status.
     * @param int|null $userid User, null for the current user.
     * @param \stdClass|null $version The version whose status changes, null for the item.
     * @return bool
     */
    public static function can_change_status(
        \stdClass $item,
        string $from,
        string $to,
        ?int $userid = null,
        ?\stdClass $version = null
    ): bool {
        if (!status::can_transition($from, $to, $version === null)) {
            return false;
        }
        if ($version !== null) {
            if ($to === status::CANCELLED) {
                global $USER;
                return ($version->origin ?? '') === version_manager::ORIGIN_PROPOSAL
                    && (int) $version->createdby === (int) ($userid ?? $USER->id);
            }
            if ($from === status::REVIEW && in_array($to, self::REVIEW_DECISIONS, true)) {
                return self::can_review($item, $userid);
            }
        }
        if (has_any_capability(self::STATUS_CAPABILITIES[$to] ?? [], self::get_context($item), $userid)) {
            return true;
        }
        // Item editors submit drafts for review, nothing else (q68, F08.AC08).
        return $to === status::REVIEW && self::is_item_editor($item, $userid);
    }

    /**
     * Whether the user may decide on versions of an item in review and take or be given its reviews (TD-310).
     *
     * The review capability in the item's context is enough; for an item available site-wide, the review
     * capability in any course category is enough too (q77). Withdrawing published versions, widening the
     * availability and adding item editors stay with the capability in the item's context.
     *
     * @param \stdClass $item Item record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_review(\stdClass $item, ?int $userid = null): bool {
        $context = self::get_context($item);
        if (has_capability(self::REVIEW, $context, $userid)) {
            return true;
        }
        return $context->contextlevel == CONTEXT_SYSTEM && self::reviews_anywhere($userid);
    }

    /**
     * People who may review an item, for the assignment list (TD-310).
     *
     * Holders of the review capability in the item's context; for an item available site-wide also everyone
     * with a role that has the review capability in a course category, if they hold it there.
     *
     * @param \stdClass $item Item record.
     * @return \stdClass[] User records (id and name fields), keyed by id, sorted by name.
     */
    public static function get_reviewers(\stdClass $item): array {
        global $DB;
        $context = self::get_context($item);
        $userids = array_keys(get_users_by_capability($context, self::REVIEW, 'u.id'));
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            // One query per course category where the right may be given, not per person (bug111): categories with an
            // assignment of a role that has the right, or with an override that allows it.
            $roleids = array_keys(get_roles_with_capability(self::REVIEW, CAP_ALLOW));
            [$rolesql, $params] = $roleids ? $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role') : ['= 0', []];
            $categoryids = $DB->get_fieldset_sql(
                "SELECT ctx.id
                   FROM {context} ctx
                  WHERE ctx.contextlevel = :category
                        AND (EXISTS (SELECT 1 FROM {role_assignments} ra WHERE ra.contextid = ctx.id AND ra.roleid $rolesql)
                             OR EXISTS (SELECT 1 FROM {role_capabilities} rc
                                         WHERE rc.contextid = ctx.id AND rc.capability = :capability
                                               AND rc.permission = :allow))",
                $params + ['category' => CONTEXT_COURSECAT, 'capability' => self::REVIEW, 'allow' => CAP_ALLOW]
            );
            foreach ($categoryids as $categoryid) {
                $category = \context::instance_by_id((int) $categoryid, IGNORE_MISSING);
                if ($category) {
                    $userids = array_merge($userids, array_keys(get_users_by_capability($category, self::REVIEW, 'u.id')));
                }
            }
            $userids = array_values(array_unique(array_map('intval', $userids)));
        }
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_select('user', "id $insql AND deleted = 0", $params, '', 'id, ' . $fields);
        $names = array_map(static fn($user) => fullname($user), $users);
        \core_collator::asort($names);
        return array_replace($names, $users);
    }

    /**
     * Whether a user holds the review capability in the system context or in any course category.
     *
     * Cached for the request (TD-310).
     *
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    private static function reviews_anywhere(?int $userid = null): bool {
        global $USER;
        $userid = (int) ($userid ?? $USER->id);
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'local_nextpool', 'reviewsanywhere');
        $cached = $cache->get($userid);
        if ($cached !== false) {
            return (bool) $cached;
        }
        $result = has_capability(self::REVIEW, \context_system::instance(), $userid);
        if (!$result) {
            [$categories] = get_user_capability_contexts(self::REVIEW, true, $userid, true, '', '', '', '', 1);
            $result = !empty($categories);
        }
        $cache->set($userid, (int) $result);
        return $result;
    }

    /**
     * Whether the user is recorded as an editor of the item (F08, adr25).
     *
     * The editorial team adds them on the item page (task84, {@see item_editor_manager}).
     *
     * @param \stdClass $item Item record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function is_item_editor(\stdClass $item, ?int $userid = null): bool {
        global $DB, $USER;
        return $DB->record_exists('local_nextpool_item_editor', ['itemid' => $item->id, 'userid' => $userid ?? $USER->id]);
    }

    /**
     * Whether the user may read and write feedback on an item (F08, TD-304, q63).
     *
     * From a course (courseid > 0): the catalogue capability in the course and the item available there, the same
     * rule as browsing the catalogue. Without a course: the editorial team, reviewers and item editors.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_comment(\stdClass $item, int $courseid = 0, ?int $userid = null): bool {
        if ($courseid > 0) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            return $context && has_capability('local/nextpool:viewcatalogue', $context, $userid)
                && catalogue::is_available_in_course($item, $courseid);
        }
        return self::can_view($item, $userid) || self::is_item_editor($item, $userid);
    }

    /**
     * Whether a catalogue item can be previewed in a course (F16 p176-f).
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course id.
     * @param int|null $userid User, null for current user.
     * @return bool
     */
    public static function can_preview(\stdClass $item, int $courseid, ?int $userid = null): bool {
        return $courseid > 0 && self::can_comment($item, $courseid, $userid);
    }

    /**
     * Require the course catalogue permission and availability for a preview.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course id.
     * @throws \required_capability_exception
     */
    public static function require_preview(\stdClass $item, int $courseid): void {
        if (!self::can_preview($item, $courseid)) {
            throw new \required_capability_exception(
                \context_course::instance($courseid),
                'local/nextpool:viewcatalogue',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the user may propose a new version of an item from a course (F07, TD-304, q62, F07.AC18).
     *
     * The capability to propose versions in the course and the item available there, the same rule as
     * browsing the catalogue. Items uploaded directly and still available only in their course take new versions in
     * the activity settings, without review (M06.AC08, t82-d, bug105).
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course the proposal comes from.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_propose(\stdClass $item, int $courseid, ?int $userid = null): bool {
        if (self::get_context($item)->contextlevel == CONTEXT_COURSE) {
            return false;
        }
        $context = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        return $context && has_capability(self::PROPOSE, $context, $userid)
            && catalogue::is_available_in_course($item, $courseid);
    }

    /**
     * Throw unless the user may read and write feedback on an item.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @throws \required_capability_exception
     */
    public static function require_comment(\stdClass $item, int $courseid = 0): void {
        if (!self::can_comment($item, $courseid)) {
            $context = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : null;
            throw new \required_capability_exception(
                $context ?: self::get_context($item),
                $courseid > 0 ? 'local/nextpool:viewcatalogue' : self::MANAGE,
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the user may mark a comment as done or open it again (F08.AC03).
     *
     * The author, item editors and the editorial team (edit right in the item's context).
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $comment Comment record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_resolve_comment(\stdClass $item, \stdClass $comment, ?int $userid = null): bool {
        global $USER;
        if (!empty($comment->deleted)) {
            return false;
        }
        return (int) $comment->userid === (int) ($userid ?? $USER->id) || self::can_edit($item, $userid);
    }

    /**
     * Whether the user may remove a comment: their own, or any as the editorial team (F08, q70).
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $comment Comment record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_delete_comment(\stdClass $item, \stdClass $comment, ?int $userid = null): bool {
        global $USER;
        if (!empty($comment->deleted)) {
            return false;
        }
        return (int) $comment->userid === (int) ($userid ?? $USER->id) || self::can_manage($item, $userid);
    }

    /**
     * Whether the user may change the text of a comment: only their own (F08 "Ändern").
     *
     * @param \stdClass $comment Comment record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_update_comment(\stdClass $comment, ?int $userid = null): bool {
        global $USER;
        return empty($comment->deleted) && (int) $comment->userid === (int) ($userid ?? $USER->id);
    }

    /**
     * Throw unless the user may see the item.
     *
     * @param \stdClass $item Item record.
     * @throws \required_capability_exception
     */
    public static function require_view(\stdClass $item): void {
        if (!self::can_view($item)) {
            throw new \required_capability_exception(self::get_context($item), self::MANAGE, 'nopermissions', '');
        }
    }

    /**
     * Throw unless the user may edit the item (editorial team or item editor).
     *
     * @param \stdClass $item Item record.
     * @throws \required_capability_exception
     */
    public static function require_edit(\stdClass $item): void {
        if (!self::can_edit($item)) {
            throw new \required_capability_exception(self::get_context($item), self::MANAGE, 'nopermissions', '');
        }
    }

    /**
     * Throw unless the user holds the edit right in the item's context.
     *
     * @param \stdClass $item Item record.
     * @throws \required_capability_exception
     */
    public static function require_manage(\stdClass $item): void {
        require_capability(self::MANAGE, self::get_context($item));
    }

    /**
     * Throw unless the user may make the status change.
     *
     * @param \stdClass $item Item record.
     * @param string $from Current status.
     * @param string $to Target status.
     * @param \stdClass|null $version The version whose status changes, null for the item.
     * @throws \moodle_exception
     */
    public static function require_change_status(
        \stdClass $item,
        string $from,
        string $to,
        ?\stdClass $version = null
    ): void {
        status::require_transition($from, $to, $version === null);
        if (!self::can_change_status($item, $from, $to, null, $version)) {
            throw new \required_capability_exception(
                self::get_context($item),
                self::STATUS_CAPABILITIES[$to][0] ?? self::PROPOSE,
                'nopermissions',
                ''
            );
        }
    }
}
