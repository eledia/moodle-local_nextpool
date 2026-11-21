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

namespace local_nextpool\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_nextpool\local\version_manager;

/**
 * Privacy provider for local_nextpool.
 *
 * Catalogue items are shared content. A user appears as creator, last editor,
 * author, version creator, publisher, assigned reviewer, in the review history of a version (with the reasons
 * they gave) or as last editor of a competency entry. On deletion these references are
 * anonymised; the content itself stays, because other people's courses use it.
 *
 * In courses, a user appears as the teacher who placed an item or who applied,
 * skipped or configured updates (course context). These references are
 * anonymised too; the placement stays, because it belongs to the course.
 *
 * Items uploaded from a course live in that course's context (M06, adr30); there the
 * course context holds both the item references and the placements (TD-411).
 *
 * Feedback (F08, task81): comments a user wrote or marked as done and their subscriptions belong to the item's
 * context. On deletion the text of their comments is replaced by the placeholder "Comment removed", the thread and
 * the replies of others stay (q70); "marked as done by" loses the person; subscriptions are deleted. Item editors
 * (task84): being an item editor is deleted, "added by" loses the person.
 *
 * Proposals (F07, task86): a version a person proposed stays without the person (`createdby` 0), because courses
 * use it once it is published; their reasons in the review history are removed, the entry stays; assignments to
 * them are removed. The export holds the proposal with its description and file. Notifications go through
 * core_message, which exports and deletes them.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nextpool_item', [
            'title' => 'privacy:metadata:item:title',
            'createdby' => 'privacy:metadata:item:createdby',
            'usermodified' => 'privacy:metadata:item:usermodified',
            'timecreated' => 'privacy:metadata:item:timecreated',
            'timemodified' => 'privacy:metadata:item:timemodified',
        ], 'privacy:metadata:item');
        $collection->add_database_table('local_nextpool_author', [
            'userid' => 'privacy:metadata:author:userid',
            'name' => 'privacy:metadata:author:name',
            'role' => 'privacy:metadata:author:role',
        ], 'privacy:metadata:author');
        $collection->add_database_table('local_nextpool_competency', [
            'usermodified' => 'privacy:metadata:competency:usermodified',
            'timemodified' => 'privacy:metadata:competency:timemodified',
        ], 'privacy:metadata:competency');
        $collection->add_database_table('local_nextpool_version', [
            'createdby' => 'privacy:metadata:version:createdby',
            'timecreated' => 'privacy:metadata:version:timecreated',
            'publishedby' => 'privacy:metadata:version:publishedby',
            'timepublished' => 'privacy:metadata:version:timepublished',
            'assignedto' => 'privacy:metadata:version:assignedto',
            'origin' => 'privacy:metadata:version:origin',
            'courseid' => 'privacy:metadata:version:courseid',
            'changelog' => 'privacy:metadata:version:changelog',
            'timesubmitted' => 'privacy:metadata:version:timesubmitted',
            'timedecided' => 'privacy:metadata:version:timedecided',
            'selfapproved' => 'privacy:metadata:version:selfapproved',
        ], 'privacy:metadata:version');
        $collection->add_database_table('local_nextpool_version_log', [
            'action' => 'privacy:metadata:version_log:action',
            'userid' => 'privacy:metadata:version_log:userid',
            'relateduserid' => 'privacy:metadata:version_log:relateduserid',
            'reason' => 'privacy:metadata:version_log:reason',
            'timecreated' => 'privacy:metadata:version_log:timecreated',
        ], 'privacy:metadata:version_log');
        $collection->add_database_table('local_nextpool_comment', [
            'userid' => 'privacy:metadata:comment:userid',
            'content' => 'privacy:metadata:comment:content',
            'resolvedby' => 'privacy:metadata:comment:resolvedby',
            'timeresolved' => 'privacy:metadata:comment:timeresolved',
            'timecreated' => 'privacy:metadata:comment:timecreated',
            'timemodified' => 'privacy:metadata:comment:timemodified',
        ], 'privacy:metadata:comment');
        $collection->add_database_table('local_nextpool_subscription', [
            'userid' => 'privacy:metadata:subscription:userid',
            'courseid' => 'privacy:metadata:subscription:courseid',
            'auto' => 'privacy:metadata:subscription:auto',
            'unsubscribed' => 'privacy:metadata:subscription:unsubscribed',
            'timecreated' => 'privacy:metadata:subscription:timecreated',
            'timemodified' => 'privacy:metadata:subscription:timemodified',
        ], 'privacy:metadata:subscription');
        $collection->add_database_table('local_nextpool_item_editor', [
            'userid' => 'privacy:metadata:item_editor:userid',
            'addedby' => 'privacy:metadata:item_editor:addedby',
            'timecreated' => 'privacy:metadata:item_editor:timecreated',
        ], 'privacy:metadata:item_editor');
        $collection->add_database_table('local_nextpool_placement', [
            'courseid' => 'privacy:metadata:placement:courseid',
            'placedby' => 'privacy:metadata:placement:placedby',
            'timeplaced' => 'privacy:metadata:placement:timeplaced',
        ], 'privacy:metadata:placement');
        $collection->add_database_table('local_nextpool_placement_log', [
            'action' => 'privacy:metadata:placement_log:action',
            'userid' => 'privacy:metadata:placement_log:userid',
            'timecreated' => 'privacy:metadata:placement_log:timecreated',
        ], 'privacy:metadata:placement_log');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->link_subsystem('core_tag', 'privacy:metadata:core_tag');
        // Notifications about comments, proposals, reviews, assignments and updates (F07, F08) are stored by core.
        $collection->link_subsystem('core_message', 'privacy:metadata:core_message');
        $collection->add_user_preference(
            \local_nextpool\local\comment_manager::PREF_NOTICE,
            'privacy:metadata:preference:commentnotice'
        );
        return $collection;
    }

    /**
     * SQL fragment selecting the items a user is involved in.
     *
     * @param int $userid User id.
     * @return array{0:string,1:array} WHERE clause on alias i and its parameters.
     */
    private static function involvement_sql(int $userid): array {
        $sql = "(i.createdby = :u1 OR i.usermodified = :u2
                 OR EXISTS (SELECT 1 FROM {local_nextpool_author} a WHERE a.itemid = i.id AND a.userid = :u3)
                 OR EXISTS (SELECT 1 FROM {local_nextpool_competency} c WHERE c.itemid = i.id AND c.usermodified = :u6)
                 OR EXISTS (SELECT 1 FROM {local_nextpool_version} v
                             WHERE v.itemid = i.id AND (v.createdby = :u4 OR v.publishedby = :u5 OR v.assignedto = :u7))
                 OR EXISTS (SELECT 1 FROM {local_nextpool_version_log} l
                              JOIN {local_nextpool_version} lv ON lv.id = l.versionid
                             WHERE lv.itemid = i.id AND (l.userid = :u8 OR l.relateduserid = :u9))
                 OR EXISTS (SELECT 1 FROM {local_nextpool_comment} cm
                             WHERE cm.itemid = i.id AND (cm.userid = :u10 OR cm.resolvedby = :u11))
                 OR EXISTS (SELECT 1 FROM {local_nextpool_subscription} s WHERE s.itemid = i.id AND s.userid = :u12)
                 OR EXISTS (SELECT 1 FROM {local_nextpool_item_editor} ie
                             WHERE ie.itemid = i.id AND (ie.userid = :u13 OR ie.addedby = :u14)))";
        $params = [];
        foreach (range(1, 14) as $n) {
            $params['u' . $n] = $userid;
        }
        return [$sql, $params];
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        [$where, $params] = self::involvement_sql($userid);
        $contextlist = new contextlist();
        $contextlist->add_from_sql("SELECT DISTINCT i.contextid FROM {local_nextpool_item} i WHERE $where", $params);
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
               FROM {context} ctx
               JOIN {local_nextpool_placement} p ON p.courseid = ctx.instanceid AND ctx.contextlevel = :courselevel
              WHERE p.placedby = :placedby
                 OR EXISTS (SELECT 1 FROM {local_nextpool_placement_log} l WHERE l.placementid = p.id AND l.userid = :loguser)",
            ['courselevel' => CONTEXT_COURSE, 'placedby' => $userid, 'loguser' => $userid]
        );
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            $params = ['courseid' => $context->instanceid];
            $userlist->add_from_sql('placedby', 'SELECT placedby FROM {local_nextpool_placement}
                WHERE courseid = :courseid AND placedby > 0', $params);
            $userlist->add_from_sql('userid', 'SELECT l.userid FROM {local_nextpool_placement_log} l
                JOIN {local_nextpool_placement} p ON p.id = l.placementid WHERE p.courseid = :courseid AND l.userid > 0', $params);
        } else if (!in_array($context->contextlevel, [CONTEXT_SYSTEM, CONTEXT_COURSECAT], true)) {
            return;
        }
        $params = ['contextid' => $context->id];
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_nextpool_item}
            WHERE contextid = :contextid AND createdby > 0', $params);
        $userlist->add_from_sql('usermodified', 'SELECT usermodified FROM {local_nextpool_item}
            WHERE contextid = :contextid AND usermodified > 0', $params);
        $userlist->add_from_sql('userid', 'SELECT a.userid FROM {local_nextpool_author} a
            JOIN {local_nextpool_item} i ON i.id = a.itemid WHERE i.contextid = :contextid AND a.userid IS NOT NULL', $params);
        $userlist->add_from_sql('usermodified', 'SELECT c.usermodified FROM {local_nextpool_competency} c
            JOIN {local_nextpool_item} i ON i.id = c.itemid WHERE i.contextid = :contextid AND c.usermodified > 0', $params);
        $userlist->add_from_sql('createdby', 'SELECT v.createdby FROM {local_nextpool_version} v
            JOIN {local_nextpool_item} i ON i.id = v.itemid WHERE i.contextid = :contextid AND v.createdby > 0', $params);
        $userlist->add_from_sql('publishedby', 'SELECT v.publishedby FROM {local_nextpool_version} v
            JOIN {local_nextpool_item} i ON i.id = v.itemid WHERE i.contextid = :contextid AND v.publishedby > 0', $params);
        $userlist->add_from_sql('assignedto', 'SELECT v.assignedto FROM {local_nextpool_version} v
            JOIN {local_nextpool_item} i ON i.id = v.itemid WHERE i.contextid = :contextid AND v.assignedto > 0', $params);
        $userlist->add_from_sql('userid', 'SELECT l.userid FROM {local_nextpool_version_log} l
            JOIN {local_nextpool_version} v ON v.id = l.versionid
            JOIN {local_nextpool_item} i ON i.id = v.itemid WHERE i.contextid = :contextid AND l.userid > 0', $params);
        $userlist->add_from_sql('relateduserid', 'SELECT l.relateduserid FROM {local_nextpool_version_log} l
            JOIN {local_nextpool_version} v ON v.id = l.versionid
            JOIN {local_nextpool_item} i ON i.id = v.itemid WHERE i.contextid = :contextid AND l.relateduserid > 0', $params);
        $userlist->add_from_sql('userid', 'SELECT c.userid FROM {local_nextpool_comment} c
            JOIN {local_nextpool_item} i ON i.id = c.itemid WHERE i.contextid = :contextid AND c.userid > 0', $params);
        $userlist->add_from_sql('resolvedby', 'SELECT c.resolvedby FROM {local_nextpool_comment} c
            JOIN {local_nextpool_item} i ON i.id = c.itemid WHERE i.contextid = :contextid AND c.resolvedby > 0', $params);
        $userlist->add_from_sql('userid', 'SELECT s.userid FROM {local_nextpool_subscription} s
            JOIN {local_nextpool_item} i ON i.id = s.itemid WHERE i.contextid = :contextid', $params);
        $userlist->add_from_sql('userid', 'SELECT e.userid FROM {local_nextpool_item_editor} e
            JOIN {local_nextpool_item} i ON i.id = e.itemid WHERE i.contextid = :contextid', $params);
        $userlist->add_from_sql('addedby', 'SELECT e.addedby FROM {local_nextpool_item_editor} e
            JOIN {local_nextpool_item} i ON i.id = e.itemid WHERE i.contextid = :contextid AND e.addedby > 0', $params);
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        [$where, $params] = self::involvement_sql($userid);
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                self::export_placements($context, $userid);
            }
            $items = $DB->get_records_sql(
                "SELECT i.* FROM {local_nextpool_item} i WHERE i.contextid = :contextid AND $where ORDER BY i.id",
                $params + ['contextid' => $context->id]
            );
            foreach ($items as $item) {
                $isauthor = $DB->get_record(
                    'local_nextpool_author',
                    ['itemid' => $item->id, 'userid' => $userid],
                    'role',
                    IGNORE_MULTIPLE
                );
                $versions = [];
                foreach ($DB->get_records('local_nextpool_version', ['itemid' => $item->id], 'timecreated, id') as $version) {
                    $history = [];
                    $logs = $DB->get_records('local_nextpool_version_log', ['versionid' => $version->id], 'timecreated, id');
                    foreach ($logs as $log) {
                        if ((int) $log->userid !== $userid && (int) $log->relateduserid !== $userid) {
                            continue;
                        }
                        $history[] = [
                            'action' => $log->action,
                            'byyou' => transform::yesno((int) $log->userid === $userid),
                            'assignedtoyou' => transform::yesno((int) $log->relateduserid === $userid),
                            'reason' => (int) $log->userid === $userid ? (string) $log->reason : '',
                            'timecreated' => transform::datetime($log->timecreated),
                        ];
                    }
                    $involved = (int) $version->createdby === $userid || (int) $version->publishedby === $userid
                        || (int) $version->assignedto === $userid;
                    if (!$involved && !$history) {
                        continue;
                    }
                    $createdbyyou = (int) $version->createdby === $userid;
                    $versions[] = [
                        'versionnr' => $version->versionnr,
                        'origin' => $version->origin,
                        'status' => $version->status,
                        'filename' => $version->filename,
                        'createdbyyou' => transform::yesno($createdbyyou),
                        'timecreated' => transform::datetime($version->timecreated),
                        'courseid' => $createdbyyou ? $version->courseid : null,
                        'changelog' => $createdbyyou && $version->origin === version_manager::ORIGIN_PROPOSAL
                            ? format_text((string) $version->changelog, (int) $version->changelogformat, ['context' => $context])
                            : '',
                        'timesubmitted' => $version->timesubmitted ? transform::datetime($version->timesubmitted) : '-',
                        'timedecided' => $version->timedecided ? transform::datetime($version->timedecided) : '-',
                        'selfapproved' => transform::yesno((bool) $version->selfapproved),
                        'publishedbyyou' => transform::yesno((int) $version->publishedby === $userid),
                        'timepublished' => $version->timepublished ? transform::datetime($version->timepublished) : '-',
                        'assignedtoyou' => transform::yesno((int) $version->assignedto === $userid),
                        'history' => $history,
                    ];
                    if ($createdbyyou && $version->origin === version_manager::ORIGIN_PROPOSAL) {
                        // The file of an own proposal (q70: the version itself stays, without the person).
                        $file = version_manager::get_file((int) $version->id);
                        if ($file) {
                            writer::with_context($context)->export_file(
                                [get_string('pluginname', 'local_nextpool'), $item->uuid, 'proposal-' . $version->id],
                                $file
                            );
                        }
                    }
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_nextpool'), $item->uuid],
                    (object) [
                        'title' => format_string($item->title, true, ['context' => $context]),
                        'createdbyyou' => transform::yesno((int) $item->createdby === $userid),
                        'lastmodifiedbyyou' => transform::yesno((int) $item->usermodified === $userid),
                        'author' => transform::yesno((bool) $isauthor),
                        'authorrole' => $isauthor->role ?? '',
                        'timecreated' => transform::datetime($item->timecreated),
                        'versions' => $versions,
                        'competencieschangedbyyou' => array_values($DB->get_fieldset(
                            'local_nextpool_competency',
                            'competency',
                            ['itemid' => $item->id, 'usermodified' => $userid]
                        )),
                        'comments' => self::export_comments((int) $item->id, $userid, $context),
                        'subscription' => self::export_subscription((int) $item->id, $userid),
                        'itemeditor' => self::export_item_editor((int) $item->id, $userid),
                    ]
                );
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            self::anonymise_placements((int) $context->instanceid, null);
        }
        $itemids = $DB->get_fieldset('local_nextpool_item', 'id', ['contextid' => $context->id]);
        foreach ($itemids as $itemid) {
            self::anonymise_item((int) $itemid, null);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                self::anonymise_placements((int) $context->instanceid, [$userid]);
            }
            foreach ($DB->get_fieldset('local_nextpool_item', 'id', ['contextid' => $context->id]) as $itemid) {
                self::anonymise_item((int) $itemid, [$userid]);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $userids = array_map('intval', $userlist->get_userids());
        if (!$userids) {
            return;
        }
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            self::anonymise_placements((int) $context->instanceid, $userids);
        }
        foreach ($DB->get_fieldset('local_nextpool_item', 'id', ['contextid' => $context->id]) as $itemid) {
            self::anonymise_item((int) $itemid, $userids);
        }
    }

    /**
     * Remove user references from an item, its authors and versions.
     *
     * @param int $itemid Item id.
     * @param int[]|null $userids Users to remove, or null for everyone.
     */
    private static function anonymise_item(int $itemid, ?array $userids): void {
        global $DB;
        $matches = static fn($value): bool => (int) $value !== 0 && ($userids === null || in_array((int) $value, $userids, true));

        $item = $DB->get_record('local_nextpool_item', ['id' => $itemid], 'id, createdby, usermodified', MUST_EXIST);
        $update = ['id' => $itemid];
        foreach (['createdby', 'usermodified'] as $field) {
            if ($matches($item->$field)) {
                $update[$field] = 0;
            }
        }
        if (count($update) > 1) {
            $DB->update_record('local_nextpool_item', (object) $update);
        }

        foreach ($DB->get_records('local_nextpool_author', ['itemid' => $itemid]) as $author) {
            if ($author->userid !== null && $matches($author->userid)) {
                $DB->update_record('local_nextpool_author', (object) ['id' => $author->id, 'userid' => null, 'name' => '']);
            } else if ($userids === null && $author->userid === null && $author->name !== '') {
                $DB->update_record('local_nextpool_author', (object) ['id' => $author->id, 'name' => '']);
            }
        }

        foreach ($DB->get_records('local_nextpool_competency', ['itemid' => $itemid], '', 'id, usermodified') as $competency) {
            if ($matches($competency->usermodified)) {
                $DB->set_field('local_nextpool_competency', 'usermodified', 0, ['id' => $competency->id]);
            }
        }

        $fields = 'id, createdby, publishedby, assignedto';
        foreach ($DB->get_records('local_nextpool_version', ['itemid' => $itemid], '', $fields) as $version) {
            $update = ['id' => $version->id];
            foreach (['createdby', 'publishedby'] as $field) {
                if ($matches($version->$field)) {
                    $update[$field] = 0;
                }
            }
            if ($version->assignedto !== null && $matches($version->assignedto)) {
                $update['assignedto'] = null;
            }
            if (count($update) > 1) {
                $DB->update_record('local_nextpool_version', (object) $update);
            }
            // Review history: the person goes, the entry stays; their reasons are removed (q70).
            foreach ($DB->get_records('local_nextpool_version_log', ['versionid' => $version->id]) as $log) {
                $logupdate = ['id' => $log->id];
                if ($matches($log->userid)) {
                    $logupdate['userid'] = 0;
                    $logupdate['reason'] = null;
                }
                if ($log->relateduserid !== null && $matches($log->relateduserid)) {
                    // 0, not null: null means the assignment was removed (t86-f).
                    $logupdate['relateduserid'] = 0;
                }
                if (count($logupdate) > 1) {
                    $DB->update_record('local_nextpool_version_log', (object) $logupdate);
                }
            }
        }

        // Feedback (q70): the text of the person's comments becomes the placeholder "Comment removed", the threads
        // and other people's replies stay; "marked as done by" loses the person; subscriptions go.
        $fields = 'id, userid, resolvedby';
        foreach ($DB->get_records('local_nextpool_comment', ['itemid' => $itemid], '', $fields) as $comment) {
            $update = ['id' => $comment->id];
            if ($matches($comment->userid)) {
                $update += ['userid' => 0, 'content' => '', 'deleted' => 1];
            }
            if ($comment->resolvedby !== null && $matches($comment->resolvedby)) {
                $update['resolvedby'] = null;
            }
            if (count($update) > 1) {
                $DB->update_record('local_nextpool_comment', (object) $update);
            }
        }
        if ($userids === null) {
            $DB->delete_records('local_nextpool_subscription', ['itemid' => $itemid]);
            $DB->delete_records('local_nextpool_item_editor', ['itemid' => $itemid]);
        } else {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(
                'local_nextpool_subscription',
                "itemid = :itemid AND userid $insql",
                $params + ['itemid' => $itemid]
            );
            // Item editors (task84): the right goes with the person; "added by" loses the person.
            $DB->delete_records_select(
                'local_nextpool_item_editor',
                "itemid = :itemid AND userid $insql",
                $params + ['itemid' => $itemid]
            );
            $DB->set_field_select(
                'local_nextpool_item_editor',
                'addedby',
                0,
                "itemid = :itemid AND addedby $insql",
                $params + ['itemid' => $itemid]
            );
        }
    }

    /**
     * Whether a user is item editor of an item and how many item editors they added (F08, task84).
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @return array|null Null if neither.
     */
    private static function export_item_editor(int $itemid, int $userid): ?array {
        global $DB;
        $own = $DB->get_record('local_nextpool_item_editor', ['itemid' => $itemid, 'userid' => $userid]);
        $added = $DB->count_records('local_nextpool_item_editor', ['itemid' => $itemid, 'addedby' => $userid]);
        if (!$own && !$added) {
            return null;
        }
        return [
            'youareitemeditor' => transform::yesno((bool) $own),
            'timeadded' => $own ? transform::datetime($own->timecreated) : '-',
            'itemeditorsaddedbyyou' => $added,
        ];
    }

    /**
     * Comments a user wrote or marked as done on an item (F08).
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @param \context $context Item context.
     * @return array[]
     */
    private static function export_comments(int $itemid, int $userid, \context $context): array {
        global $DB;
        $comments = $DB->get_records_select(
            'local_nextpool_comment',
            'itemid = :itemid AND (userid = :userid OR resolvedby = :resolvedby)',
            ['itemid' => $itemid, 'userid' => $userid, 'resolvedby' => $userid],
            'timecreated, id'
        );
        $data = [];
        foreach ($comments as $comment) {
            $own = (int) $comment->userid === $userid;
            $data[] = [
                'writtenbyyou' => transform::yesno($own),
                'content' => $own && !$comment->deleted
                    ? format_text((string) $comment->content, (int) $comment->contentformat, ['context' => $context])
                    : '',
                'reply' => transform::yesno((bool) $comment->parentid),
                'versionid' => $comment->versionid,
                'timecreated' => transform::datetime($comment->timecreated),
                'timemodified' => transform::datetime($comment->timemodified),
                'markeddonebyyou' => transform::yesno((int) $comment->resolvedby === $userid),
                'timeresolved' => $comment->timeresolved ? transform::datetime($comment->timeresolved) : '-',
            ];
        }
        return $data;
    }

    /**
     * Subscription of a user to an item (F08).
     *
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @return array|null
     */
    private static function export_subscription(int $itemid, int $userid): ?array {
        global $DB;
        $subscription = $DB->get_record('local_nextpool_subscription', ['itemid' => $itemid, 'userid' => $userid]);
        if (!$subscription) {
            return null;
        }
        return [
            'subscribed' => transform::yesno(!$subscription->unsubscribed),
            'automatic' => transform::yesno((bool) $subscription->auto),
            'courseid' => $subscription->courseid,
            'timecreated' => transform::datetime($subscription->timecreated),
        ];
    }

    #[\Override]
    public static function export_user_preferences(int $userid) {
        $seen = get_user_preferences(\local_nextpool\local\comment_manager::PREF_NOTICE, null, $userid);
        if ($seen !== null) {
            writer::export_user_preference(
                'local_nextpool',
                \local_nextpool\local\comment_manager::PREF_NOTICE,
                transform::yesno((bool) $seen),
                get_string('privacy:metadata:preference:commentnotice', 'local_nextpool')
            );
        }
    }

    /**
     * Export the placements a user made in a course and the update actions they took.
     *
     * @param \context $context Course context.
     * @param int $userid User id.
     */
    private static function export_placements(\context $context, int $userid): void {
        global $DB;
        $placements = $DB->get_records('local_nextpool_placement', ['courseid' => $context->instanceid], 'id');
        $data = [];
        foreach ($placements as $placement) {
            $actions = [];
            $logs = $DB->get_records('local_nextpool_placement_log', ['placementid' => $placement->id, 'userid' => $userid], 'id');
            foreach ($logs as $log) {
                $actions[] = ['action' => $log->action, 'time' => transform::datetime($log->timecreated)];
            }
            if ((int) $placement->placedby !== $userid && !$actions) {
                continue;
            }
            $data[] = [
                'cmid' => $placement->cmid,
                'placedbyyou' => transform::yesno((int) $placement->placedby === $userid),
                'timeplaced' => transform::datetime($placement->timeplaced),
                'actions' => $actions,
            ];
        }
        if ($data) {
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_nextpool'), get_string('placementsheading', 'local_nextpool')],
                (object) ['placements' => $data]
            );
        }
    }

    /**
     * Remove user references from the placements of a course and their log.
     *
     * @param int $courseid Course id.
     * @param int[]|null $userids Users to remove, or null for everyone.
     */
    private static function anonymise_placements(int $courseid, ?array $userids): void {
        global $DB;
        $placementids = $DB->get_fieldset('local_nextpool_placement', 'id', ['courseid' => $courseid]);
        if (!$placementids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($placementids, SQL_PARAMS_NAMED, 'p');
        $usersql = '';
        if ($userids !== null) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $params += $userparams;
        }
        $DB->execute(
            "UPDATE {local_nextpool_placement} SET placedby = 0 WHERE id $insql"
                . ($userids !== null ? " AND placedby $usersql" : ''),
            $params
        );
        $DB->execute(
            "UPDATE {local_nextpool_placement_log} SET userid = 0 WHERE placementid $insql"
                . ($userids !== null ? " AND userid $usersql" : ''),
            $params
        );
    }
}
