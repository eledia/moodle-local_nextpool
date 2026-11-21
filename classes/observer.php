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

use local_nextpool\local\placement_manager;

/**
 * Event observers for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Queue distribution of a published version to its placements after commit (F06).
     *
     * @param \local_nextpool\event\version_published $event Event.
     */
    public static function version_published(\local_nextpool\event\version_published $event): void {
        $task = new \local_nextpool\task\distribute_version();
        $task->set_custom_data(['versionid' => (int) $event->objectid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Tell the reviewers about a version submitted for review (F07.AC14, task85).
     *
     * @param \local_nextpool\event\version_submitted $event Event.
     */
    public static function version_submitted(\local_nextpool\event\version_submitted $event): void {
        \local_nextpool\local\review_queue::notify_submitted((int) $event->objectid, (int) $event->userid);
    }

    /**
     * Subscribe the person who created an item to its feedback (F08, q66).
     *
     * @param \local_nextpool\event\item_created $event Event.
     */
    public static function item_created(\local_nextpool\event\item_created $event): void {
        \local_nextpool\local\subscription_manager::subscribe_automatically((int) $event->objectid, (int) $event->userid);
    }

    /**
     * Forget the placement when its course module is deleted.
     *
     * @param \core\event\course_module_deleted $event Event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        placement_manager::delete_for_cm((int) $event->objectid);
    }

    /**
     * Forget all placements of a deleted course.
     *
     * @param \core\event\course_deleted $event Event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        placement_manager::delete_for_course((int) $event->objectid);
    }

    /**
     * Give items whose course category is gone the system context (bug33).
     *
     * The deletion callbacks in lib.php move the items before core deletes the category; this catches items
     * left behind by a deletion that did not run them.
     *
     * @param \core\event\course_category_deleted $event Event.
     */
    public static function course_category_deleted(\core\event\course_category_deleted $event): void {
        \local_nextpool\local\item_manager::move_orphaned_items();
    }
}
