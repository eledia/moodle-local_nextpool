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

use local_nextpool\local\source\entry;
use local_nextpool\local\source\manager as source_manager;

/**
 * Proposals from a course: a new version of an item or a new item, followed by the person who proposed it (F07, task82).
 *
 * A proposal is a version with origin "proposal" (TD-301). It goes into review at once, has no number until it is
 * published (q67), changes no placement and lives only as snapshot in Moodle: publishing writes it into the storage
 * location ({@see review_manager::publish()}). The person who proposed it can change and resubmit it after it was
 * returned, and cancel it until a reviewer decides.
 *
 * Domain layer like {@see version_manager}: callers check {@see access::can_propose()} and
 * {@see can_propose_item()}; only the person who proposed a version may change or cancel it, which is checked here.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class proposal_manager {
    /** @var string Table of the comments a proposal takes up. */
    public const LINK_TABLE = 'local_nextpool_comment_link';
    /** @var int Months a rejected or cancelled proposal is kept (q67). */
    public const RETENTION_MONTHS = 12;

    /**
     * Type of a new item proposed from an activity: the placeable type whose handler creates this kind of activity.
     *
     * @param \cm_info $cm Course module.
     * @return string|null Null if the catalogue cannot take this kind of activity.
     */
    public static function get_item_type(\cm_info $cm): ?string {
        foreach (item_types::all() as $type) {
            $handler = item_types::get_handler($type);
            if ($handler !== null && $handler->get_modname() === $cm->modname) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Whether the user may propose an activity as a new item (F07.AC10, q65).
     *
     * The capability to propose versions in the course, an activity the user can see that does not come from the
     * catalogue (activities with a placement get "Propose a new version") and a kind of activity the catalogue can take.
     *
     * @param \cm_info $cm Course module.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_propose_item(\cm_info $cm, ?int $userid = null): bool {
        $context = \context_course::instance((int) $cm->course, IGNORE_MISSING);
        if (!$context || !has_capability(access::PROPOSE, $context, $userid) || !empty($cm->deletioninprogress)) {
            return false;
        }
        return placement_manager::get_by_cmid((int) $cm->id) === null && self::get_item_type($cm) !== null;
    }

    /**
     * Whether a returned proposal may be submitted again with the user's current course rights (bug135).
     *
     * A proposed new item has not been published yet, so catalogue availability cannot be required for that case.
     *
     * @param \stdClass $version Returned proposal.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_resubmit(\stdClass $version, ?int $userid = null): bool {
        $courseid = (int) $version->courseid;
        if (!$courseid || !self::is_own($version, $userid) || $version->status !== status::DRAFT) {
            return false;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || !has_capability(access::PROPOSE, $context, $userid)) {
            return false;
        }
        $item = item_manager::get((int) $version->itemid);
        if (!empty($item->currentversionid) || !empty($version->basedonversionid)) {
            return access::can_propose($item, $courseid, $userid);
        }
        return in_array($item->status, [status::DRAFT, status::REVIEW], true)
            && in_array((int) $item->contextid, catalogue::get_visibility_contextids($courseid), true);
    }

    /**
     * Version a proposal builds on: the one applied in the activity, else the item's current version (F07).
     *
     * @param \stdClass $item Item record.
     * @param int $cmid Activity the proposal comes from, 0 if none.
     * @return int Version id, 0 if the item has none.
     */
    public static function get_base_versionid(\stdClass $item, int $cmid = 0): int {
        $placement = $cmid ? placement_manager::get_by_cmid($cmid) : null;
        if ($placement !== null && (int) $placement->itemid === (int) $item->id && !empty($placement->appliedversionid)) {
            return (int) $placement->appliedversionid;
        }
        return (int) ($item->currentversionid ?? 0);
    }

    /**
     * Check a proposed file with the rules of the import (F07.AC02): the type's handler and "unchanged".
     *
     * @param string $type Item type.
     * @param string $localfile Absolute path of the file.
     * @param string $filename Name of the file.
     * @param int $itemid Item id for a new version, 0 for a new item.
     * @throws \moodle_exception With the reason, e.g. error_unchanged or the handler's error.
     */
    public static function check_file(string $type, string $localfile, string $filename, int $itemid = 0): void {
        if (clean_param($filename, PARAM_FILE) === '') {
            throw new \moodle_exception('error_invalidpath', 'local_nextpool');
        }
        $latest = $itemid ? version_manager::get_latest($itemid) : null;
        if ($latest && $latest->contenthash === sha1_file($localfile) && $latest->filename === clean_param($filename, PARAM_FILE)) {
            throw new \moodle_exception('error_unchanged', 'local_nextpool');
        }
        item_types::get_handler($type)?->validate_package($localfile);
    }

    /**
     * Whether the content of an activity can be taken over as the content of a proposal (F07, q64, task83).
     *
     * The current user must be able to manage the activity: its content (e.g. questions with their answers) becomes a
     * file they can download (bug108).
     *
     * @param \cm_info $cm Course module.
     * @param string $type Item type of the proposal.
     * @return bool
     */
    public static function can_extract(\cm_info $cm, string $type): bool {
        $handler = item_types::get_handler($type);
        return $handler !== null && $handler->supports_extract() && $handler->get_modname() === $cm->modname
            && has_capability('moodle/course:manageactivities', \context_module::instance($cm->id));
    }

    /**
     * The activity of a course a proposal of an item can take over: the item's only placement there ((t83-g)).
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course id.
     * @return \cm_info|null Null if the item is placed not once in the course, or the type cannot take it over.
     */
    public static function get_extract_cm(\stdClass $item, int $courseid): ?\cm_info {
        if (!$courseid) {
            return null;
        }
        $placements = array_filter(
            placement_manager::get_for_course($courseid),
            static fn($placement) => (int) $placement->itemid === (int) $item->id
        );
        if (count($placements) !== 1) {
            return null;
        }
        $cm = get_fast_modinfo($courseid)->get_cms()[(int) reset($placements)->cmid] ?? null;
        return $cm !== null && $cm->uservisible && empty($cm->deletioninprogress) && self::can_extract($cm, (string) $item->type)
            ? $cm : null;
    }

    /**
     * Take over an activity as the file of a proposal and check it with the rules of the import (F07, task83).
     *
     * The file has the name of the item's latest version with the extension of the format written, so publishing
     * keeps the name in the storage location; a new item gets the name of the activity ((t83-e)).
     *
     * @param \cm_info $cm Activity to take over.
     * @param string $type Item type of the proposal.
     * @param int $itemid Item id for a new version, 0 for a new item.
     * @return array{0:string,1:string} Absolute path of the file and its name.
     * @throws \moodle_exception With the reason if the activity cannot be taken over or the file is refused.
     */
    public static function extract(\cm_info $cm, string $type, int $itemid = 0): array {
        if (!self::can_extract($cm, $type)) {
            throw new \moodle_exception('error_extractnotsupported', 'local_nextpool');
        }
        $localfile = make_request_directory() . '/extract';
        $filename = item_types::get_handler($type)->extract($cm, $localfile);
        $latest = $itemid ? version_manager::get_latest($itemid) : null;
        if ($latest !== null && (string) $latest->filename !== '') {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = pathinfo((string) $latest->filename, PATHINFO_FILENAME) . ($extension !== '' ? '.' . $extension : '');
        }
        self::check_file($type, $localfile, $filename, $itemid);
        return [$localfile, $filename];
    }

    /**
     * Propose a new version of an item from a course (F07.AC01, AC09).
     *
     * @param int $itemid Item id.
     * @param int $courseid Course of the proposal.
     * @param string $localfile Absolute path of the proposed file.
     * @param string $filename Name of the proposed file.
     * @param string $changelog Description of the change, required; becomes the change log.
     * @param int $changelogformat Format of the description.
     * @param int[] $commentids Comments of the item the proposal takes up.
     * @param int $cmid Activity the proposal comes from, 0 if none (gives the base version).
     * @param int $userid Proposing user, 0 for the current user.
     * @return \stdClass The version, in review.
     * @throws \moodle_exception If the description is missing or the file is refused.
     */
    public static function propose_version(
        int $itemid,
        int $courseid,
        string $localfile,
        string $filename,
        string $changelog,
        int $changelogformat = FORMAT_HTML,
        array $commentids = [],
        int $cmid = 0,
        int $userid = 0
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        self::require_description($changelog);
        $item = item_manager::get($itemid);

        $transaction = $DB->start_delegated_transaction();
        $version = version_manager::create_from_file($itemid, $localfile, $filename, new entry(''), [
            'origin' => version_manager::ORIGIN_PROPOSAL,
            'userid' => $userid,
            'courseid' => $courseid,
            'basedonversionid' => self::get_base_versionid($item, $cmid),
            'changelog' => $changelog,
            'changelogformat' => $changelogformat,
        ]);
        self::set_linked_comments((int) $version->id, $commentids);
        $version = version_manager::set_status((int) $version->id, status::REVIEW, $userid);
        subscription_manager::subscribe_automatically($itemid, $userid, $courseid);
        $transaction->allow_commit();

        self::notify_subscribers($item, $version, $userid);
        return $version;
    }

    /**
     * Propose an activity of a course as a new item (F07.AC10, q65).
     *
     * The item is created in review in the course category of the course and is not in any course catalogue until
     * a reviewer publishes it; its storage location is chosen then (q75). The item lives in the storage location for
     * direct uploads until then, or in the first enabled one.
     *
     * @param \cm_info $cm Activity the item is proposed from.
     * @param string $localfile Absolute path of the proposed file.
     * @param string $filename Name of the proposed file.
     * @param \stdClass $meta title, description, descriptionformat, license, authors (as for item_manager::create()),
     *     changelog and changelogformat (description of the proposal, required).
     * @param int $userid Proposing user, 0 for the current user.
     * @return \stdClass The version, in review; itemid names the new item.
     * @throws \moodle_exception If a required field is missing, the file is refused or no storage location exists.
     */
    public static function propose_item(
        \cm_info $cm,
        string $localfile,
        string $filename,
        \stdClass $meta,
        int $userid = 0
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $type = self::get_item_type($cm);
        if ($type === null) {
            throw new \moodle_exception('error_invalidtype', 'local_nextpool');
        }
        $changelog = (string) ($meta->changelog ?? '');
        self::require_description($changelog);
        if (trim(html_to_text((string) ($meta->description ?? ''), 0, false)) === '') {
            throw new \moodle_exception('error_required', 'local_nextpool', '', get_string('description'));
        }
        $sourceid = self::get_placeholder_sourceid();
        $course = get_course((int) $cm->course);

        $transaction = $DB->start_delegated_transaction();
        $item = item_manager::create((object) [
            'title' => trim((string) ($meta->title ?? '')),
            'description' => (string) $meta->description,
            'descriptionformat' => (int) ($meta->descriptionformat ?? FORMAT_HTML),
            'type' => $type,
            'license' => (string) ($meta->license ?? ''),
            'sourceid' => $sourceid,
            'contextid' => \context_coursecat::instance((int) $course->category)->id,
        ], $meta->authors ?? [['userid' => $userid]], $userid);
        item_manager::set_status((int) $item->id, status::REVIEW, $userid);
        $version = version_manager::create_from_file((int) $item->id, $localfile, $filename, new entry(''), [
            'origin' => version_manager::ORIGIN_PROPOSAL,
            'userid' => $userid,
            'courseid' => (int) $course->id,
            'changelog' => $changelog,
            'changelogformat' => (int) ($meta->changelogformat ?? FORMAT_HTML),
        ]);
        $version = version_manager::set_status((int) $version->id, status::REVIEW, $userid);
        subscription_manager::subscribe_automatically((int) $item->id, $userid, (int) $course->id);
        $transaction->allow_commit();
        return $version;
    }

    /**
     * Change a proposal that was returned for revision and submit it again (F07.AC05).
     *
     * The history stays; the file is replaced only if a new one is given.
     *
     * @param int $versionid Proposal, a draft after it was returned.
     * @param string $changelog Description of the change, required.
     * @param int $changelogformat Format of the description.
     * @param string|null $localfile Absolute path of a new file, null to keep the file.
     * @param string $filename Name of the new file.
     * @param int[]|null $commentids Comments the proposal takes up, null to keep them.
     * @param int $userid Proposing user, 0 for the current user.
     * @return \stdClass The version, in review again.
     * @throws \moodle_exception If the user did not propose it, it is not a draft, or the file is refused.
     */
    public static function resubmit(
        int $versionid,
        string $changelog,
        int $changelogformat = FORMAT_HTML,
        ?string $localfile = null,
        string $filename = '',
        ?array $commentids = null,
        int $userid = 0
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $version = version_manager::get($versionid);
        self::require_own($version, $userid);
        if ($version->status !== status::DRAFT) {
            throw new \moodle_exception('error_statustransition', 'local_nextpool', '', [
                'from' => $version->status,
                'to' => status::REVIEW,
            ]);
        }
        self::require_description($changelog);
        $item = item_manager::get((int) $version->itemid);

        $transaction = $DB->start_delegated_transaction();
        $update = (object) [
            'id' => $versionid,
            'changelog' => $changelog,
            'changelogformat' => $changelogformat,
        ];
        if ($localfile !== null) {
            self::check_file($item->type, $localfile, $filename, (int) $item->id);
            $filename = clean_param($filename, PARAM_FILE);
            $update->contenthash = sha1_file($localfile);
            $update->filename = $filename;
            $update->filesize = (int) filesize($localfile);
            $update->mimetype = mimeinfo('type', $filename);
            $fs = get_file_storage();
            $contextid = \context_system::instance()->id;
            $fs->delete_area_files($contextid, 'local_nextpool', version_manager::FILEAREA, $versionid);
            $fs->create_file_from_pathname([
                'contextid' => $contextid,
                'component' => 'local_nextpool',
                'filearea' => version_manager::FILEAREA,
                'itemid' => $versionid,
                'filepath' => '/',
                'filename' => $filename,
                'mimetype' => $update->mimetype,
                'userid' => version_manager::get_snapshot_owner(),
            ], $localfile);
        }
        $DB->update_record(version_manager::TABLE, $update);
        if ($commentids !== null) {
            self::set_linked_comments($versionid, $commentids);
        }
        $version = version_manager::set_status($versionid, status::REVIEW, $userid);
        $transaction->allow_commit();

        self::notify_subscribers($item, $version, $userid);
        return $version;
    }

    /**
     * Cancel an own proposal before a reviewer decides on it (F07.AC11).
     *
     * A proposed new item goes back to draft with it, so it no longer waits for review.
     *
     * @param int $versionid Proposal in draft or review.
     * @param int $userid Proposing user, 0 for the current user.
     * @return \stdClass The cancelled version.
     * @throws \moodle_exception If the user did not propose it or it is decided already.
     */
    public static function cancel(int $versionid, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $version = version_manager::get($versionid);
        self::require_own($version, $userid);
        $transaction = $DB->start_delegated_transaction();
        $version = version_manager::set_status($versionid, status::CANCELLED, $userid);
        self::release_new_item((int) $version->itemid, $userid);
        $transaction->allow_commit();
        return $version;
    }

    /**
     * Put a proposed new item back to draft once no proposal of it waits any more (cancelled or rejected).
     *
     * Items with a numbered version are left alone.
     *
     * @param int $itemid Item id.
     * @param int $userid Acting user.
     */
    public static function release_new_item(int $itemid, int $userid): void {
        global $DB;
        $item = item_manager::get($itemid);
        if ($item->status !== status::REVIEW || version_manager::get_latest($itemid) !== null) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal([status::DRAFT, status::REVIEW], SQL_PARAMS_NAMED);
        $waiting = $DB->record_exists_select(version_manager::TABLE, "itemid = :itemid AND status $insql", $params + [
            'itemid' => $itemid,
        ]);
        if (!$waiting) {
            item_manager::set_status($itemid, status::DRAFT, $userid);
        }
    }

    /**
     * Whether a proposal is decided against and may be deleted: rejected or cancelled (q67, task86).
     *
     * @param \stdClass $version Version record.
     * @return bool
     */
    public static function is_deletable(\stdClass $version): bool {
        return $version->origin === version_manager::ORIGIN_PROPOSAL
            && in_array($version->status, [status::REJECTED, status::CANCELLED], true)
            && (int) $version->timepublished === 0;
    }

    /**
     * Whether the user may delete a rejected or cancelled proposal before the retention period ends (q67).
     *
     * The editorial team of the item (t86-e).
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_delete(\stdClass $item, \stdClass $version, ?int $userid = null): bool {
        return self::is_deletable($version) && access::can_manage($item, $userid);
    }

    /**
     * Whether deleting the proposal also deletes its item: a proposed new item without any other version (t86-b).
     *
     * @param \stdClass $version Version record.
     * @return bool
     */
    public static function deletes_item(\stdClass $version): bool {
        global $DB;
        $item = item_manager::get((int) $version->itemid);
        if (!in_array($item->status, [status::DRAFT, status::REVIEW], true)) {
            return false;
        }
        return !$DB->record_exists_select(
            version_manager::TABLE,
            'itemid = :itemid AND (id <> :versionid OR timepublished > 0)',
            ['itemid' => $item->id, 'versionid' => $version->id]
        );
    }

    /**
     * Delete a rejected or cancelled proposal with its snapshot, review history and comment links (q67, task86).
     *
     * A proposed new item that has no other version goes with it (t86-b). Checks the state, not the capability;
     * callers check {@see can_delete()}.
     *
     * @param int $versionid Version id.
     * @return bool Whether the item was deleted too.
     * @throws \moodle_exception If the version is no rejected or cancelled proposal.
     */
    public static function delete(int $versionid): bool {
        global $DB;
        $version = version_manager::get($versionid);
        if (!self::is_deletable($version)) {
            throw new \moodle_exception('error_versionnotdeletable', 'local_nextpool');
        }
        $deleteitem = self::deletes_item($version);
        $transaction = $DB->start_delegated_transaction();
        try {
            version_manager::delete_unpublished($versionid);
            if ($deleteitem) {
                item_manager::delete((int) $version->itemid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Never leave the transaction open: cleanup() goes on with the next proposal (bug165).
            $transaction->rollback($e);
        }
        return $deleteitem;
    }

    /**
     * Delete rejected and cancelled proposals decided more than 12 months ago (q67, scheduled task cleanup_proposals).
     *
     * @param int|null $now Current time, null for now.
     * @return int Number of deleted proposals.
     */
    public static function cleanup(?int $now = null): int {
        global $DB;
        $before = strtotime('-' . self::RETENTION_MONTHS . ' months', $now ?? time());
        [$insql, $params] = $DB->get_in_or_equal([status::REJECTED, status::CANCELLED], SQL_PARAMS_NAMED);
        $versionids = $DB->get_fieldset_select(
            version_manager::TABLE,
            'id',
            "origin = :origin AND status $insql AND timedecided > 0 AND timedecided < :before",
            $params + ['origin' => version_manager::ORIGIN_PROPOSAL, 'before' => $before]
        );
        $deleted = 0;
        foreach ($versionids as $versionid) {
            try {
                self::delete((int) $versionid);
                $deleted++;
            } catch (\moodle_exception $e) {
                // One proposal that cannot go must not keep the others (e.g. its item meanwhile in use).
                debugging('Proposal ' . $versionid . ' was not deleted: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $deleted;
    }

    /**
     * Whether a version is a proposal of the user.
     *
     * @param \stdClass $version Version record.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function is_own(\stdClass $version, ?int $userid = null): bool {
        global $USER;
        return $version->origin === version_manager::ORIGIN_PROPOSAL
            && (int) $version->createdby === (int) ($userid ?? $USER->id);
    }

    /**
     * Proposals of a user, newest first, with the item title and context (F07 "Meine Vorschläge").
     *
     * @param int $userid User id.
     * @return \stdClass[] Version records with itemtitle and itemcontextid.
     */
    public static function get_for_user(int $userid): array {
        global $DB;
        return $DB->get_records_sql(
            'SELECT v.*, i.title AS itemtitle, i.contextid AS itemcontextid, i.status AS itemstatus
               FROM {' . version_manager::TABLE . '} v
               JOIN {' . item_manager::TABLE . '} i ON i.id = v.itemid
              WHERE v.origin = :origin AND v.createdby = :userid
           ORDER BY v.timecreated DESC, v.id DESC',
            ['origin' => version_manager::ORIGIN_PROPOSAL, 'userid' => $userid]
        );
    }

    /**
     * The latest reason a reviewer gave for a version, '' if none (returned or rejected).
     *
     * @param int $versionid Version id.
     * @return \stdClass|null Log entry with reason and reasonformat, null if none.
     */
    public static function get_last_reason(int $versionid): ?\stdClass {
        return self::get_last_reasons([$versionid])[$versionid] ?? null;
    }

    /**
     * The latest reason a reviewer gave for each of several versions, in one query (bug111).
     *
     * @param int[] $versionids Version ids.
     * @return array<int,\stdClass> Version id => log entry with reason and reasonformat; versions without reason are missing.
     */
    public static function get_last_reasons(array $versionids): array {
        global $DB;
        if (!$versionids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $versionids), SQL_PARAMS_NAMED);
        $logs = $DB->get_records_select(
            version_manager::LOG_TABLE,
            "versionid $insql AND reason IS NOT NULL",
            $params,
            'timecreated ASC, id ASC'
        );
        $reasons = [];
        foreach ($logs as $log) {
            if (trim((string) $log->reason) !== '') {
                $reasons[(int) $log->versionid] = $log;
            }
        }
        return $reasons;
    }

    /**
     * Open top-level comments of an item a proposal can take up.
     *
     * @param int $itemid Item id.
     * @return array<int,string> Comment id => "Name: start of the text" (plain text).
     */
    public static function get_open_comments(int $itemid): array {
        global $DB;
        $comments = [];
        $threads = array_filter(
            comment_manager::get_threads($itemid),
            static fn($comment) => empty($comment->deleted) && empty($comment->resolvedby) && empty($comment->timeresolved)
        );
        $userids = array_unique(array_map(static fn($comment) => (int) $comment->userid, $threads));
        $users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
        foreach ($threads as $id => $comment) {
            $user = $users[(int) $comment->userid] ?? null;
            $text = shorten_text(trim(html_to_text((string) $comment->content, 0, false)), 80);
            $comments[$id] = get_string('proposalcommentoption', 'local_nextpool', [
                'user' => $user ? fullname($user) : '-',
                'text' => $text,
            ]);
        }
        return $comments;
    }

    /**
     * Comments a proposal takes up, oldest first.
     *
     * @param int $versionid Version id.
     * @return \stdClass[] Comment records keyed by id.
     */
    public static function get_linked_comments(int $versionid): array {
        global $DB;
        return $DB->get_records_sql(
            'SELECT c.*
               FROM {' . self::LINK_TABLE . '} l
               JOIN {' . comment_manager::TABLE . '} c ON c.id = l.commentid
              WHERE l.versionid = :versionid
           ORDER BY c.timecreated, c.id',
            ['versionid' => $versionid]
        );
    }

    /**
     * Replace the comments a proposal takes up; only top-level comments of the same item are kept.
     *
     * @param int $versionid Version id.
     * @param int[] $commentids Comment ids.
     */
    public static function set_linked_comments(int $versionid, array $commentids): void {
        global $DB;
        $version = version_manager::get($versionid);
        $DB->delete_records(self::LINK_TABLE, ['versionid' => $versionid]);
        $commentids = array_unique(array_filter(array_map('intval', $commentids)));
        if (!$commentids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($commentids, SQL_PARAMS_NAMED);
        $valid = $DB->get_fieldset_select(
            comment_manager::TABLE,
            'id',
            "id $insql AND itemid = :itemid AND parentid IS NULL AND deleted = 0",
            $params + ['itemid' => $version->itemid]
        );
        foreach ($valid as $commentid) {
            $DB->insert_record(self::LINK_TABLE, (object) ['versionid' => $versionid, 'commentid' => (int) $commentid]);
        }
    }

    /**
     * Notify subscribers of an item about a new or resubmitted proposal (F08.AC04).
     *
     * Proposals are visible only to the editorial team, reviewers and item editors (F07 "Status und Sichtbarkeit"),
     * so only subscribers who see the item editorially get a message; the proposing person gets none.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version The proposal.
     * @param int $userid Proposing user.
     */
    private static function notify_subscribers(\stdClass $item, \stdClass $version, int $userid): void {
        $context = access::get_context($item);
        $user = \core_user::get_user($userid);
        $url = new \moodle_url('/local/nextpool/review.php', ['versionid' => $version->id]);
        $arguments = static fn() => (object) [
            'item' => format_string($item->title, true, ['context' => $context, 'escape' => false]),
            'user' => $user ? fullname($user) : '-',
            'version' => review_manager::get_version_name($version),
            'url' => $url->out(false),
        ];
        foreach (subscription_manager::get_subscribers((int) $item->id) as $recipient => $subscription) {
            if ($recipient === $userid || !access::can_comment($item, 0, $recipient)) {
                continue;
            }
            review_manager::send_message(
                'proposalsubmitted',
                $recipient,
                'proposalsubmitted_subject',
                'proposalsubmitted_body',
                $url->out(false),
                (int) $version->courseid ?: SITEID,
                $arguments
            );
        }
    }

    /**
     * Storage location a proposed new item is recorded with until it is published (q75).
     *
     * @return int Source id.
     * @throws \moodle_exception If there is no enabled storage location.
     */
    private static function get_placeholder_sourceid(): int {
        $sourceid = direct_upload::get_sourceid();
        if ($sourceid) {
            return $sourceid;
        }
        $records = source_manager::get_records(true);
        if (!$records) {
            throw new \moodle_exception('error_nouploadsource', 'local_nextpool');
        }
        return (int) reset($records)->id;
    }

    /**
     * Throw unless the description of a proposal has text (F07.AC02).
     *
     * @param string $changelog Description.
     * @throws \moodle_exception
     */
    private static function require_description(string $changelog): void {
        if (trim(html_to_text($changelog, 0, false)) === '') {
            $field = get_string('proposaldescription', 'local_nextpool');
            throw new \moodle_exception('error_required', 'local_nextpool', '', $field);
        }
    }

    /**
     * Throw unless the version is a proposal of the user.
     *
     * @param \stdClass $version Version record.
     * @param int $userid User id.
     * @throws \moodle_exception
     */
    private static function require_own(\stdClass $version, int $userid): void {
        if (!self::is_own($version, $userid)) {
            throw new \moodle_exception('error_notownproposal', 'local_nextpool');
        }
    }
}
