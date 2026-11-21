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

use local_nextpool\local\source\base as source_base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\path;

/**
 * Review decisions on versions in review: publish into the storage location, return for revision, reject (F07, task80).
 *
 * Publishing writes the snapshot into the item's storage location before the status changes (TD-306): a published
 * version without its file in the storage location would be worse than a surplus draft. The written file is recorded
 * on the version, so the storage location check does not import it again. If the file in the storage location
 * changed since the latest version, publishing stops (q74); {@see sync_now()} imports the change as a draft.
 *
 * Domain layer like {@see version_manager}: the methods do not check capabilities; review.php checks
 * {@see access::can_review()}.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class review_manager {
    /** @var string Folder for proposed new items in the storage location for direct uploads (q75). */
    public const FOLDER_PROPOSALS = 'vorschlaege';
    /** @var string Error code when the file in the storage location changed since the latest version (q74). */
    public const ERROR_SOURCECHANGED = 'error_sourcechanged';

    /**
     * Whether publishing a version needs a storage location and folder chosen by the reviewer (q75).
     *
     * True for items without any numbered version: a proposed new item exists only as snapshot in Moodle until it
     * is published.
     *
     * @param \stdClass $item Item record.
     * @return bool
     */
    public static function needs_location(\stdClass $item): bool {
        return version_manager::get_latest((int) $item->id) === null;
    }

    /**
     * Default folder for a proposed new item in the storage location for direct uploads (q75).
     *
     * @param \stdClass $version Version record.
     * @return string Path relative to the base folder, e.g. "vorschlaege/kurs-12".
     */
    public static function get_default_folder(\stdClass $version): string {
        return empty($version->courseid) ? self::FOLDER_PROPOSALS : self::FOLDER_PROPOSALS . '/kurs-' . (int) $version->courseid;
    }

    /**
     * Publish a version in review: write it into the storage location, then publish it (TD-306, F07.AC03).
     *
     * Steps: (1) the storage location must be enabled, writable and reachable, otherwise nothing changes and the
     * exception names the reason (F07.AC08); (2) if the file changed since the latest version, publishing stops
     * with {@see ERROR_SOURCECHANGED} (q74); (3) the snapshot replaces the file of the latest version, or for a new
     * item goes to the chosen storage location and folder (q75); (4) the written file is recorded on the version;
     * (5) the status changes in one transaction: the version gets its number, becomes current and is distributed to
     * placements by the observer; an item in draft or review is published with it, a withdrawn item stays withdrawn
     * (task104). If step 5 fails after the write, the write is undone: a new file is deleted, a replaced file gets
     * the content of the latest version back (bug161). Then the person who created or submitted the version and
     * the subscribers of the item are notified.
     *
     * The steps run under a lock per item, so two versions of an item cannot be published at the same time: the
     * storage location never holds another content than the version published last (bug161).
     *
     * A numbered version older than the latest version is published without writing (t80-c): the storage
     * location already holds a newer state. A version whose file is already the one in the storage location is not
     * written again.
     *
     * @param int $versionid Version id, in review.
     * @param array $options 'userid' (0 for the current user), 'changelog' and 'changelogformat' (replace the change
     *     log), 'h5plibraries' (q39), and for a new item 'sourceid' and 'folder' (default: storage location for
     *     direct uploads and {@see get_default_folder()}), 'resolvecomments' (ids of comments the version takes up
     *     that are marked as done with it, F08 "Erledigt").
     * @param source_base|null $source The storage location if the caller has it already (tests), null to load it.
     * @return \stdClass The published version.
     * @throws \moodle_exception error_publishfailed with the reason, {@see ERROR_SOURCECHANGED}, or a workflow error.
     */
    public static function publish(int $versionid, array $options = [], ?source_base $source = null): \stdClass {
        $itemid = (int) version_manager::get($versionid)->itemid;
        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('publish_item_' . $itemid, 0);
        if (!$lock) {
            throw new \moodle_exception(
                'error_publishfailed',
                'local_nextpool',
                '',
                get_string('error_publishbusy', 'local_nextpool')
            );
        }
        try {
            return self::publish_locked($versionid, $options, $source);
        } finally {
            $lock->release();
        }
    }

    /**
     * The steps of {@see publish()}, under the lock of the item.
     *
     * @param int $versionid Version id, in review.
     * @param array $options Options of {@see publish()}.
     * @param source_base|null $source The storage location if the caller has it already, null to load it.
     * @return \stdClass The published version.
     */
    private static function publish_locked(int $versionid, array $options, ?source_base $source): \stdClass {
        global $DB, $USER;
        $userid = (int) ($options['userid'] ?? 0) ?: (int) $USER->id;
        // Read inside the lock: a publish that just finished may have changed the version or the latest one.
        $version = version_manager::get($versionid);
        if ($version->status !== status::REVIEW) {
            throw new \moodle_exception('error_statustransition', 'local_nextpool', '', [
                'from' => $version->status,
                'to' => status::PUBLISHED,
            ]);
        }
        $item = item_manager::get((int) $version->itemid);
        $latest = version_manager::get_latest((int) $item->id);

        $target = self::prepare_write($item, $version, $latest, $options, $source);
        $entry = null;
        if ($target !== null) {
            [$source, $path] = $target;
            $snapshot = version_manager::get_file($versionid);
            if ($snapshot === null) {
                throw new \moodle_exception(
                    'error_publishfailed',
                    'local_nextpool',
                    '',
                    get_string('error_nosnapshot', 'local_nextpool')
                );
            }
            $tempfile = make_request_directory() . '/snapshot';
            $snapshot->copy_content_to($tempfile);
            try {
                $entry = $source->write($path, $tempfile);
            } catch (\moodle_exception $e) {
                throw new \moodle_exception('error_publishfailed', 'local_nextpool', '', $e->getMessage());
            }
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $version = self::publish_records($version, $item, $latest, $entry, $source, $options, $userid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                // The method rollback() throws the error again; undo the write once the records are back.
                unset($rethrown);
            }
            if ($entry !== null) {
                self::undo_write($source, $path, $latest);
            }
            throw $e;
        }

        self::notify($version, version_manager::LOG_PUBLISHED, '', $userid);
        return $version;
    }

    /**
     * Step 5 of {@see publish()}: record the written file, publish the version and the item, resolve comments.
     *
     * @param \stdClass $version Version in review.
     * @param \stdClass $item Its item.
     * @param \stdClass|null $latest Latest numbered version before publishing.
     * @param entry|null $entry The written file, null if nothing was written.
     * @param source_base|null $source Storage location written to.
     * @param array $options Options of {@see publish()}.
     * @param int $userid Publishing user.
     * @return \stdClass The published version.
     */
    private static function publish_records(
        \stdClass $version,
        \stdClass $item,
        ?\stdClass $latest,
        ?entry $entry,
        ?source_base $source,
        array $options,
        int $userid
    ): \stdClass {
        global $DB;
        $versionid = (int) $version->id;
        $update = ['id' => $versionid];
        if ($entry !== null) {
            $update += [
                'sourceref' => $entry->path,
                'sourcerevision' => $entry->revision !== '' ? $entry->revision : null,
                'sourceexternalid' => $entry->externalid !== '' ? $entry->externalid : null,
            ];
        }
        if (array_key_exists('changelog', $options)) {
            $changelog = trim((string) $options['changelog']);
            $update['changelog'] = $changelog !== '' ? $changelog : null;
            $update['changelogformat'] = (int) ($options['changelogformat'] ?? FORMAT_HTML);
        }
        if (count($update) > 1) {
            $DB->update_record(version_manager::TABLE, (object) $update);
        }
        if ($latest === null && $source !== null && (int) $item->sourceid !== $source->get_id()) {
            // A proposed new item lives in the storage location chosen when publishing (q75).
            $DB->set_field(item_manager::TABLE, 'sourceid', $source->get_id(), ['id' => $item->id]);
        }
        $version = version_manager::set_status($versionid, status::PUBLISHED, $userid, !empty($options['h5plibraries']));
        // Publishing a version publishes an item that was not published yet; a withdrawn one stays (task104).
        $item = item_manager::get((int) $item->id);
        if ($item->status === status::DRAFT) {
            $item = item_manager::set_status((int) $item->id, status::REVIEW, $userid);
        }
        if ($item->status === status::REVIEW) {
            item_manager::set_status((int) $item->id, status::PUBLISHED, $userid);
        }
        self::resolve_comments($versionid, (array) ($options['resolvecomments'] ?? []), $userid);
        return $version;
    }

    /**
     * Undo the write of a publish whose records could not be changed (bug161).
     *
     * A new file is deleted; a replaced file gets the content of the latest version back, and the latest version
     * records the restored revision, so the next check and the next publish do not see a change. Errors are only
     * reported for developers: the original error is the one the reviewer has to see.
     *
     * @param source_base $source Storage location.
     * @param string $path Path that was written.
     * @param \stdClass|null $latest Latest numbered version whose file was replaced, null if the file is new.
     */
    private static function undo_write(source_base $source, string $path, ?\stdClass $latest): void {
        global $DB;
        $restored = direct_upload::undo_write($source, $path, $latest);
        if ($restored !== null && $latest !== null && $restored->revision !== '') {
            try {
                $DB->set_field(version_manager::TABLE, 'sourcerevision', $restored->revision, ['id' => $latest->id]);
            } catch (\Throwable $e) {
                debugging('Publish: could not record the restored revision of ' . $path . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Mark comments a published version takes up as done (F08 "Erledigt", task80/task82).
     *
     * Only open top-level comments linked to the version are changed; other ids are ignored.
     *
     * @param int $versionid Version id.
     * @param int[] $commentids Comment ids the reviewer chose.
     * @param int $userid Reviewer.
     */
    private static function resolve_comments(int $versionid, array $commentids, int $userid): void {
        $commentids = array_map('intval', $commentids);
        foreach (proposal_manager::get_linked_comments($versionid) as $comment) {
            $open = empty($comment->deleted) && empty($comment->parentid) && empty($comment->timeresolved);
            if ($open && in_array((int) $comment->id, $commentids, true)) {
                comment_manager::set_resolved((int) $comment->id, true, $userid);
            }
        }
    }

    /**
     * Find out where publishing writes, and check the storage location before anything changes.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version to publish.
     * @param \stdClass|null $latest Latest numbered version of the item.
     * @param array $options Options of {@see publish()}.
     * @param source_base|null $source Storage location given by the caller.
     * @return array|null [source_base, path] to write to, null if nothing needs to be written.
     * @throws \moodle_exception error_publishfailed or {@see ERROR_SOURCECHANGED}.
     */
    private static function prepare_write(
        \stdClass $item,
        \stdClass $version,
        ?\stdClass $latest,
        array $options,
        ?source_base $source
    ): ?array {
        if ($latest !== null && $version->versionnr !== null && (int) $version->id !== (int) $latest->id) {
            // An older numbered version: the storage location holds a newer state, which stays (t80-c).
            return null;
        }
        try {
            if ($latest === null) {
                // A proposed new item: the reviewer chooses where it goes (q75).
                $sourceid = (int) ($options['sourceid'] ?? 0) ?: direct_upload::get_sourceid();
                if ($source === null) {
                    if (!$sourceid) {
                        throw new \moodle_exception('error_nouploadsource', 'local_nextpool');
                    }
                    $source = source_manager::get($sourceid);
                }
                $folder = path::normalise((string) ($options['folder'] ?? self::get_default_folder($version)));
                $path = path::join($folder, path::normalise((string) $version->filename));
                self::require_writable($source);
                if ($source->stat($path) !== null) {
                    // Never replace a file that may belong to another item.
                    throw new source\source_exception('error_fileexists', s($path));
                }
                return [$source, $path];
            }
            $source = $source ?? source_manager::get((int) $item->sourceid);
            self::require_writable($source);
            $path = (string) $latest->sourceref;
            $current = $source->stat($path);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('error_publishfailed', 'local_nextpool', '', $e->getMessage());
        }
        if ($current !== null && $current->isfolder) {
            throw new \moodle_exception(
                'error_publishfailed',
                'local_nextpool',
                '',
                get_string('error_notafile', 'local_nextpool')
            );
        }
        $isthisversion = $current !== null && (int) $version->id === (int) $latest->id && $current->revision !== ''
            && $current->revision === (string) $version->sourcerevision;
        if ($isthisversion) {
            // The file in the storage location already is this version, e.g. a draft from the storage location.
            return null;
        }
        $changed = $current !== null && $current->revision !== '' && (string) $latest->sourcerevision !== ''
            && $current->revision !== (string) $latest->sourcerevision;
        if ($changed) {
            // Changed since the latest version: never overwrite it unseen (q74).
            throw new \moodle_exception(self::ERROR_SOURCECHANGED, 'local_nextpool');
        }
        return [$source, $path];
    }

    /**
     * Throw unless a storage location can be written to.
     *
     * @param source_base $source Storage location.
     * @throws source\source_exception
     */
    private static function require_writable(source_base $source): void {
        if (!$source->supports_write()) {
            throw new source\source_exception('error_sourcereadonly');
        }
    }

    /**
     * Return a version in review for revision, with a reason (F07.AC04, AC05).
     *
     * @param int $versionid Version id, in review.
     * @param string $reason Reason, required.
     * @param int $userid Deciding user, 0 for the current user.
     * @param int $reasonformat Format of the reason.
     * @return \stdClass The version, now a draft.
     * @throws \moodle_exception If the reason is missing or the transition is not allowed.
     */
    public static function return_for_revision(
        int $versionid,
        string $reason,
        int $userid = 0,
        int $reasonformat = FORMAT_PLAIN
    ): \stdClass {
        return self::decide($versionid, status::DRAFT, $reason, $userid, $reasonformat);
    }

    /**
     * Reject a version in review, with a reason (F07.AC04, AC06).
     *
     * @param int $versionid Version id, in review.
     * @param string $reason Reason, required.
     * @param int $userid Deciding user, 0 for the current user.
     * @param int $reasonformat Format of the reason.
     * @return \stdClass The rejected version.
     * @throws \moodle_exception If the reason is missing or the transition is not allowed.
     */
    public static function reject(int $versionid, string $reason, int $userid = 0, int $reasonformat = FORMAT_PLAIN): \stdClass {
        return self::decide($versionid, status::REJECTED, $reason, $userid, $reasonformat);
    }

    /**
     * Return or reject a version in review and notify.
     *
     * @param int $versionid Version id.
     * @param string $to status::DRAFT or status::REJECTED.
     * @param string $reason Reason.
     * @param int $userid Deciding user, 0 for the current user.
     * @param int $reasonformat Format of the reason.
     * @return \stdClass The version.
     */
    private static function decide(int $versionid, string $to, string $reason, int $userid, int $reasonformat): \stdClass {
        global $USER;
        $userid = $userid ?: (int) $USER->id;
        $from = version_manager::get($versionid)->status;
        if ($from !== status::REVIEW) {
            throw new \moodle_exception('error_statustransition', 'local_nextpool', '', [
                'from' => $from,
                'to' => $to,
            ]);
        }
        $version = version_manager::set_status($versionid, $to, $userid, false, $reason, $reasonformat);
        if ($to === status::REJECTED) {
            // A rejected new item no longer waits for review (t82-c).
            proposal_manager::release_new_item((int) $version->itemid, $userid);
        }
        $action = $to === status::DRAFT ? version_manager::LOG_RETURNED : version_manager::LOG_REJECTED;
        self::notify($version, $action, trim($reason), $userid);
        return $version;
    }

    /**
     * "Check now" after a conflict: import the changed file of the storage location as a draft (q74, q73).
     *
     * The draft is based on the latest version and counts as created by the storage location check; the version
     * in review stays in review.
     *
     * @param int $itemid Item id.
     * @param source_base|null $source The item's storage location if the caller has it already, null to load it.
     * @return \stdClass|null The new draft, null if the file did not change.
     * @throws \moodle_exception On storage location errors.
     */
    public static function sync_now(int $itemid, ?source_base $source = null): ?\stdClass {
        $source = $source ?? source_manager::get((int) item_manager::get($itemid)->sourceid);
        $latest = version_manager::get_latest($itemid);
        $entry = version_manager::check_for_update($itemid, $source);
        if ($entry === null) {
            return null;
        }
        try {
            return version_manager::create_from_source($itemid, $entry->path, [
                'createdby' => 0,
                'basedonversionid' => $latest ? (int) $latest->id : 0,
            ], $source);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'error_unchanged') {
                // Same content behind a new revision: recorded, nothing to review.
                return null;
            }
            throw $e;
        }
    }

    /**
     * Whether a version builds on an older version than the current one (F07.AC07).
     *
     * @param \stdClass $version Version record.
     * @return array|null ['base' => version record, 'current' => version record] if outdated, else null.
     */
    public static function get_outdated_base(\stdClass $version): ?array {
        if (empty($version->basedonversionid) || !in_array($version->status, [status::DRAFT, status::REVIEW], true)) {
            return null;
        }
        $item = item_manager::get((int) $version->itemid);
        if (empty($item->currentversionid)) {
            return null;
        }
        $base = version_manager::get((int) $version->basedonversionid);
        $current = version_manager::get((int) $item->currentversionid);
        if ($base->versionnr === null || (int) $current->versionnr <= (int) $base->versionnr) {
            return null;
        }
        return ['base' => $base, 'current' => $current];
    }

    /**
     * Display name of a version: "Version N", or for a proposal without number "Proposal by X on D" (q67).
     *
     * @param \stdClass $version Version record.
     * @param \stdClass|null $creator The user who created it, if the caller has the record already (lists, bug111).
     * @return string Plain text.
     */
    public static function get_version_name(\stdClass $version, ?\stdClass $creator = null): string {
        if ($version->versionnr !== null) {
            return get_string('versionnr', 'local_nextpool', (int) $version->versionnr);
        }
        if ($creator !== null && (int) $creator->id === (int) $version->createdby) {
            $user = $creator;
        } else {
            $user = $version->createdby ? \core_user::get_user((int) $version->createdby) : null;
        }
        return get_string('proposalname', 'local_nextpool', [
            'user' => $user ? fullname($user) : '-',
            'date' => userdate((int) $version->timecreated, get_string('strftimedatefullshort', 'core_langconfig')),
        ]);
    }

    /**
     * Notify about a review decision (F07.AC03, AC04, F08.AC04).
     *
     * The person who created the version and the person who last submitted it get "reviewdecision" with the reason.
     * Subscribers of the item get "versionpublished" on publishing, if they may still see the item (task81 rules,
     * {@see subscription_manager::can_receive()}); on returning and rejecting, only subscribers who see the item
     * editorially get "reviewdecision", because versions in review are not visible in courses (F07). The deciding
     * person gets nothing, and nobody gets two messages.
     *
     * @param \stdClass $version The version after the decision.
     * @param string $action version_manager::LOG_PUBLISHED, LOG_RETURNED or LOG_REJECTED.
     * @param string $reason Reason, '' if none.
     * @param int $userid Deciding user.
     */
    private static function notify(\stdClass $version, string $action, string $reason, int $userid): void {
        $item = item_manager::get((int) $version->itemid);
        $context = access::get_context($item);
        $reviewurl = (new \moodle_url('/local/nextpool/review.php', ['versionid' => $version->id]))->out(false);
        $arguments = static fn() => (object) [
            'item' => format_string($item->title, true, ['context' => $context, 'escape' => false]),
            'version' => self::get_version_name($version),
            'reason' => $reason,
            'url' => $reviewurl,
        ];
        $courseid = $version->courseid ? (int) $version->courseid : SITEID;

        $notified = [$userid => true];
        $recipients = [];
        if ((int) $version->createdby > 0) {
            $recipients[] = (int) $version->createdby;
        }
        foreach (array_reverse(version_manager::get_log((int) $version->id)) as $log) {
            if ($log->action === version_manager::LOG_SUBMITTED) {
                $recipients[] = (int) $log->userid;
                break;
            }
        }
        foreach (array_unique($recipients) as $recipient) {
            if (isset($notified[$recipient])) {
                continue;
            }
            $notified[$recipient] = true;
            self::send_message(
                'reviewdecision',
                $recipient,
                'reviewdecision_subject_' . $action,
                'reviewdecision_body_' . $action,
                $reviewurl,
                $courseid,
                $arguments
            );
        }

        foreach (subscription_manager::get_subscribers((int) $item->id) as $recipient => $subscription) {
            if (isset($notified[$recipient])) {
                continue;
            }
            if ($action !== version_manager::LOG_PUBLISHED) {
                // Returned and rejected versions are visible only editorially (t82-e).
                if (!access::can_comment($item, 0, $recipient)) {
                    continue;
                }
                $notified[$recipient] = true;
                self::send_message(
                    'reviewdecision',
                    $recipient,
                    'reviewdecision_subject_' . $action,
                    'reviewdecision_body_' . $action,
                    $reviewurl,
                    SITEID,
                    $arguments
                );
                continue;
            }
            if (!subscription_manager::can_receive($item, $subscription)) {
                continue;
            }
            $notified[$recipient] = true;
            $url = subscription_manager::get_item_url($item, $subscription)->out(false);
            $publishedarguments = static function () use ($arguments, $url) {
                $a = $arguments();
                $a->url = $url;
                return $a;
            };
            self::send_message(
                'versionpublished',
                $recipient,
                'versionpublished_subject',
                'versionpublished_body',
                $url,
                $subscription->courseid ? (int) $subscription->courseid : SITEID,
                $publishedarguments
            );
        }
    }

    /**
     * Send one notification from the catalogue (also used by {@see proposal_manager}).
     *
     * @param string $name Message provider.
     * @param int $userid Recipient.
     * @param string $subject Subject.
     * @param string $body Plain-text body.
     * @param string $url Context URL.
     * @param int $courseid Course of the message.
     * @param callable|null $arguments Optional localized placeholders; subject/body are then string identifiers.
     */
    public static function send_message(
        string $name,
        int $userid,
        string $subject,
        string $body,
        string $url,
        int $courseid,
        ?callable $arguments = null
    ): void {
        notification_sender::send($userid, static function () use ($name, $subject, $body, $url, $courseid, $arguments) {
            if ($arguments !== null) {
                $a = $arguments();
                $subject = get_string($subject, 'local_nextpool', $a);
                $body = get_string($body, 'local_nextpool', $a);
            }
            $message = new \core\message\message();
            $message->component = 'local_nextpool';
            $message->name = $name;
            $message->userfrom = \core_user::get_noreply_user();
            $message->subject = $subject;
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url;
            $message->contexturlname = $subject;
            $message->courseid = $courseid;
            return $message;
        });
    }
}
