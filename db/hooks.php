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

/**
 * Hook callbacks for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \tool_usertours\hook\before_serverside_filter_fetch::class,
        'callback' => [\local_nextpool\local\tour_manager::class, 'add_filter'],
    ],
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => [\local_nextpool\hook_callbacks::class, 'before_standard_top_of_body_html_generation'],
    ],
    [
        // Entry "Nextpool" in the user menu (U-20).
        'hook' => \core_user\hook\extend_user_menu::class,
        'callback' => [\local_nextpool\hook_callbacks::class, 'extend_user_menu'],
    ],
    [
        // Entry "Nextpool" in the top navigation for editors and reviewers.
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_nextpool\hook_callbacks::class, 'extend_primary_navigation'],
    ],
    [
        // Show "Content catalogue" as a visible tab of the course navigation instead of under "More".
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => [\local_nextpool\hook_callbacks::class, 'extend_secondary_navigation'],
    ],
    [
        // Items uploaded from a course survive its deletion (M06 TD-409).
        'hook' => \core_course\hook\before_course_deleted::class,
        'callback' => [\local_nextpool\hook_callbacks::class, 'before_course_deleted'],
    ],
];
