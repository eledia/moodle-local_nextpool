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

use local_nextpool\event\version_cancelled;
use local_nextpool\event\version_created;
use local_nextpool\event\version_deleted;
use local_nextpool\event\version_published;
use local_nextpool\event\version_rejected;
use local_nextpool\event\version_returned;
use local_nextpool\event\version_submitted;
use local_nextpool\event\version_withdrawn;
use local_nextpool\local\source\base as source_base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;

/**
 * Item versions (F02).
 *
 * A version is imported from the item's backend and stored as an immutable
 * snapshot in the Moodle file API (component local_nextpool, file area
 * 'version', system context, itemid = version id). The snapshot keeps pinned
 * placements and older versions deliverable even after the backend file has
 * changed or the backend is unreachable; the version table is independent of
 * any backend versioning (adr08).
 *
 * The file area 'xapipackage' (item context, itemid = version id) holds the
 * unpacked xAPI package of a version. mod_nextpool fills and delivers it
 * (task47); the catalogue only deletes it with the item and moves it with the
 * item's context, and never serves it through local_nextpool_pluginfile().
 *
 * Every version records its origin (TD-301): created by the editors, imported by
 * the storage location check, proposed from a course or uploaded directly in a
 * course (M06). Proposals have no number until they are published (q67, adr24),
 * so lists and comparisons by number only see numbered versions. Status changes
 * are recorded in the review history with their reasons (TD-303).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class version_manager {
    /** @var string Version table. */
    public const TABLE = 'local_nextpool_version';
    /** @var string File area of the snapshots. */
    public const FILEAREA = 'version';
    /** @var string File area of unpacked xAPI packages, in the item context (task45). */
    public const FILEAREA_XAPIPACKAGE = 'xapipackage';
    /** @var string Review history table (TD-303). */
    public const LOG_TABLE = 'local_nextpool_version_log';

    /** @var string Created or imported by the editors or item editors. */
    public const ORIGIN_EDITORIAL = 'editorial';
    /** @var string Imported by the storage location check (F06). */
    public const ORIGIN_SOURCE = 'source';
    /** @var string Proposed by a teacher from a course (F07). */
    public const ORIGIN_PROPOSAL = 'proposal';
    /** @var string Uploaded directly in a course activity (M06), published without review (q54). */
    public const ORIGIN_UPLOAD = 'upload';

    /** @var string History: submitted for review. */
    public const LOG_SUBMITTED = 'submitted';
    /** @var string History: returned to draft with a reason. */
    public const LOG_RETURNED = 'returned';
    /** @var string History: rejected with a reason. */
    public const LOG_REJECTED = 'rejected';
    /** @var string History: published. */
    public const LOG_PUBLISHED = 'published';
    /** @var string History: withdrawn after publishing. */
    public const LOG_WITHDRAWN = 'withdrawn';
    /** @var string History: cancelled by the person who proposed it. */
    public const LOG_CANCELLED = 'cancelled';
    /** @var string History: review assigned to a reviewer (task85). */
    public const LOG_ASSIGNED = 'assigned';

    /**
     * Import the current content of a backend file as a new draft version.
     *
     * If the content and the file name are identical to the latest version, no version is created. If it is
     * the same file (same path or same backend id), its new revision and path are recorded on that version, so
     * polling does not download the unchanged file again (bug36) and the item follows a moved file (q46).
     * A new file name always creates a version, even with identical content (q50).
     *
     * @param int $itemid Item id.
     * @param string $path File path relative to the backend's base folder.
     * @param array $options Optional 'label', 'changelog', 'changelogformat', 'basedonversionid', 'createdby' or
     *     'userid' (0 for the storage location check), 'origin' (ORIGIN_*, default editorial, or source for
     *     createdby 0) and 'courseid' (course of a proposal).
     * @param source_base|null $source The item's backend if the caller has it already, null to load it.
     * @return \stdClass The stored version.
     * @throws source_exception If the file does not exist, is a folder, or the backend fails.
     * @throws \moodle_exception If content and file name are identical to the latest version.
     */
    public static function create_from_source(
        int $itemid,
        string $path,
        array $options = [],
        ?source_base $source = null
    ): \stdClass {
        $item = item_manager::get($itemid);
        $source = $source ?? source_manager::get((int) $item->sourceid);

        $entry = $source->stat($path);
        if ($entry === null) {
            throw new source_exception('error_notfound');
        }
        if ($entry->isfolder) {
            throw new source_exception('error_notafile');
        }

        $tempfile = make_request_directory() . '/content';
        $source->read($entry->path, $tempfile);
        $contenthash = sha1_file($tempfile);
        self::set_source_missing($itemid, false);

        $latest = self::get_latest($itemid);
        if ($latest && $latest->contenthash === $contenthash && !self::is_renamed($latest, $entry)) {
            $samefile = $latest->sourceref === $entry->path
                || ($entry->externalid !== '' && $entry->externalid === (string) $latest->sourceexternalid);
            if ($samefile) {
                self::follow_file($latest, $entry, true);
            }
            throw new \moodle_exception('error_unchanged', 'local_nextpool');
        }
        // A file the item's type cannot use never becomes a version, e.g. a quiz with a question type outside q30.
        try {
            item_types::get_handler($item->type)?->validate_package($tempfile);
        } catch (\moodle_exception $e) {
            if ($latest) {
                rejected_source_revision::remember($item, $latest, $entry);
            }
            throw $e;
        }
        $filename = clean_param($entry->get_name(), PARAM_FILE);
        return self::insert($item, $tempfile, $contenthash, $entry, $filename, $latest, $options);
    }

    /**
     * Store a local file as a new draft version of an item, after it was written to the item's backend (TD-405).
     *
     * For direct uploads (M06) and course restores: the file comes from the user or the backup, not from the
     * backend, but the version records where it was written ($entry, from {@see source_base::write()}), so the
     * storage location check finds it unchanged and does not create a second draft.
     *
     * @param int $itemid Item id.
     * @param string $localfile Absolute path of the local file.
     * @param string $filename File name of the version, e.g. the name of the uploaded file.
     * @param entry $entry The file as written to the item's backend.
     * @param array $options As for {@see create_from_source()}, plus 'versionnr' to keep the number of a
     *     restored version (higher than every existing version of the item).
     * @return \stdClass The stored version.
     * @throws \moodle_exception If the type's handler refuses the file, content and file name are identical to
     *     the latest version, or the version number is taken.
     */
    public static function create_from_file(
        int $itemid,
        string $localfile,
        string $filename,
        entry $entry,
        array $options = []
    ): \stdClass {
        $item = item_manager::get($itemid);
        $contenthash = sha1_file($localfile);
        $filename = clean_param($filename, PARAM_FILE);
        $latest = self::get_latest($itemid);
        if ($latest && $latest->contenthash === $contenthash && $latest->filename === $filename) {
            throw new \moodle_exception('error_unchanged', 'local_nextpool');
        }
        item_types::get_handler($item->type)?->validate_package($localfile);
        $versionnr = (int) ($options['versionnr'] ?? 0);
        if ($versionnr && $latest && $versionnr <= (int) $latest->versionnr) {
            throw new \coding_exception('Version number ' . $versionnr . ' is not above the latest version.');
        }
        self::set_source_missing($itemid, false);
        return self::insert($item, $localfile, $contenthash, $entry, $filename, $latest, $options);
    }

    /**
     * Upload a file for a new item: write it into the item's storage location and store it as draft version 1.
     *
     * For the item form (task101): the file lands in the base folder of the storage location under its own name.
     * An existing file is never replaced, because it may belong to another item. If the version cannot be stored,
     * the written file is deleted again; the caller runs this in the transaction that created the item.
     *
     * @param int $itemid Item id, an item without versions.
     * @param string $localfile Absolute path of the uploaded file.
     * @param string $filename Name of the uploaded file.
     * @return \stdClass The stored version.
     * @throws source_exception If the storage location cannot be written to, the file exists or the backend fails.
     * @throws \moodle_exception If the type's handler refuses the file.
     */
    public static function create_from_upload(int $itemid, string $localfile, string $filename): \stdClass {
        $item = item_manager::get($itemid);
        if (self::get_latest($itemid)) {
            throw new \coding_exception('create_from_upload() is only for items without versions.');
        }
        $source = source_manager::get((int) $item->sourceid);
        if (!$source->supports_write()) {
            throw new source_exception('error_sourcereadonly');
        }
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '') {
            throw new source_exception('error_invalidpath');
        }
        $path = source\path::normalise($filename);
        if ($source->stat($path) !== null) {
            throw new source_exception('error_fileexists', s($path));
        }
        // Check the file before it reaches the storage location.
        item_types::get_handler($item->type)?->validate_package($localfile);
        $entry = $source->write($path, $localfile);
        try {
            return self::create_from_file($itemid, $localfile, $filename, $entry);
        } catch (\Throwable $e) {
            try {
                $source->delete($entry->path);
            } catch (\Throwable $ignored) {
                debugging(
                    'Could not delete ' . $entry->path . ' after a failed upload: ' . $ignored->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
            throw $e;
        }
    }

    /**
     * Insert a draft version with its snapshot.
     *
     * @param \stdClass $item Item record.
     * @param string $localfile Absolute path of the local file with the content.
     * @param string $contenthash SHA-1 of the content.
     * @param entry $entry The file in the item's backend.
     * @param string $filename Cleaned file name of the version ('' falls back to 'content').
     * @param \stdClass|null $latest Latest version of the item.
     * @param array $options See {@see create_from_source()} and {@see create_from_file()}.
     * @return \stdClass The stored version.
     */
    private static function insert(
        \stdClass $item,
        string $localfile,
        string $contenthash,
        entry $entry,
        string $filename,
        ?\stdClass $latest,
        array $options
    ): \stdClass {
        global $DB, $USER;
        $itemid = (int) $item->id;
        $basedon = (int) ($options['basedonversionid'] ?? 0);
        if ($basedon && !$DB->record_exists(self::TABLE, ['id' => $basedon, 'itemid' => $itemid])) {
            throw new \moodle_exception('error_invalidbaseversion', 'local_nextpool');
        }

        $filename = $filename ?: 'content';
        $createdby = array_key_exists('createdby', $options)
            ? (int) $options['createdby'] : ((int) ($options['userid'] ?? 0) ?: (int) $USER->id);
        // A createdby of 0 marks versions the storage location check created on its own (F06 polling).
        $origin = (string) ($options['origin'] ?? ($createdby === 0 ? self::ORIGIN_SOURCE : self::ORIGIN_EDITORIAL));
        if (!in_array($origin, [self::ORIGIN_EDITORIAL, self::ORIGIN_SOURCE, self::ORIGIN_PROPOSAL, self::ORIGIN_UPLOAD], true)) {
            throw new \coding_exception('Unknown version origin: ' . $origin);
        }
        $isproposal = $origin === self::ORIGIN_PROPOSAL;
        $record = (object) [
            'itemid' => $itemid,
            // Proposals get their number when they are published, so rejected ones leave no gap (q67).
            'versionnr' => $isproposal ? null
                : ((int) ($options['versionnr'] ?? 0) ?: ($latest ? (int) $latest->versionnr + 1 : 1)),
            // Versions get a label from month and file name unless the caller names them; proposals stay unnamed (t103-b).
            'label' => trim((string) ($options['label'] ?? '')) ?: ($isproposal ? null : self::build_label($filename)),
            'changelog' => $options['changelog'] ?? null,
            'changelogformat' => (int) ($options['changelogformat'] ?? FORMAT_HTML),
            'contenthash' => $contenthash,
            'sourceref' => $entry->path,
            'sourcerevision' => $entry->revision !== '' ? $entry->revision : null,
            'sourceexternalid' => $entry->externalid !== '' ? $entry->externalid : null,
            'filename' => $filename,
            'filesize' => (int) filesize($localfile),
            // The type follows the file name as everywhere in Moodle, not what the storage location claims (bug38).
            'mimetype' => mimeinfo('type', $filename),
            'status' => status::DRAFT,
            'basedonversionid' => $basedon ?: null,
            'createdby' => $createdby,
            'timecreated' => time(),
            'publishedby' => 0,
            'timepublished' => 0,
            'origin' => $origin,
            'courseid' => $isproposal ? ((int) ($options['courseid'] ?? 0) ?: null) : null,
            // Drafts from the storage location wait for review from the start (q73, task85).
            'timesubmitted' => $origin === self::ORIGIN_SOURCE ? time() : 0,
        ];

        $transaction = $DB->start_delegated_transaction();
        $record->id = $DB->insert_record(self::TABLE, $record);
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_nextpool',
            'filearea' => self::FILEAREA,
            'itemid' => $record->id,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => $record->mimetype,
            'userid' => self::get_snapshot_owner(),
        ], $localfile);
        $transaction->allow_commit();

        $version = self::get($record->id);
        if ($origin === self::ORIGIN_SOURCE) {
            review_queue::invalidate();
        }
        version_created::create_from_version($version, $item)->trigger();
        return $version;
    }

    /**
     * Automatic label of a version: year and month of the import, an underscore and the file name without extension.
     *
     * Example: 2026-11_Handout 1 (task103). Longer than the field, the name is shortened.
     *
     * @param string $filename File name of the version.
     * @param int|null $time Time of the import, null for now.
     * @return string
     */
    public static function build_label(string $filename, ?int $time = null): string {
        $name = pathinfo(clean_param($filename, PARAM_FILE), PATHINFO_FILENAME);
        $label = userdate($time ?? time(), '%Y-%m') . '_' . $name;
        return \core_text::substr($label, 0, 255);
    }

    /**
     * Whether label and change log of a version can still be edited: not after it was rejected or cancelled.
     *
     * Published and withdrawn versions are editable, because both are metadata only (t103-a).
     *
     * @param \stdClass $version Version record.
     * @return bool
     */
    public static function can_edit_details(\stdClass $version): bool {
        return !in_array($version->status, [status::REJECTED, status::CANCELLED], true);
    }

    /**
     * Change label and change log of a version afterwards (task103).
     *
     * Checks the status, not the capability; callers check {@see access::require_edit()}.
     *
     * @param int $versionid Version id.
     * @param string $label New label, '' for none.
     * @param string $changelog New change log, '' for none.
     * @param int $changelogformat Format of the change log.
     * @return \stdClass The stored version.
     * @throws \moodle_exception If the version can no longer be edited.
     */
    public static function update_details(int $versionid, string $label, string $changelog, int $changelogformat): \stdClass {
        global $DB;
        $version = self::get($versionid);
        if (!self::can_edit_details($version)) {
            throw new \moodle_exception('error_versionnoteditable', 'local_nextpool');
        }
        $label = trim($label);
        $changelog = trim($changelog);
        $DB->update_record(self::TABLE, (object) [
            'id' => $versionid,
            'label' => $label !== '' ? \core_text::substr($label, 0, 255) : null,
            'changelog' => $changelog !== '' ? $changelog : null,
            'changelogformat' => $changelogformat,
        ]);
        return self::get($versionid);
    }

    /**
     * Change the editorial status of a version.
     *
     * Publishing makes the version the item's current version if it is newer
     * than the current one, and gives a proposal the next free number (q67). Withdrawing the current version
     * falls back to the newest remaining published version. Returning a version in review to draft and
     * rejecting it need a reason (TD-303). Every change is recorded in the review history.
     *
     * Direct uploads (M06) pass review without a review (q54): their submission is neither recorded nor
     * announced, and their publication never counts as self-approved.
     *
     * Checks the workflow, not the capability; callers check {@see access::can_change_status()}.
     *
     * @param int $versionid Version id.
     * @param string $newstatus Target status.
     * @param int $userid Acting user, 0 for the current user.
     * @param bool $h5plibraries When publishing: allow the H5P package to install or update H5P libraries
     *     site-wide (q39). Off by default; ignored for other target states.
     * @param string|null $reason Reason of the change; required when returning to draft or rejecting.
     * @param int $reasonformat Format of the reason.
     * @return \stdClass The stored version.
     * @throws \moodle_exception If the transition is not allowed or a required reason is missing.
     */
    public static function set_status(
        int $versionid,
        string $newstatus,
        int $userid = 0,
        bool $h5plibraries = false,
        ?string $reason = null,
        int $reasonformat = FORMAT_PLAIN
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $version = self::get($versionid);
        $item = item_manager::get((int) $version->itemid);
        status::require_transition($version->status, $newstatus);
        if ($newstatus === status::CANCELLED && $version->origin !== self::ORIGIN_PROPOSAL) {
            // Only proposals can be cancelled; other drafts are deleted or stay (TD-302).
            throw new \moodle_exception('error_statustransition', 'local_nextpool', '', [
                'from' => $version->status,
                'to' => $newstatus,
            ]);
        }
        $action = self::get_log_action($version->status, $newstatus);
        $reason = trim((string) $reason);
        if (in_array($action, [self::LOG_RETURNED, self::LOG_REJECTED], true) && $reason === '') {
            throw new \moodle_exception('error_reasonrequired', 'local_nextpool');
        }
        $isupload = $version->origin === self::ORIGIN_UPLOAD;
        $now = time();

        $update = (object) ['id' => $versionid, 'status' => $newstatus];
        if ($newstatus === status::PUBLISHED) {
            $update->publishedby = $userid;
            $update->timepublished = $now;
            $update->h5plibraries = (int) $h5plibraries;
        }
        if ($newstatus === status::REVIEW && !$isupload) {
            $update->timesubmitted = $now;
        }
        if (in_array($action, [self::LOG_PUBLISHED, self::LOG_RETURNED, self::LOG_REJECTED, self::LOG_CANCELLED], true)) {
            $update->timedecided = $now;
        }
        if ($action === self::LOG_PUBLISHED && $version->status === status::REVIEW) {
            // Publishing one's own version is allowed and marked (q71).
            $update->selfapproved = (int) (!$isupload && self::is_own_version($version, $userid));
        }

        // Check before writing: a caught exception must not leave a changed record in an open transaction (bug34).
        $remaining = array_filter(
            self::get_versions((int) $item->id, status::PUBLISHED),
            static fn($published) => (int) $published->id !== $versionid
        );
        if ($newstatus !== status::PUBLISHED && !$remaining && $item->status === status::PUBLISHED) {
            throw new \moodle_exception('error_lastpublishedversion', 'local_nextpool');
        }

        $transaction = $DB->start_delegated_transaction();
        if ($newstatus === status::PUBLISHED && $version->versionnr === null) {
            $latest = self::get_latest((int) $item->id);
            $update->versionnr = $latest ? (int) $latest->versionnr + 1 : 1;
        }
        $DB->update_record(self::TABLE, $update);
        if ($action !== self::LOG_SUBMITTED || !$isupload) {
            self::add_log($versionid, $action, $userid, $reason !== '' ? $reason : null, $reasonformat);
        }
        $current = self::find_current_version((int) $item->id);
        if ((int) $item->currentversionid !== (int) ($current->id ?? 0)) {
            $DB->update_record(item_manager::TABLE, (object) [
                'id' => $item->id,
                'currentversionid' => $current->id ?? null,
                'timemodified' => time(),
            ]);
        }
        $transaction->allow_commit();
        review_queue::invalidate();

        $waspublished = $version->status === status::PUBLISHED;
        $version = self::get($versionid);
        $item = item_manager::get((int) $item->id);
        if ($newstatus === status::PUBLISHED) {
            version_published::create_from_version($version, $item)->trigger();
        } else if ($waspublished) {
            // Courses must not keep waiting for a version that is no longer published (bug53).
            placement_manager::withdraw_offers($versionid);
        }
        $event = match (true) {
            $action === self::LOG_SUBMITTED && !$isupload => version_submitted::create_from_version($version, $item),
            $action === self::LOG_RETURNED => version_returned::create_from_version($version, $item),
            $action === self::LOG_REJECTED => version_rejected::create_from_version($version, $item),
            $action === self::LOG_WITHDRAWN => version_withdrawn::create_from_version($version, $item, $userid),
            $action === self::LOG_CANCELLED => version_cancelled::create_from_version($version, $item, $userid),
            default => null,
        };
        $event?->trigger();
        return $version;
    }

    /**
     * The history action of a status change.
     *
     * @param string $from Current status.
     * @param string $to Target status.
     * @return string One of the LOG_* constants.
     */
    private static function get_log_action(string $from, string $to): string {
        return match ($to) {
            status::REVIEW => self::LOG_SUBMITTED,
            status::DRAFT => self::LOG_RETURNED,
            status::REJECTED => self::LOG_REJECTED,
            status::PUBLISHED => self::LOG_PUBLISHED,
            status::WITHDRAWN => self::LOG_WITHDRAWN,
            status::CANCELLED => self::LOG_CANCELLED,
        };
    }

    /**
     * Whether a user created a version or was the last to submit it for review.
     *
     * @param \stdClass $version Version record.
     * @param int $userid User id.
     * @return bool
     */
    private static function is_own_version(\stdClass $version, int $userid): bool {
        global $DB;
        if ((int) $version->createdby === $userid) {
            return true;
        }
        $submissions = $DB->get_records(
            self::LOG_TABLE,
            ['versionid' => $version->id, 'action' => self::LOG_SUBMITTED],
            'timecreated DESC, id DESC',
            'id, userid',
            0,
            1
        );
        return $submissions && (int) reset($submissions)->userid === $userid;
    }

    /**
     * Record an entry in the review history of a version (TD-303).
     *
     * @param int $versionid Version id.
     * @param string $action One of the LOG_* constants.
     * @param int $userid Acting user.
     * @param string|null $reason Reason, if any.
     * @param int $reasonformat Format of the reason.
     * @param int|null $relateduserid For assignments: the assigned reviewer.
     * @return int Id of the entry.
     */
    public static function add_log(
        int $versionid,
        string $action,
        int $userid,
        ?string $reason = null,
        int $reasonformat = FORMAT_PLAIN,
        ?int $relateduserid = null
    ): int {
        global $DB;
        return (int) $DB->insert_record(self::LOG_TABLE, (object) [
            'versionid' => $versionid,
            'action' => $action,
            'userid' => $userid,
            'relateduserid' => $relateduserid,
            'reason' => $reason,
            'reasonformat' => $reasonformat,
            'timecreated' => time(),
        ]);
    }

    /**
     * Review history of a version, oldest first.
     *
     * @param int $versionid Version id.
     * @return \stdClass[]
     */
    public static function get_log(int $versionid): array {
        global $DB;
        return array_values($DB->get_records(self::LOG_TABLE, ['versionid' => $versionid], 'timecreated ASC, id ASC'));
    }

    /**
     * Whether the backend file of the latest version has changed since its import.
     *
     * Looks for the file at its stored path first, then by its backend id (q46, TD-201): a file that was only
     * moved inside the base folder gets its new path recorded and counts as unchanged; a renamed file counts as
     * changed (q50). A file found neither way marks the item as "not found" until it turns up again (F03O.AC09,
     * AC15). Otherwise the backend revision (ETag, cTag) is compared; used by polling (F06) and "Check now".
     *
     * @param int $itemid Item id.
     * @param source_base|null $source The item's backend if the caller has it already, null to load it.
     * @return entry|null The changed backend file (import it from its path), or null if unchanged, not found or
     *     the item has no version.
     * @throws source_exception On backend errors.
     */
    public static function check_for_update(int $itemid, ?source_base $source = null): ?entry {
        global $DB;
        $latest = self::get_latest($itemid);
        if ($latest === null) {
            return null;
        }
        $source = $source ?? source_manager::get((int) item_manager::get($itemid)->sourceid);
        $entry = $source->stat($latest->sourceref);
        if ($entry !== null && !$entry->isfolder) {
            if ((string) $latest->sourceexternalid === '' && $entry->externalid !== '') {
                // Versions from before task71 learn the id of their file on the next check.
                $DB->set_field(self::TABLE, 'sourceexternalid', $entry->externalid, ['id' => $latest->id]);
            }
        } else {
            $entry = null;
            $externalid = (string) $latest->sourceexternalid;
            $found = $externalid !== '' ? $source->find_by_externalid($externalid) : null;
            if ($found !== null && !$found->isfolder) {
                if (!self::is_renamed($latest, $found)) {
                    // Only moved: follow the file without a new version.
                    self::follow_file($latest, $found, false);
                }
                $entry = $found;
            }
        }
        self::set_source_missing($itemid, $entry === null);
        if ($entry === null) {
            return null;
        }
        if (self::is_renamed($latest, $entry)) {
            return $entry;
        }
        if ($entry->revision === '') {
            return null;
        }
        return $entry->revision !== (string) $latest->sourcerevision ? $entry : null;
    }

    /**
     * Whether a backend file has a different name than the file of a version (q50).
     *
     * @param \stdClass $version Version record.
     * @param entry $entry Backend file.
     * @return bool
     */
    private static function is_renamed(\stdClass $version, entry $entry): bool {
        return $entry->get_name() !== (new entry((string) $version->sourceref))->get_name();
    }

    /**
     * Record where the file of a version is now, without creating a version (q46).
     *
     * @param \stdClass $version Version record.
     * @param entry $entry The same file in the backend.
     * @param bool $withrevision Also record the entry's revision (only once its content has been compared).
     */
    private static function follow_file(\stdClass $version, entry $entry, bool $withrevision): void {
        global $DB;
        $update = [];
        if ($entry->path !== (string) $version->sourceref) {
            $update['sourceref'] = $entry->path;
        }
        if ($entry->externalid !== '' && $entry->externalid !== (string) $version->sourceexternalid) {
            $update['sourceexternalid'] = $entry->externalid;
        }
        if ($withrevision && $entry->revision !== '' && $entry->revision !== (string) $version->sourcerevision) {
            $update['sourcerevision'] = $entry->revision;
        }
        if ($update) {
            $DB->update_record(self::TABLE, (object) (['id' => $version->id] + $update));
        }
    }

    /**
     * Mark an item's file as missing in its storage location, or as found again (F03O.AC09).
     *
     * Keeps the time it was first missed; does not touch the item's modification time.
     *
     * @param int $itemid Item id.
     * @param bool $missing Whether the file is missing.
     */
    private static function set_source_missing(int $itemid, bool $missing): void {
        global $DB;
        $DB->set_field_select(
            item_manager::TABLE,
            'timesourcemissing',
            $missing ? time() : 0,
            'id = :id AND timesourcemissing ' . ($missing ? '= 0' : '> 0'),
            ['id' => $itemid]
        );
    }

    /**
     * Load a version.
     *
     * @param int $versionid Version id.
     * @return \stdClass
     */
    public static function get(int $versionid): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $versionid], '*', MUST_EXIST);
    }

    /**
     * Compare the metadata of two versions of an item (F02; content diff for page/book is R2).
     *
     * The older version is always "from". The content counts as changed when the snapshot hash differs.
     *
     * @param int $fromid Version id.
     * @param int $toid Version id of the same item.
     * @return array Keys from and to (version records), rows (keyed by field: from, to, changed) and
     *     between (versions after "from" up to "to", oldest first).
     */
    public static function compare(int $fromid, int $toid): array {
        $from = self::get($fromid);
        $to = self::get($toid);
        if ((int) $from->itemid !== (int) $to->itemid) {
            throw new \coding_exception('Only versions of the same item can be compared.');
        }
        // A proposal without a number counts as newer than every numbered version.
        $number = static fn(\stdClass $v): int => $v->versionnr === null ? PHP_INT_MAX : (int) $v->versionnr;
        if ($number($from) > $number($to)) {
            [$from, $to] = [$to, $from];
        }
        $rows = [];
        foreach (['versionnr', 'label', 'status', 'filename', 'filesize', 'mimetype', 'sourceref', 'contenthash'] as $field) {
            $rows[$field] = [
                'from' => $from->$field,
                'to' => $to->$field,
                'changed' => (string) $from->$field !== (string) $to->$field,
            ];
        }
        $between = array_filter(
            array_reverse(self::get_versions((int) $from->itemid)),
            static fn($v) => (int) $v->versionnr > $number($from) && (int) $v->versionnr <= $number($to)
        );
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'between' => array_values($between)];
    }

    /**
     * Numbered versions of an item, newest first.
     *
     * Proposals without a number are left out (adr24), see {@see get_unnumbered()}.
     *
     * @param int $itemid Item id.
     * @param string|null $status Only versions in this status, null for all.
     * @return \stdClass[]
     */
    public static function get_versions(int $itemid, ?string $status = null): array {
        global $DB;
        $where = 'itemid = :itemid AND versionnr IS NOT NULL';
        $params = ['itemid' => $itemid];
        if ($status !== null) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        return array_values($DB->get_records_select(self::TABLE, $where, $params, 'versionnr DESC'));
    }

    /**
     * Versions of an item without a number (proposals not yet published), oldest first.
     *
     * @param int $itemid Item id.
     * @param string|null $status Only versions in this status, null for all.
     * @return \stdClass[]
     */
    public static function get_unnumbered(int $itemid, ?string $status = null): array {
        global $DB;
        $where = 'itemid = :itemid AND versionnr IS NULL';
        $params = ['itemid' => $itemid];
        if ($status !== null) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        return array_values($DB->get_records_select(self::TABLE, $where, $params, 'timecreated ASC, id ASC'));
    }

    /**
     * The version with the highest number, in any status; proposals without a number do not count (adr24).
     *
     * @param int $itemid Item id.
     * @return \stdClass|null
     */
    public static function get_latest(int $itemid): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select(
            self::TABLE,
            'itemid = :itemid AND versionnr IS NOT NULL',
            ['itemid' => $itemid],
            'versionnr DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * Owner of every snapshot file: the guest user, who may never deploy H5P content (bug234).
     *
     * Snapshots are no H5P content of their own; placing copies them with the publisher as owner (q18).
     *
     * @return int User id.
     * @see h5p_guard::get_owner()
     */
    public static function get_snapshot_owner(): int {
        return h5p_guard::get_owner();
    }

    /**
     * Protect snapshots stored before bug234 was fixed: give them the snapshot owner and remove what core's H5P
     * player already deployed from them (upgrade step 2026111542).
     *
     * @return int Number of deployed H5P contents removed.
     * @see h5p_guard::secure_area()
     */
    public static function secure_existing_snapshots(): int {
        return h5p_guard::secure_area('local_nextpool', self::FILEAREA, \context_system::instance()->id);
    }

    /**
     * Snapshot file of a version.
     *
     * @param int $versionid Version id.
     * @return \stored_file|null
     */
    public static function get_file(int $versionid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_nextpool',
            self::FILEAREA,
            $versionid,
            'id',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Delete all versions, snapshots and unpacked packages of an item (only for never-published items).
     *
     * @param int $itemid Item id.
     */
    public static function delete_all_for_item(int $itemid): void {
        global $DB;
        $fs = get_file_storage();
        $systemcontextid = \context_system::instance()->id;
        $itemcontextid = (int) $DB->get_field(item_manager::TABLE, 'contextid', ['id' => $itemid]);
        foreach ($DB->get_fieldset(self::TABLE, 'id', ['itemid' => $itemid]) as $versionid) {
            preview_assets::delete((int) $versionid);
            $fs->delete_area_files($systemcontextid, 'local_nextpool', self::FILEAREA, $versionid);
            if ($itemcontextid) {
                $fs->delete_area_files($itemcontextid, 'local_nextpool', self::FILEAREA_XAPIPACKAGE, $versionid);
            }
            $DB->delete_records(self::LOG_TABLE, ['versionid' => $versionid]);
            $DB->delete_records('local_nextpool_comment_link', ['versionid' => $versionid]);
        }
        $DB->delete_records(self::TABLE, ['itemid' => $itemid]);
    }

    /**
     * Delete a version that was never published, with its snapshot, unpacked package, review history and
     * comment links (q67, task86).
     *
     * Comments about the version stay as comments about the item in general; versions based on it lose the base.
     * Runs in a transaction of the caller or its own.
     *
     * @param int $versionid Version id.
     * @throws \moodle_exception If the version was ever published.
     */
    public static function delete_unpublished(int $versionid): void {
        global $DB;
        $version = self::get($versionid);
        if ((int) $version->timepublished > 0 || $version->status === status::PUBLISHED) {
            throw new \moodle_exception('error_versionnotdeletable', 'local_nextpool');
        }
        $item = item_manager::get((int) $version->itemid);
        $event = version_deleted::create_from_version($version, $item);
        $itemcontextid = (int) $item->contextid;
        $transaction = $DB->start_delegated_transaction();
        $DB->set_field('local_nextpool_comment', 'versionid', null, ['versionid' => $versionid]);
        $DB->set_field(self::TABLE, 'basedonversionid', null, ['basedonversionid' => $versionid]);
        $DB->delete_records(self::LOG_TABLE, ['versionid' => $versionid]);
        $DB->delete_records('local_nextpool_comment_link', ['versionid' => $versionid]);
        $DB->delete_records(self::TABLE, ['id' => $versionid]);
        $transaction->allow_commit();

        $fs = get_file_storage();
        preview_assets::delete($versionid);
        $fs->delete_area_files(\context_system::instance()->id, 'local_nextpool', self::FILEAREA, $versionid);
        if ($itemcontextid) {
            $fs->delete_area_files($itemcontextid, 'local_nextpool', self::FILEAREA_XAPIPACKAGE, $versionid);
        }
        $event->trigger();
    }

    /**
     * Move the unpacked packages of an item's versions to the item's new context.
     *
     * @param int $itemid Item id.
     * @param int $fromcontextid Previous item context.
     * @param int $tocontextid New item context.
     */
    public static function move_packages(int $itemid, int $fromcontextid, int $tocontextid): void {
        global $DB;
        if ($fromcontextid === $tocontextid) {
            return;
        }
        $fs = get_file_storage();
        foreach ($DB->get_fieldset(self::TABLE, 'id', ['itemid' => $itemid]) as $versionid) {
            foreach ([self::FILEAREA_XAPIPACKAGE, 'scormpackage'] as $area) {
                $files = $fs->get_area_files($fromcontextid, 'local_nextpool', $area, $versionid, 'id', false);
                foreach ($files as $file) {
                    $fs->create_file_from_storedfile(['contextid' => $tocontextid], $file);
                }
                $fs->delete_area_files($fromcontextid, 'local_nextpool', $area, $versionid);
            }
        }
    }

    /**
     * The newest published version of an item.
     *
     * @param int $itemid Item id.
     * @return \stdClass|null
     */
    private static function find_current_version(int $itemid): ?\stdClass {
        $published = self::get_versions($itemid, status::PUBLISHED);
        return $published ? $published[0] : null;
    }
}
