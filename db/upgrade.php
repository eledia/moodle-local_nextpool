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
 * Upgrade steps for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade local_nextpool.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool Always true on success.
 */
function xmldb_local_nextpool_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100202) {
        // Placements of catalogue items in courses (F05).
        $table = new xmldb_table('local_nextpool_placement');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('policy', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'notify');
        $table->add_field('appliedversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('pendingversionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('placedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeplaced', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeupdated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemid', XMLDB_KEY_FOREIGN, ['itemid'], 'local_nextpool_item', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('cmid', XMLDB_KEY_FOREIGN_UNIQUE, ['cmid'], 'course_modules', ['id']);
        $table->add_key('appliedversionid', XMLDB_KEY_FOREIGN, ['appliedversionid'], 'local_nextpool_version', ['id']);
        $table->add_key('pendingversionid', XMLDB_KEY_FOREIGN, ['pendingversionid'], 'local_nextpool_version', ['id']);
        $table->add_key('placedby', XMLDB_KEY_FOREIGN, ['placedby'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Log of placements and updates (F06).
        $table = new xmldb_table('local_nextpool_placement_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('placementid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fromversionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('toversionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('result', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'ok');
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('placementid', XMLDB_KEY_FOREIGN, ['placementid'], 'local_nextpool_placement', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100202, 'local', 'nextpool');
    }

    if ($oldversion < 2026101900) {
        // Competencies of catalogue items (F14, q26).
        $table = new xmldb_table('local_nextpool_competency');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('competency', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('skilllevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('didacticfunction', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('objectives', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemid', XMLDB_KEY_FOREIGN, ['itemid'], 'local_nextpool_item', ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('itemid-competency', XMLDB_INDEX_UNIQUE, ['itemid', 'competency']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101900, 'local', 'nextpool');
    }

    if ($oldversion < 2026110700) {
        // H5P library updates only with an explicit option when publishing (q39). Existing versions: off.
        $table = new xmldb_table('local_nextpool_version');
        $field = new xmldb_field('h5plibraries', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timepublished');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026110700, 'local', 'nextpool');
    }

    if ($oldversion < 2026111405) {
        // Items follow their file by its backend id and show when it is no longer found (q46, task71).
        $table = new xmldb_table('local_nextpool_version');
        $field = new xmldb_field('sourceexternalid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sourcerevision');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_nextpool_item');
        $field = new xmldb_field('timesourcemissing', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'currentversionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026111405, 'local', 'nextpool');
    }

    if ($oldversion < 2026111408) {
        // Items uploaded from a course remember that course (M06 direct upload, adr30, task78a). No data changes.
        $table = new xmldb_table('local_nextpool_item');
        $field = new xmldb_field('origincourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timesourcemissing');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('origincourseid', XMLDB_INDEX_NOTUNIQUE, ['origincourseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026111408, 'local', 'nextpool');
    }

    if ($oldversion < 2026111414) {
        // Review and collaboration (F07, F08, task79): origin and review fields on versions (TD-301), review
        // history (TD-303), comments, subscriptions and item editors. Proposals get their number when they are
        // published (q67, adr24), so the version number becomes nullable.
        $table = new xmldb_table('local_nextpool_version');
        $fields = [
            new xmldb_field('origin', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'editorial', 'h5plibraries'),
            new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'origin'),
            new xmldb_field('assignedto', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid'),
            new xmldb_field('timesubmitted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'assignedto'),
            new xmldb_field('timedecided', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timesubmitted'),
            new xmldb_field('selfapproved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timedecided'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Existing versions: imported by the storage location check (createdby 0, F06) or by the editors.
        $DB->set_field_select('local_nextpool_version', 'origin', 'source', 'createdby = 0 AND origin = :editorial', [
            'editorial' => 'editorial',
        ]);

        $keys = [
            new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']),
            new xmldb_key('assignedto', XMLDB_KEY_FOREIGN, ['assignedto'], 'user', ['id']),
        ];
        foreach ($keys as $key) {
            // A foreign key is created as an index; skip it when a resumed upgrade has it already.
            if (!$dbman->index_exists($table, new xmldb_index($key->getName(), XMLDB_INDEX_NOTUNIQUE, $key->getFields()))) {
                $dbman->add_key($table, $key);
            }
        }
        $indexes = [
            new xmldb_index('status-assignedto', XMLDB_INDEX_NOTUNIQUE, ['status', 'assignedto']),
            new xmldb_index('status-timedecided', XMLDB_INDEX_NOTUNIQUE, ['status', 'timedecided']),
        ];
        foreach ($indexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        // The version number becomes nullable; its unique index has to go while the field changes.
        $index = new xmldb_index('itemid-versionnr', XMLDB_INDEX_UNIQUE, ['itemid', 'versionnr']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('versionnr', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'itemid');
        $dbman->change_field_notnull($table, $field);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Review history (TD-303).
        $table = new xmldb_table('local_nextpool_version_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('relateduserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('reasonformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '2');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('versionid', XMLDB_KEY_FOREIGN, ['versionid'], 'local_nextpool_version', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('relateduserid', XMLDB_KEY_FOREIGN, ['relateduserid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Feedback (F08).
        $table = new xmldb_table('local_nextpool_comment');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('parentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('contentformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('deleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('resolvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timeresolved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemid', XMLDB_KEY_FOREIGN, ['itemid'], 'local_nextpool_item', ['id']);
        $table->add_key('versionid', XMLDB_KEY_FOREIGN, ['versionid'], 'local_nextpool_version', ['id']);
        $table->add_key('parentid', XMLDB_KEY_FOREIGN, ['parentid'], 'local_nextpool_comment', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('resolvedby', XMLDB_KEY_FOREIGN, ['resolvedby'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Comments a proposal takes up (F07).
        $table = new xmldb_table('local_nextpool_comment_link');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('commentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('versionid', XMLDB_KEY_FOREIGN, ['versionid'], 'local_nextpool_version', ['id']);
        $table->add_key('commentid', XMLDB_KEY_FOREIGN, ['commentid'], 'local_nextpool_comment', ['id']);
        $table->add_index('versionid-commentid', XMLDB_INDEX_UNIQUE, ['versionid', 'commentid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Subscriptions (F08).
        $table = new xmldb_table('local_nextpool_subscription');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('auto', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('unsubscribed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemid', XMLDB_KEY_FOREIGN, ['itemid'], 'local_nextpool_item', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('itemid-userid', XMLDB_INDEX_UNIQUE, ['itemid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Editors of one item (F08, adr25).
        $table = new xmldb_table('local_nextpool_item_editor');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('addedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemid', XMLDB_KEY_FOREIGN, ['itemid'], 'local_nextpool_item', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('addedby', XMLDB_KEY_FOREIGN, ['addedby'], 'user', ['id']);
        $table->add_index('itemid-userid', XMLDB_INDEX_UNIQUE, ['itemid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026111414, 'local', 'nextpool');
    }

    if ($oldversion < 2026111530) {
        // Anonymous statistics per catalogue item and version, computed every night (F15, task126). No data changes:
        // the first run of the scheduled task fills the table.
        $table = new xmldb_table('local_nextpool_item_stats');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('versionnr', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('started', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('passed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completionrate', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('passrate', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('scoremedian', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('scorelow', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('scorehigh', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('durationmedian', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('attemptsmedian', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('coursecount', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('suppressed', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecalculated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('itemid', XMLDB_KEY_FOREIGN, ['itemid'], 'local_nextpool_item', ['id']);
        $table->add_index('itemid-versionnr', XMLDB_INDEX_UNIQUE, ['itemid', 'versionnr']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026111530, 'local', 'nextpool');
    }

    if ($oldversion < 2026111542) {
        // Snapshots stored before bug234 had no owner, so core's H5P player showed them to anybody and installed the
        // libraries in them. They get the guest user as owner, and contents core already deployed from them are
        // removed. The savepoint follows only when both parts are done; a resumed upgrade finishes the job.
        $removed = \local_nextpool\local\version_manager::secure_existing_snapshots();
        \local_nextpool\local\h5p_guard::report_removed('local_nextpool', $removed, 'Nextpool version files', 'bug234');

        upgrade_plugin_savepoint(true, 2026111542, 'local', 'nextpool');
    }

    if ($oldversion < 2026111591) {
        // F16: remove the old system-context H5P cache and its publicly resolvable Core contents.
        \local_nextpool\local\h5p_preview::purge_legacy();
        \local_nextpool\local\tour_manager::install();
        upgrade_plugin_savepoint(true, 2026111591, 'local', 'nextpool');
    }

    return true;
}
