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

use local_nextpool\event\placement_created;
use local_nextpool\event\placement_updated;
use local_nextpool\task\apply_update;
use local_nextpool\task\send_update_notice;

/**
 * Placements of catalogue items in courses and the update engine (F05, F06).
 *
 * A placement is a course module created from a catalogue item. Updates are
 * written into that course module (adr07), so learners keep their history.
 * What happens with a newly published version depends on the placement's
 * policy: auto applies it in an adhoc task, notify waits for the teacher,
 * pinned keeps the current version.
 *
 * Domain layer: capabilities are checked by the pages, except that placing
 * goes through core's create_module(), which requires the course capability.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class placement_manager {
    /** @var string Placement table. */
    public const TABLE = 'local_nextpool_placement';
    /** @var string Log table. */
    public const LOG_TABLE = 'local_nextpool_placement_log';

    /** @var string Log action: placed in the course. */
    public const ACTION_PLACE = 'place';
    /** @var string Log action: version written into the course module. */
    public const ACTION_UPDATE = 'update';
    /** @var string Log action: teacher was offered a version (policy notify). */
    public const ACTION_OFFER = 'offer';
    /** @var string Log action: teacher skipped an offered version. */
    public const ACTION_SKIP = 'skip';
    /** @var string Log action: policy changed. */
    public const ACTION_POLICY = 'policy';
    /** @var string Log action: placement recreated by a course restore, copy or import. */
    public const ACTION_RESTORE = 'restore';
    /** @var string Log action: an automatic update was not possible, the version waits for the teacher. */
    public const ACTION_DOWNGRADE = 'downgrade';
    /** @var string Log action: the item's competencies were linked with the course module (F14). */
    public const ACTION_COMPETENCY = 'competency';
    /** @var string Log action: the offered version was withdrawn by the editorial team (bug53). */
    public const ACTION_WITHDRAW = 'withdraw';
    /** @var string Log action: the version was placed as a new activity next to the old, now hidden one (q29). */
    public const ACTION_ALONGSIDE = 'alongside';

    /**
     * Place the current version of an item in a course.
     *
     * @param int $itemid Item id.
     * @param int $courseid Course id.
     * @param int $section Section number.
     * @param string $policy Update policy.
     * @param int $userid Acting user, 0 for the current user.
     * @param array $options Options for the type's handler, from its fields in the placement form (task45).
     * @param int $beforemod Position before this module in the same course and section, 0 to append.
     * @return \stdClass The placement; competencyresults holds the result per competency of the item (F14).
     * @throws \moodle_exception If the item is not published, not available in the course or not placeable, or the
     *     handler refuses the version (check_place(), bug88).
     */
    public static function place(
        int $itemid,
        int $courseid,
        int $section,
        string $policy,
        int $userid = 0,
        array $options = [],
        int $beforemod = 0
    ): \stdClass {
        global $USER, $CFG;
        $userid = $userid ?: (int) $USER->id;
        $item = item_manager::get($itemid);
        $course = get_course($courseid);

        if (!policy::is_valid($policy)) {
            throw new \moodle_exception('error_invalidpolicy', 'local_nextpool');
        }
        if ($item->status !== status::PUBLISHED || empty($item->currentversionid)) {
            throw new \moodle_exception('error_notpublished', 'local_nextpool');
        }
        if (!catalogue::is_available_in_course($item, $courseid)) {
            throw new \moodle_exception('error_notavailableincourse', 'local_nextpool');
        }
        $handler = item_types::get_handler($item->type);
        if ($handler === null) {
            throw new \moodle_exception('error_notplaceable', 'local_nextpool');
        }

        $version = version_manager::get((int) $item->currentversionid);
        $reason = $handler->check_place($item, $version);
        if ($reason !== null) {
            throw new \moodle_exception('error_cannotplace', 'local_nextpool', '', $reason);
        }
        if ($beforemod) {
            $before = get_fast_modinfo($course)->get_cm($beforemod);
            if ((int) $before->course !== $courseid || (int) $before->sectionnum !== $section) {
                throw new \moodle_exception('invalidcoursemodule');
            }
        }
        // A handler removes its course module itself if it fails after creating it (base::create_module_cleanly()).
        $cmid = $handler->place($item, $version, $course, $section, $options);

        try {
            if ($beforemod) {
                require_once($CFG->dirroot . '/course/lib.php');
                $actions = \core_courseformat\formatactions::cm($courseid);
                if (method_exists($actions, 'move_before')) {
                    $actions->move_before($cmid, $before->id);
                } else {
                    // Moodle 5.0/5.1 do not yet expose this course-format action.
                    $modinfo = get_fast_modinfo($courseid);
                    moveto_module($modinfo->get_cm($cmid), $modinfo->get_section_info($section), $before->id);
                }
            }
            [$placement, $results] = self::insert($item, $courseid, $cmid, $policy, (int) $version->id, $userid);
        } catch (\Throwable $e) {
            // Placing is all or nothing: without its placement the course module would be an orphan, and placing
            // again would create a second one (bug55). Deleting it also removes a placement row already written.
            content_type\base::delete_module_quietly($cmid, $courseid);
            throw $e;
        }

        placement_created::create_from_placement($placement, $item)->trigger();
        $placement->competencyresults = $results;
        return $placement;
    }

    /**
     * Record a placement for a course module that was created outside the catalogue (M06 direct upload, TD-405).
     *
     * For the activity form, where core creates the course module and its instance: the item's current version
     * becomes the applied version without writing anything into the course module. Logged as "place" and
     * reported with the event placement_created, like {@see place()}. Capabilities are checked by the caller.
     *
     * @param int $itemid Item id.
     * @param int $cmid Course module, of the module the item's type places.
     * @param string $policy Update policy.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The placement; competencyresults holds the result per competency of the item (F14).
     * @throws \moodle_exception If the item is not published or not available in the course, the course module is of
     *     another module or already has a placement.
     */
    public static function register(int $itemid, int $cmid, string $policy, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $item = item_manager::get($itemid);
        if (!policy::is_valid($policy)) {
            throw new \moodle_exception('error_invalidpolicy', 'local_nextpool');
        }
        if ($item->status !== status::PUBLISHED || empty($item->currentversionid)) {
            throw new \moodle_exception('error_notpublished', 'local_nextpool');
        }
        $cm = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid',
            ['cmid' => $cmid],
            MUST_EXIST
        );
        if (!catalogue::is_available_in_course($item, (int) $cm->course)) {
            throw new \moodle_exception('error_notavailableincourse', 'local_nextpool');
        }
        $handler = item_types::get_handler($item->type);
        if ($handler === null || $handler->get_modname() !== $cm->modname) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        if ($DB->record_exists(self::TABLE, ['cmid' => $cmid])) {
            throw new \moodle_exception('error_alreadyplaced', 'local_nextpool');
        }

        $versionid = (int) $item->currentversionid;
        [$placement, $results] = self::insert($item, (int) $cm->course, $cmid, $policy, $versionid, $userid);
        placement_created::create_from_placement($placement, $item)->trigger();
        $placement->competencyresults = $results;
        return $placement;
    }

    /**
     * Insert a placement, log it and link the item's competencies with the course module.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course id.
     * @param int $cmid Course module id.
     * @param string $policy Update policy.
     * @param int $versionid Applied version.
     * @param int $userid Acting user.
     * @return array{0:\stdClass,1:array} The stored placement and the competency results (F14).
     */
    private static function insert(
        \stdClass $item,
        int $courseid,
        int $cmid,
        string $policy,
        int $versionid,
        int $userid
    ): array {
        global $DB;
        $now = time();
        $placementid = (int) $DB->insert_record(self::TABLE, (object) [
            'itemid' => $item->id,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'policy' => $policy,
            'appliedversionid' => $versionid,
            'pendingversionid' => null,
            'placedby' => $userid,
            'timeplaced' => $now,
            'timeupdated' => $now,
        ]);
        self::log($placementid, self::ACTION_PLACE, null, $versionid, $userid);

        // Competencies of the item go onto the course module; what cannot be linked is reported, not fatal (F14).
        $results = item_competencies::link_to_cm((int) $item->id, $cmid);
        if ($results) {
            $summary = implode('; ', array_map(static fn($r) => $r['competency'] . ': ' . $r['result'], $results));
            // The level per cm belongs to LernHive Skills; hand it over when Skills offers its API (feat28).
            $skills = skills_bridge::propose_levels($cmid, (string) $item->uuid, $results);
            if ($skills >= 0) {
                $summary .= '; skills: ' . $skills;
            }
            $summary = \core_text::substr($summary, 0, 1000);
            self::log($placementid, self::ACTION_COMPETENCY, null, null, $userid, $summary);
        }
        return [self::get($placementid), $results];
    }

    /**
     * Write a version into the placement's course module.
     *
     * Takes a lock per placement, so a manual and an automatic update cannot run at the same time. The
     * placement is read and checked inside the lock, and a placement never goes back to an older version:
     * if it already carries this or a newer version, nothing changes.
     *
     * @param int $placementid Placement id.
     * @param int $versionid Version to apply, 0 for the item's current version.
     * @param int $userid Acting user, 0 for automatic updates.
     * @param bool $force Skip the handler's in-place check (check_update()). Only for a new package a teacher
     *     uploaded for an item of their own course and confirmed a different xAPI activity for (M06, TD-407).
     * @return \stdClass The placement.
     * @throws \moodle_exception If the version is not published, the lock is busy or the handler fails (logged).
     */
    public static function apply(int $placementid, int $versionid = 0, int $userid = 0, bool $force = false): \stdClass {
        return self::apply_version($placementid, $versionid, $userid, $force, false);
    }

    /**
     * Apply a version, checking automatic update consent under the placement lock (bug240).
     *
     * @param int $placementid Placement id.
     * @param int $versionid Version id, or 0 for the current version.
     * @param int $userid Acting user.
     * @param bool $force Skip the handler's in-place check.
     * @param bool $automatic Require the placement's current rule to be automatic.
     * @return \stdClass Placement, unchanged if automatic updates are no longer allowed.
     */
    private static function apply_version(
        int $placementid,
        int $versionid,
        int $userid,
        bool $force,
        bool $automatic
    ): \stdClass {
        global $DB;
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_nextpool');
        $lock = $lockfactory->get_lock('placement_' . $placementid, 10);
        if (!$lock) {
            throw new \moodle_exception('error_placementlocked', 'local_nextpool');
        }
        try {
            $placement = self::get($placementid);
            if ($automatic && $placement->policy !== policy::AUTO) {
                return $placement;
            }
            $item = item_manager::get((int) $placement->itemid);
            $versionid = $versionid ?: (int) $item->currentversionid;
            $version = version_manager::get($versionid);
            if ((int) $version->itemid !== (int) $item->id || $version->status !== status::PUBLISHED) {
                throw new \moodle_exception('error_versionnotapplicable', 'local_nextpool');
            }
            if (self::is_up_to_date($placement, $versionid)) {
                return $placement;
            }
            $handler = item_types::get_handler($item->type);
            if ($handler === null) {
                throw new \moodle_exception('error_notplaceable', 'local_nextpool');
            }

            $cm = self::get_cm($placement);
            if ($cm === null) {
                throw new \moodle_exception('error_placementgone', 'local_nextpool');
            }
            // The only check before writing: it runs inside the lock, on the placement as it is now (bug59).
            $reason = $force ? null : $handler->check_update($cm, $item, $version);
            if ($reason !== null) {
                throw new \moodle_exception('error_noinplace', 'local_nextpool', '', $reason);
            }

            $from = (int) $placement->appliedversionid;
            $intransaction = $DB->is_transaction_started();
            try {
                $handler->update_in_place($cm, $item, $version);
            } catch (\Throwable $e) {
                if (!$intransaction && $DB->is_transaction_started()) {
                    // The handler failed inside its own transaction and left it open. Roll it back here, or the
                    // log entry, the offer and the notice that follow are lost with it (bug160).
                    $DB->force_transaction_rollback();
                }
                self::log($placementid, self::ACTION_UPDATE, $from, $versionid, $userid, $e->getMessage());
                throw $e;
            }
            $DB->update_record(self::TABLE, (object) [
                'id' => $placementid,
                'appliedversionid' => $versionid,
                'pendingversionid' => null,
                'timeupdated' => time(),
            ]);
            self::log($placementid, self::ACTION_UPDATE, $from, $versionid, $userid);
        } finally {
            $lock->release();
        }

        $placement = self::get($placementid);
        placement_updated::create_from_placement($placement, $item, $from)->trigger();
        return $placement;
    }

    /**
     * Place a version as a new activity next to the placement's course module and hide the old one (q29).
     *
     * For versions the handler cannot write into the course module, e.g. a quiz with attempts whose questions
     * change. The placement moves to the new course module and keeps its policy and history; the old activity
     * with its attempts and grades stays hidden in the course and is never deleted (soft-replace, adr07).
     *
     * @param int $placementid Placement id.
     * @param int $versionid Version to place, 0 for the item's current version.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The placement.
     * @throws \moodle_exception If the version is not published, the type cannot do this or the lock is busy.
     */
    public static function place_alongside(int $placementid, int $versionid = 0, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_nextpool');
        $lock = $lockfactory->get_lock('placement_' . $placementid, 10);
        if (!$lock) {
            throw new \moodle_exception('error_placementlocked', 'local_nextpool');
        }
        try {
            $placement = self::get($placementid);
            $item = item_manager::get((int) $placement->itemid);
            $versionid = $versionid ?: (int) $item->currentversionid;
            $version = version_manager::get($versionid);
            if ((int) $version->itemid !== (int) $item->id || $version->status !== status::PUBLISHED) {
                throw new \moodle_exception('error_versionnotapplicable', 'local_nextpool');
            }
            if (self::is_up_to_date($placement, $versionid)) {
                return $placement;
            }
            $handler = item_types::get_handler($item->type);
            if ($handler === null || !$handler->can_place_alongside()) {
                throw new \moodle_exception('error_noalongside', 'local_nextpool');
            }
            $cm = self::get_cm($placement);
            if ($cm === null) {
                throw new \moodle_exception('error_placementgone', 'local_nextpool');
            }

            $from = (int) $placement->appliedversionid;
            $cmid = $handler->place_alongside($cm, $item, $version);
            try {
                $DB->update_record(self::TABLE, (object) [
                    'id' => $placementid,
                    'cmid' => $cmid,
                    'appliedversionid' => $versionid,
                    'pendingversionid' => null,
                    'timeupdated' => time(),
                ]);
                self::log($placementid, self::ACTION_ALONGSIDE, $from, $versionid, $userid, 'cm ' . $cm->id . ' -> ' . $cmid);
                $results = item_competencies::link_to_cm((int) $item->id, $cmid);
                if ($results) {
                    $summary = implode('; ', array_map(static fn($r) => $r['competency'] . ': ' . $r['result'], $results));
                    self::log($placementid, self::ACTION_COMPETENCY, null, null, $userid, \core_text::substr($summary, 0, 1000));
                }
            } catch (\Throwable $e) {
                content_type\base::delete_module_quietly($cmid, (int) $placement->courseid);
                \core_courseformat\formatactions::cm((int) $placement->courseid)->set_visibility((int) $cm->id, (int) $cm->visible);
                throw $e;
            }
        } finally {
            $lock->release();
        }

        $placement = self::get($placementid);
        placement_updated::create_from_placement($placement, $item, $from)->trigger();
        return $placement;
    }

    /**
     * React to a newly published version according to each placement's policy.
     *
     * Only the item's current version is distributed; republishing an older version changes nothing in courses.
     *
     * @param int $versionid Published version.
     * @return int Number of placements that got an update queued or offered.
     */
    public static function distribute(int $versionid): int {
        global $DB;
        $version = version_manager::get($versionid);
        $item = item_manager::get((int) $version->itemid);
        if ((int) $item->currentversionid !== $versionid) {
            return 0;
        }
        $count = 0;
        foreach ($DB->get_records(self::TABLE, ['itemid' => $item->id]) as $placement) {
            if (self::distribute_to($placement, $versionid)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Queue or offer a version for one placement according to its policy.
     *
     * Nothing happens if the placement carries this or a newer version, already waits for it, or the teachers
     * skipped it before: publishing a withdrawn version again does not repeat offers and notices (bug54).
     *
     * @param \stdClass $placement Placement record.
     * @param int $versionid Published version of the placement's item.
     * @return bool True if an update was queued or offered.
     */
    private static function distribute_to(\stdClass $placement, int $versionid): bool {
        global $DB;
        if (self::is_up_to_date($placement, $versionid) || (int) $placement->pendingversionid === $versionid) {
            return false;
        }
        switch ($placement->policy) {
            case policy::AUTO:
                $task = new apply_update();
                $task->set_custom_data(['placementid' => (int) $placement->id, 'versionid' => $versionid]);
                \core\task\manager::queue_adhoc_task($task, true);
                return true;
            case policy::NOTIFY:
                $skipped = $DB->record_exists(self::LOG_TABLE, [
                    'placementid' => $placement->id,
                    'action' => self::ACTION_SKIP,
                    'toversionid' => $versionid,
                ]);
                if ($skipped) {
                    return false;
                }
                self::offer((int) $placement->id, $versionid);
                return true;
            default:
                // Pinned: the course keeps its version; the placements page shows that a newer one exists.
                return false;
        }
    }

    /**
     * Take back offers of a version the editorial team no longer publishes (bug53).
     *
     * Placements waiting for the version stop waiting; if the item's current version is still newer than what
     * they carry, that one is distributed instead, so the teachers see what they can apply.
     *
     * @param int $versionid Version that was withdrawn.
     * @return int Number of placements whose offer was taken back.
     */
    public static function withdraw_offers(int $versionid): int {
        global $DB;
        $placements = $DB->get_records(self::TABLE, ['pendingversionid' => $versionid]);
        foreach ($placements as $placement) {
            $DB->set_field(self::TABLE, 'pendingversionid', null, ['id' => $placement->id]);
            self::log((int) $placement->id, self::ACTION_WITHDRAW, (int) $placement->appliedversionid, $versionid, 0);
            $current = (int) item_manager::get((int) $placement->itemid)->currentversionid;
            if ($current) {
                self::distribute_to(self::get((int) $placement->id), $current);
            }
        }
        return count($placements);
    }

    /**
     * Apply a version without a teacher (policy auto), falling back to an offer when that is not possible.
     *
     * If the handler reports that the update would lose learner data, or the
     * update fails, the version is offered to the teachers instead and they are
     * notified (downgrade to notify for this version; the policy stays auto).
     * A busy lock is passed on, so the adhoc task is retried. A version that is
     * no longer published, or a course module that is gone, ends the task quietly.
     *
     * @param int $placementid Placement id.
     * @param int $versionid Version to apply.
     * @return bool True if applied or nothing is left to do, false if offered instead.
     * @throws \moodle_exception If the placement is locked by another update.
     */
    public static function apply_automatically(int $placementid, int $versionid): bool {
        global $DB;
        $placement = self::get($placementid);
        if (self::is_up_to_date($placement, $versionid)) {
            // Nothing to do, and an older version must not be offered either. apply() checks again inside the lock.
            return true;
        }
        if ($DB->get_field(version_manager::TABLE, 'status', ['id' => $versionid]) !== status::PUBLISHED) {
            // Withdrawn after the update was queued: nothing to apply or offer (bug53).
            return true;
        }
        if (self::get_cm($placement) === null) {
            // The course module is being deleted; its placement goes with it. No retry (bug63, Q-24).
            return true;
        }
        try {
            // Recheck the current rule and whether the version can be written inside the lock (bug59, bug240).
            self::apply_version($placementid, $versionid, 0, false, true);
            return true;
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'error_placementlocked') {
                throw $e;
            }
            $reason = $e->errorcode === 'error_noinplace' ? (string) $e->a : $e->getMessage();
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }
        self::offer($placementid, $versionid, $reason);
        return false;
    }

    /**
     * The course module of a placement, unless it is gone or being deleted.
     *
     * @param \stdClass $placement Placement record.
     * @return \cm_info|null
     */
    public static function get_cm(\stdClass $placement): ?\cm_info {
        try {
            $cm = get_fast_modinfo((int) $placement->courseid)->get_cms()[(int) $placement->cmid] ?? null;
        } catch (\dml_missing_record_exception $e) {
            return null;
        }
        // Modinfo keeps a course module whose asynchronous deletion has started; it is gone for the catalogue.
        return $cm && empty($cm->deletioninprogress) ? $cm : null;
    }

    /**
     * Whether a placement already carries a version or a newer one of the same item.
     *
     * @param \stdClass $placement Placement record.
     * @param int $versionid Version to compare with.
     * @return bool True if applying the version would change nothing or go back; also true if the version is gone.
     */
    public static function is_up_to_date(\stdClass $placement, int $versionid): bool {
        global $DB;
        if ((int) $placement->appliedversionid === $versionid) {
            return true;
        }
        $target = $DB->get_field(version_manager::TABLE, 'versionnr', ['id' => $versionid]);
        if ($target === false) {
            return true;
        }
        $applied = $DB->get_field(version_manager::TABLE, 'versionnr', ['id' => (int) $placement->appliedversionid]);
        return $applied !== false && (int) $applied >= (int) $target;
    }

    /**
     * Offer a version to the teachers of the placement's course and notify them.
     *
     * @param int $placementid Placement id.
     * @param int $versionid Offered version.
     * @param string|null $reason Why an automatic update was not possible (downgrade), or null for a regular offer.
     * @param bool $notify Whether to notify the teachers; false when a teacher asked for the offer.
     */
    public static function offer(int $placementid, int $versionid, ?string $reason = null, bool $notify = true): void {
        global $DB;
        $placement = self::get($placementid);
        $DB->set_field(self::TABLE, 'pendingversionid', $versionid, ['id' => $placementid]);
        self::log(
            $placementid,
            $reason === null ? self::ACTION_OFFER : self::ACTION_DOWNGRADE,
            (int) $placement->appliedversionid,
            $versionid,
            0,
            $reason === null ? null : \core_text::substr($reason, 0, 1000)
        );
        if ($notify) {
            $task = new send_update_notice();
            $task->set_custom_data(['placementid' => $placementid, 'versionid' => $versionid]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Why the offered version of a placement could not be applied automatically.
     *
     * @param \stdClass $placement Placement record.
     * @return string|null Reason, or null if the offer is a regular one (policy notify) or nothing is offered.
     */
    public static function get_downgrade_reason(\stdClass $placement): ?string {
        global $DB;
        if (empty($placement->pendingversionid)) {
            return null;
        }
        $entries = $DB->get_records(self::LOG_TABLE, [
            'placementid' => $placement->id,
            'toversionid' => $placement->pendingversionid,
        ], 'timecreated DESC, id DESC', 'id, action, message', 0, 1);
        $latest = reset($entries);
        return $latest && $latest->action === self::ACTION_DOWNGRADE ? (string) $latest->message : null;
    }

    /**
     * Decline an offered version (policy notify).
     *
     * @param int $placementid Placement id.
     * @param int $userid Acting user, 0 for the current user.
     */
    public static function skip(int $placementid, int $userid = 0): void {
        global $DB, $USER;
        $placement = self::get($placementid);
        if (empty($placement->pendingversionid)) {
            return;
        }
        $DB->set_field(self::TABLE, 'pendingversionid', null, ['id' => $placementid]);
        self::log(
            $placementid,
            self::ACTION_SKIP,
            (int) $placement->appliedversionid,
            (int) $placement->pendingversionid,
            $userid ?: (int) $USER->id
        );
    }

    /**
     * Change the update policy. Switching to auto applies a newer current version right away (queued).
     *
     * @param int $placementid Placement id.
     * @param string $newpolicy New policy.
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass The placement.
     */
    public static function set_policy(int $placementid, string $newpolicy, int $userid = 0): \stdClass {
        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('placement_' . $placementid, 10);
        if (!$lock) {
            throw new \moodle_exception('error_placementlocked', 'local_nextpool');
        }
        try {
            return self::set_policy_locked($placementid, $newpolicy, $userid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Change the rule while no update can write the placement (bug240).
     *
     * @param int $placementid Placement id.
     * @param string $newpolicy Rule.
     * @param int $userid Acting user.
     * @return \stdClass Placement.
     */
    private static function set_policy_locked(int $placementid, string $newpolicy, int $userid): \stdClass {
        global $DB, $USER;
        if (!policy::is_valid($newpolicy)) {
            throw new \moodle_exception('error_invalidpolicy', 'local_nextpool');
        }
        $placement = self::get($placementid);
        if ($placement->policy === $newpolicy) {
            return $placement;
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $placementid,
            'policy' => $newpolicy,
            'pendingversionid' => null,
            'timeupdated' => time(),
        ]);
        self::log($placementid, self::ACTION_POLICY, null, null, $userid ?: (int) $USER->id, $newpolicy);

        $item = item_manager::get((int) $placement->itemid);
        $current = (int) $item->currentversionid;
        // Only a newer version is offered or queued: an older current version (the applied one was withdrawn)
        // cannot be applied, and its banner would never go away (bug166).
        $behind = $current && !self::is_up_to_date(self::get($placementid), $current);
        if ($newpolicy !== policy::PINNED && $behind) {
            // Bring the placement in line with its new policy as if the current version had just been published.
            if ($newpolicy === policy::AUTO) {
                $task = new apply_update();
                $task->set_custom_data(['placementid' => $placementid, 'versionid' => $current]);
                \core\task\manager::queue_adhoc_task($task, true);
            } else {
                // Logged like any offer (bug63, Q-20); the teacher who switched sees it on the page, no notice.
                self::offer($placementid, $current, null, false);
            }
        }
        return self::get($placementid);
    }

    /**
     * Load a placement.
     *
     * @param int $placementid Placement id.
     * @return \stdClass
     */
    public static function get(int $placementid): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $placementid], '*', MUST_EXIST);
    }

    /**
     * The placement of a course module.
     *
     * @param int $cmid Course module id.
     * @return \stdClass|null Null if the course module was not placed from the catalogue.
     */
    public static function get_by_cmid(int $cmid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['cmid' => $cmid]) ?: null;
    }

    /**
     * Placements in a course, in course order of their modules.
     *
     * @param int $courseid Course id.
     * @return \stdClass[] Keyed by id.
     */
    public static function get_for_course(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'timeplaced, id');
    }

    /**
     * Placements in a course with what the placements page shows, read with one query (bug56).
     *
     * @param int $courseid Course id.
     * @return \stdClass[] Placement records with itemtitle, itemcontextid, itemstatus, currentversionid and the version numbers
     *     appliedversionnr, currentversionnr and pendingversionnr (0 if none); keyed by id, in placement order.
     */
    public static function get_overview(int $courseid): array {
        global $DB;
        return $DB->get_records_sql(
            'SELECT p.*, i.title AS itemtitle, i.contextid AS itemcontextid, i.currentversionid, i.type AS itemtype,
                    i.status AS itemstatus,
                    COALESCE(va.versionnr, 0) AS appliedversionnr,
                    COALESCE(vc.versionnr, 0) AS currentversionnr,
                    COALESCE(vp.versionnr, 0) AS pendingversionnr
               FROM {' . self::TABLE . '} p
               JOIN {' . item_manager::TABLE . '} i ON i.id = p.itemid
          LEFT JOIN {' . version_manager::TABLE . '} va ON va.id = p.appliedversionid
          LEFT JOIN {' . version_manager::TABLE . '} vc ON vc.id = i.currentversionid
          LEFT JOIN {' . version_manager::TABLE . '} vp ON vp.id = p.pendingversionid
              WHERE p.courseid = :courseid
           ORDER BY p.timeplaced, p.id',
            ['courseid' => $courseid]
        );
    }

    /**
     * Number of placements of an item.
     *
     * @param int $itemid Item id.
     * @return int
     */
    public static function count_for_item(int $itemid): int {
        global $DB;
        return $DB->count_records(self::TABLE, ['itemid' => $itemid]);
    }

    /**
     * Number of placements in a course that wait for the teacher.
     *
     * @param int $courseid Course id.
     * @return int
     */
    public static function count_pending(int $courseid): int {
        global $DB;
        return $DB->count_records_select(self::TABLE, 'courseid = :courseid AND pendingversionid IS NOT NULL', [
            'courseid' => $courseid,
        ]);
    }

    /**
     * Log entries of a placement, newest first.
     *
     * @param int $placementid Placement id.
     * @return \stdClass[]
     */
    public static function get_log(int $placementid): array {
        global $DB;
        return array_values($DB->get_records(self::LOG_TABLE, ['placementid' => $placementid], 'timecreated DESC, id DESC'));
    }

    /**
     * Forget the placement of a deleted course module. The course module itself is gone already.
     *
     * @param int $cmid Course module id.
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;
        $placementid = $DB->get_field(self::TABLE, 'id', ['cmid' => $cmid]);
        if ($placementid) {
            $DB->delete_records(self::LOG_TABLE, ['placementid' => $placementid]);
            $DB->delete_records(self::TABLE, ['id' => $placementid]);
        }
    }

    /**
     * Forget all placements of a deleted course.
     *
     * @param int $courseid Course id.
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;
        $ids = $DB->get_fieldset(self::TABLE, 'id', ['courseid' => $courseid]);
        if ($ids) {
            $DB->delete_records_list(self::LOG_TABLE, 'placementid', $ids);
            $DB->delete_records_list(self::TABLE, 'id', $ids);
        }
    }

    /**
     * Write a log entry.
     *
     * @param int $placementid Placement id.
     * @param string $action Action constant.
     * @param int|null $from Version before.
     * @param int|null $to Version after or offered.
     * @param int $userid Acting user, 0 for automatic actions.
     * @param string|null $message Error message (result failed) or detail.
     */
    private static function log(
        int $placementid,
        string $action,
        ?int $from,
        ?int $to,
        int $userid,
        ?string $message = null
    ): void {
        global $DB;
        $failed = $message !== null && $action === self::ACTION_UPDATE;
        $DB->insert_record(self::LOG_TABLE, (object) [
            'placementid' => $placementid,
            'action' => $action,
            'fromversionid' => $from,
            'toversionid' => $to,
            'result' => $failed ? 'failed' : 'ok',
            'message' => $message,
            'userid' => $userid,
            'timecreated' => time(),
        ]);
    }
}
