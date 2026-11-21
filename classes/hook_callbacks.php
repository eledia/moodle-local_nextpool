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

use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\review_queue;
use local_nextpool\local\status;

/**
 * Hook callbacks for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Whether the user gets the update banner: they must be able to open and act on the placements page.
     *
     * @param \context $context Course context.
     * @param int|null $userid User, null for the current user.
     * @return bool
     */
    public static function can_see_update_banner(\context $context, ?int $userid = null): bool {
        return has_all_capabilities(['local/nextpool:viewcatalogue', 'moodle/course:manageactivities'], $context, $userid);
    }

    /**
     * Banner for teachers when catalogue updates wait for them (policy notify, F06).
     *
     * Not announced as an alert: it shows on every visit of the course page (bug64).
     *
     * @param \context $context Course context.
     * @return string|null HTML, or null when there is nothing to show.
     */
    public static function get_update_banner(\context $context): ?string {
        global $OUTPUT;
        if ($context->contextlevel != CONTEXT_COURSE || !self::can_see_update_banner($context)) {
            return null;
        }
        $pending = placement_manager::count_pending((int) $context->instanceid);
        if ($pending === 0) {
            return null;
        }
        $url = new \moodle_url('/local/nextpool/placements.php', ['courseid' => $context->instanceid]);
        $key = $pending === 1 ? 'updatesavailable_one' : 'updatesavailable';
        $notification = new \core\output\notification(
            get_string($key, 'local_nextpool', ['count' => $pending, 'url' => $url->out()]),
            \core\output\notification::NOTIFY_INFO,
            false
        );
        $notification->set_announce(false);
        return \html_writer::div($OUTPUT->render($notification), '', ['id' => 'local_nextpool-updatebanner']);
    }

    /**
     * Show the update banner on the course page.
     *
     * The hook writes at the top of the body; a script moves the banner into the notification area of the
     * main region, where it belongs (bug64). Without JavaScript it stays at the top.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook Hook.
     */
    public static function before_standard_top_of_body_html_generation(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE;
        if (during_initial_install() || !str_starts_with((string) $PAGE->pagetype, 'course-view-') || !$PAGE->context) {
            return;
        }
        $banner = self::get_update_banner($PAGE->context);
        if ($banner === null) {
            return;
        }
        $hook->add_html($banner);
        $PAGE->requires->js_init_code(
            "(function() {" .
                "var banner = document.getElementById('local_nextpool-updatebanner');" .
                "var main = document.querySelector('#region-main #user-notifications') || document.getElementById('region-main');" .
                "if (banner && main) { main.prepend(banner); }" .
            "})();"
        );
    }

    /**
     * Link the editorial catalogue from the user menu for everyone with editorial rights (U-20), "Waiting for
     * review" with its count for reviewers (task85) and "My items" for item editors (task84).
     *
     * Editors whose rights are limited to a course category see neither the site
     * administration entry nor, outside that category, the category link. The check
     * looks at the system context and all categories; core caches it in the session.
     *
     * @param \core_user\hook\extend_user_menu $hook Hook.
     */
    public static function extend_user_menu(\core_user\hook\extend_user_menu $hook): void {
        global $USER;
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        // With the LernHive launcher showing Nextpool, the entry is there only (task179, q137).
        if (api::can_open_editorial() && !self::shown_in_launcher()) {
            $hook->add_navitem((object) [
                'itemtype' => 'link',
                'url' => api::get_editorial_url(),
                'title' => get_string('usermenucatalogue', 'local_nextpool'),
                'titleidentifier' => 'usermenucatalogue,local_nextpool',
            ]);
        }
        // Reviewers reach "Waiting for review" with the number of reviews waiting for them (F07, task85).
        if (review_queue::can_view()) {
            $hook->add_navitem((object) [
                'itemtype' => 'link',
                'url' => new \moodle_url('/local/nextpool/reviews.php'),
                'title' => get_string('reviewqueuecount', 'local_nextpool', review_queue::count_for_user()),
                'titleidentifier' => 'reviewqueue,local_nextpool',
            ]);
        }
        // Item editors, also without editorial rights, reach their items here (F08, task84, adr25).
        if (item_editor_manager::has_items((int) $USER->id)) {
            $hook->add_navitem((object) [
                'itemtype' => 'link',
                'url' => new \moodle_url('/local/nextpool/myitems.php'),
                'title' => get_string('myitems', 'local_nextpool'),
                'titleidentifier' => 'myitems,local_nextpool',
            ]);
        }
    }

    /** @var string Key of the catalogue node in the course navigation (lib.php). */
    public const COURSE_NODE_KEY = 'local_nextpool_catalogue';

    /** @var string Key of the catalogue node in the top navigation. */
    public const PRIMARY_NODE_KEY = 'local_nextpool';

    /** @var string Class of the LernHive launcher that tells whether it shows an entry for a plugin (task179). */
    public const LAUNCHER = '\\local_lernhive\\launcher\\launcher_manager';

    /**
     * Whether the LernHive launcher shows Nextpool to the current user (task179, q137).
     *
     * Asks \local_lernhive\launcher\launcher_manager::shows_plugin('local_nextpool') at run time, without a
     * dependency on LernHive. If the class or method is missing, the answer is not true, or the call fails, Nextpool
     * keeps its own entries, so it is never out of reach.
     *
     * @return bool
     */
    public static function shown_in_launcher(): bool {
        $callable = [self::LAUNCHER, 'shows_plugin'];
        try {
            if (!class_exists(self::LAUNCHER) || !is_callable($callable)) {
                return false;
            }
            return $callable('local_nextpool') === true;
        } catch (\Throwable $e) {
            debugging('LernHive launcher: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Link the editorial catalogue from the top navigation for everyone with editorial rights.
     *
     * Same audience as the user menu entry (U-20): editors and reviewers anywhere on the site.
     * Learners and teachers without editorial rights do not see it; teachers reach the catalogue
     * of their course from the course navigation.
     *
     * @param \core\hook\navigation\primary_extend $hook Hook.
     */
    public static function extend_primary_navigation(\core\hook\navigation\primary_extend $hook): void {
        if (!api::can_open_editorial() || self::shown_in_launcher()) {
            return;
        }
        $primary = $hook->get_primaryview();
        if ($primary->find(self::PRIMARY_NODE_KEY, null)) {
            return;
        }
        $primary->add(
            get_string('usermenucatalogue', 'local_nextpool'),
            api::get_editorial_url(),
            \navigation_node::TYPE_CUSTOM,
            null,
            self::PRIMARY_NODE_KEY,
            new \pix_icon('i/search', '')
        );
    }

    /**
     * Show "Content catalogue" as a tab of the course navigation, right after "Participants".
     *
     * Core puts nodes of plugins at the end, so the catalogue landed under "More". The node itself
     * comes from local_nextpool_extend_navigation_course() with its capability check; this only
     * moves it. Core still limits the visible tabs, so the last of them moves to "More".
     *
     * @param \core\hook\navigation\secondary_extend $hook Hook.
     */
    public static function extend_secondary_navigation(\core\hook\navigation\secondary_extend $hook): void {
        $secondary = $hook->get_secondaryview();
        $node = $secondary->get(self::COURSE_NODE_KEY);
        if (!$node) {
            return;
        }
        $keys = array_values(array_map('strval', $secondary->children->get_key_list()));
        $after = null;
        foreach (['participants', 'editsettings', 'coursehome'] as $anchor) {
            if (in_array($anchor, $keys, true)) {
                $after = $anchor;
                break;
            }
        }
        if ($after === null) {
            return;
        }
        $position = array_search($after, $keys, true);
        $before = $keys[$position + 1] ?? null;
        if ($before === self::COURSE_NODE_KEY) {
            return;
        }
        $secondary->children->remove($node->key, $node->type);
        $secondary->add_node($node, $before);
    }

    /**
     * Keep the items uploaded from a course when core deletes that course (TD-409, q56, M06.AC11).
     *
     * They move to the course category, together with their unpacked packages, and published ones are
     * withdrawn. The origin course stays recorded, so the editors see where they came from.
     *
     * @param \core_course\hook\before_course_deleted $hook Hook.
     */
    public static function before_course_deleted(\core_course\hook\before_course_deleted $hook): void {
        $context = \context_course::instance((int) $hook->course->id, IGNORE_MISSING);
        if (!$context) {
            return;
        }
        self::release_course_bound_items([$context->id], $context->get_parent_context() ?: \context_system::instance());
    }

    /**
     * Move the items of course contexts to another context and withdraw the published ones (TD-409).
     *
     * Used when a course disappears, alone or with its course category. Draft items and items in review
     * only move. No capability check: the deletion itself was authorised.
     *
     * @param int[] $contextids Contexts of the courses being deleted.
     * @param \context $to Context that receives the items.
     * @return int Number of moved items.
     */
    public static function release_course_bound_items(array $contextids, \context $to): int {
        global $DB;
        if (!$contextids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $published = $DB->get_fieldset_select(
            item_manager::TABLE,
            'id',
            "contextid $insql AND status = :published",
            $params + ['published' => status::PUBLISHED]
        );
        $moved = item_manager::move_items_from_contexts($contextids, $to);
        foreach ($published as $id) {
            item_manager::set_status((int) $id, status::WITHDRAWN);
        }
        return $moved;
    }
}
