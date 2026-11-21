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

namespace local_nextpool;

use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\version_manager;

/**
 * Public interface of the catalogue for other plugins (F07, task82).
 *
 * Other plugins, such as mod_nextpool (M06.AC08), use only this class; everything below local\ may change.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api {
    /** @var int Uuids per query in {@see get_placements_by_uuid()}; keeps the number of parameters low on all databases. */
    private const PLACEMENT_BATCH = 500;

    /**
     * Whether a user may propose a new version of the catalogue item of an activity (F07.AC09, AC18).
     *
     * True for activities placed from the catalogue whose item is available in the course, if the user holds
     * local/nextpool:proposeversions in the course.
     *
     * @param int $cmid Course module id.
     * @param int $userid User id, 0 for the current user.
     * @return bool
     */
    public static function can_propose(int $cmid, int $userid = 0): bool {
        $placement = placement_manager::get_by_cmid($cmid);
        if ($placement === null) {
            return false;
        }
        $item = item_manager::get((int) $placement->itemid);
        return access::can_propose($item, (int) $placement->courseid, $userid ?: null);
    }

    /**
     * Page on which the current user proposes a new version of the catalogue item of an activity (F07, M06.AC08).
     *
     * @param int $cmid Course module id.
     * @return \moodle_url|null Null if the current user may not propose one ({@see can_propose()}).
     */
    public static function get_propose_url(int $cmid): ?\moodle_url {
        if (!self::can_propose($cmid)) {
            return null;
        }
        return new \moodle_url('/local/nextpool/propose.php', ['cmid' => $cmid]);
    }

    /**
     * Whether the current user may open the editorial catalogue (task179).
     *
     * Logged in, not a guest, and local/nextpool:manageitems or local/nextpool:reviewversions in the system context
     * or in any course category. The same check decides about the "Nextpool" entries in the user menu and the top
     * navigation; launchers of other plugins, such as the LernHive launcher, use it for their own entry.
     *
     * @return bool
     */
    public static function can_open_editorial(): bool {
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return false;
        }
        return \core_course_category::has_capability_on_any([access::MANAGE, access::REVIEW]);
    }

    /**
     * Start page of the editorial catalogue (task179).
     *
     * @return \moodle_url
     */
    public static function get_editorial_url(): \moodle_url {
        return new \moodle_url('/local/nextpool/items.php');
    }

    /**
     * Placements of catalogue items, by item uuid (task181, LernHive ADR-P31).
     *
     * Stable public contract for other plugins such as LernHive Certify; see "Public interface for other plugins"
     * in docs/03-dev-doc.md. Fields are only ever added, never renamed or removed.
     *
     * Every distinct, non-empty uuid passed in is a key of the result, with an empty list if no item has that
     * uuid or the item has no placement. Only course modules that exist and are not being deleted count: a
     * course module in the recycle bin or in an asynchronous deletion (deletioninprogress) is left out, and
     * placements of deleted course modules and courses are already gone (observers in db/events.php).
     * Hidden course modules and hidden courses are included.
     *
     * No capability check: the result holds no personal data, and the caller decides what the person it acts
     * for may see (course access, cm_info::$uservisible). Reads with one query per 500 uuids.
     *
     * Fields of each placement:
     * - placementid (int), cmid (int), courseid (int), modname (string, module of the course module, e.g. "page")
     * - itemid (int), itemuuid (string)
     * - appliedversionid (int) and appliedversionnr (int): the version in the course module
     * - pendingversionid (int|null) and pendingversionnr (int|null): newer version waiting for the teacher
     * - policy (string): auto, notify or pinned
     * - timeplaced (int): when the item was placed (or the course module restored)
     * - timeapplied (int): when the applied version arrived in the course module (placed, updated or restored)
     *
     * @param string[] $uuids Item uuids.
     * @return array<string, \stdClass[]> Placements per uuid, each list ordered by course, time placed and id.
     */
    public static function get_placements_by_uuid(array $uuids): array {
        global $DB;
        $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids), static fn($uuid) => $uuid !== '')));
        $result = array_fill_keys($uuids, []);
        $actions = [
            placement_manager::ACTION_PLACE,
            placement_manager::ACTION_UPDATE,
            placement_manager::ACTION_ALONGSIDE,
            placement_manager::ACTION_RESTORE,
        ];
        foreach (array_chunk($uuids, self::PLACEMENT_BATCH) as $chunk) {
            [$uuidsql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'uuid');
            [$logsql, $logparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'loguuid');
            [$actionsql, $actionparams] = $DB->get_in_or_equal($actions, SQL_PARAMS_NAMED, 'action');
            $records = $DB->get_records_sql(
                'SELECT p.id, p.cmid, p.courseid, m.name AS modname, p.itemid, i.uuid AS itemuuid,
                        p.appliedversionid, va.versionnr AS appliedversionnr,
                        p.pendingversionid, vp.versionnr AS pendingversionnr,
                        p.policy, p.timeplaced, la.timeapplied
                   FROM {' . placement_manager::TABLE . '} p
                   JOIN {' . item_manager::TABLE . '} i ON i.id = p.itemid
                   JOIN {course_modules} cm ON cm.id = p.cmid AND cm.deletioninprogress = 0
                   JOIN {modules} m ON m.id = cm.module
                   JOIN {course} c ON c.id = p.courseid
              LEFT JOIN {' . version_manager::TABLE . '} va ON va.id = p.appliedversionid
              LEFT JOIN {' . version_manager::TABLE . '} vp ON vp.id = p.pendingversionid
              LEFT JOIN (
                        SELECT l.placementid, MAX(l.timecreated) AS timeapplied
                          FROM {' . placement_manager::LOG_TABLE . '} l
                          JOIN {' . placement_manager::TABLE . '} lp ON lp.id = l.placementid
                                AND lp.appliedversionid = l.toversionid
                          JOIN {' . item_manager::TABLE . '} li ON li.id = lp.itemid
                         WHERE li.uuid ' . $logsql . ' AND l.action ' . $actionsql . ' AND l.result = :ok
                      GROUP BY l.placementid
                        ) la ON la.placementid = p.id
                  WHERE i.uuid ' . $uuidsql . '
               ORDER BY p.courseid, p.timeplaced, p.id',
                $params + $logparams + $actionparams + ['ok' => 'ok']
            );
            foreach ($records as $record) {
                $result[(string) $record->itemuuid][] = (object) [
                    'placementid' => (int) $record->id,
                    'cmid' => (int) $record->cmid,
                    'courseid' => (int) $record->courseid,
                    'modname' => (string) $record->modname,
                    'itemid' => (int) $record->itemid,
                    'itemuuid' => (string) $record->itemuuid,
                    'appliedversionid' => (int) $record->appliedversionid,
                    'appliedversionnr' => (int) $record->appliedversionnr,
                    'pendingversionid' => $record->pendingversionid !== null ? (int) $record->pendingversionid : null,
                    'pendingversionnr' => $record->pendingversionnr !== null ? (int) $record->pendingversionnr : null,
                    'policy' => (string) $record->policy,
                    'timeplaced' => (int) $record->timeplaced,
                    'timeapplied' => (int) ($record->timeapplied ?? $record->timeplaced),
                ];
            }
        }
        return $result;
    }
}
