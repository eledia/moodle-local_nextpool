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
 * Web service functions of local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nextpool_set_placement_policy' => [
        'classname' => \local_nextpool\external\set_placement_policy::class,
        'description' => 'Change the update policy of a course catalogue placement.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities, local/nextpool:viewcatalogue',
    ],
    // A build or another system imports a new version from the storage location (F06).
    'local_nextpool_import_version' => [
        'classname' => \local_nextpool\external\import_version::class,
        'description' => 'Import the current file of a catalogue item from its storage location as a new version.',
        'type' => 'write',
        'capabilities' => 'local/nextpool:manageitems, local/nextpool:reviewversions',
    ],
    // The competency field of the item form searches competencies instead of listing all of them (bug52).
    'local_nextpool_search_competencies' => [
        'classname' => \local_nextpool\external\search_competencies::class,
        'description' => 'Search the competencies of visible frameworks by ID number or name for the item form.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/nextpool:manageitems',
    ],
    // Person fields of the catalogue (item editors, authors) search the accounts of the site (F08, task84).
    'local_nextpool_search_users' => [
        'classname' => \local_nextpool\external\search_users::class,
        'description' => 'Search the accounts of the site by name for the item editors and authors of a catalogue item.',
        'type' => 'read',
        'ajax' => true,
    ],
    // Feedback on catalogue items (F08, task81); the same checks as feedback.php (TD-304).
    'local_nextpool_add_comment' => [
        'classname' => \local_nextpool\external\add_comment::class,
        'description' => 'Post a comment or a reply on a catalogue item.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_nextpool_update_comment' => [
        'classname' => \local_nextpool\external\update_comment::class,
        'description' => 'Change the text of an own comment on a catalogue item.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_nextpool_delete_comment' => [
        'classname' => \local_nextpool\external\delete_comment::class,
        'description' => 'Remove a comment on a catalogue item.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_nextpool_set_comment_resolved' => [
        'classname' => \local_nextpool\external\set_comment_resolved::class,
        'description' => 'Mark a comment on a catalogue item as done or open it again.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_nextpool_set_subscription' => [
        'classname' => \local_nextpool\external\set_subscription::class,
        'description' => 'Subscribe to or unsubscribe from notifications about a catalogue item.',
        'type' => 'write',
        'ajax' => true,
    ],
];
