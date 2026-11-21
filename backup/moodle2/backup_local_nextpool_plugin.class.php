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
 * Backup of catalogue placements with their course module.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\version_manager;

/**
 * Adds the placement of a course module to its backup (item UUID, version number, policy).
 *
 * The content itself is in the module's own backup; the placement only keeps
 * the link to the catalogue, so a course copy or import stays updatable.
 *
 * For an item available only in its course (M06, TD-408, adr31) the backup also
 * carries the item's metadata, its authors as display names (q91) and the
 * snapshot of the applied version, so a restore on another site can create a
 * new course-bound item with the same version (q57). Other items travel
 * without package as before.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_nextpool_plugin extends backup_local_plugin {
    /**
     * Placement of the course module, if any.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $placement = new backup_nested_element(
            'placement',
            ['id'],
            ['itemuuid', 'versionnr', 'policy', 'pendingversionnr', 'coursebound']
        );
        $courseitem = new backup_nested_element('courseitem', ['id'], [
            'type', 'title', 'description', 'descriptionformat', 'license', 'language',
            'versionid', 'filename', 'contenthash', 'changelog', 'changelogformat', 'activityid',
        ]);
        $authors = new backup_nested_element('authors');
        $author = new backup_nested_element('author', null, ['name', 'role']);
        $plugin->add_child($wrapper);
        $wrapper->add_child($placement);
        $placement->add_child($courseitem);
        $courseitem->add_child($authors);
        $authors->add_child($author);

        $placement->set_source_sql(
            'SELECT p.id, i.uuid AS itemuuid, v.versionnr, p.policy, pv.versionnr AS pendingversionnr,
                    CASE WHEN ctx.contextlevel = ' . CONTEXT_COURSE . ' AND ctx.instanceid = p.courseid
                         THEN 1 ELSE 0 END AS coursebound
               FROM {local_nextpool_placement} p
               JOIN {local_nextpool_item} i ON i.id = p.itemid
               JOIN {local_nextpool_version} v ON v.id = p.appliedversionid
          LEFT JOIN {local_nextpool_version} pv ON pv.id = p.pendingversionid
          LEFT JOIN {context} ctx ON ctx.id = i.contextid
              WHERE p.cmid = ?',
            [backup::VAR_MODID]
        );

        [$courseitems, $names] = $this->get_course_item((int) $this->task->get_moduleid());
        $courseitem->set_source_array($courseitems);
        $author->set_source_array($names);
        // The snapshot is in the system context, not in the module's (adr31); only course-bound items have a row here.
        $courseitem->annotate_files('local_nextpool', version_manager::FILEAREA, 'versionid', context_system::instance()->id);
        return $plugin;
    }

    /**
     * Metadata and authors of the course-bound item placed on a course module (TD-408).
     *
     * An item is course-bound while it is available only in the course of the placement. Anything else returns
     * no row, so neither metadata nor the package go into the backup.
     *
     * @param int $cmid Course module id.
     * @return array [courseitem rows (none or one), author rows]
     */
    private function get_course_item(int $cmid): array {
        global $DB;
        $placement = $DB->get_record(placement_manager::TABLE, ['cmid' => $cmid]);
        if (!$placement) {
            return [[], []];
        }
        $item = $DB->get_record(item_manager::TABLE, ['id' => $placement->itemid]);
        $context = $item ? context::instance_by_id((int) $item->contextid, IGNORE_MISSING) : false;
        if (!$context || $context->contextlevel != CONTEXT_COURSE || (int) $context->instanceid !== (int) $placement->courseid) {
            return [[], []];
        }
        $version = $DB->get_record(version_manager::TABLE, ['id' => $placement->appliedversionid]);
        if (!$version) {
            return [[], []];
        }
        $row = [
            'id' => (int) $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'description' => $item->description,
            'descriptionformat' => $item->descriptionformat,
            'license' => $item->license,
            'language' => $item->language,
            'versionid' => (int) $version->id,
            'filename' => $version->filename,
            'contenthash' => $version->contenthash,
            'changelog' => $version->changelog,
            'changelogformat' => $version->changelogformat,
            'activityid' => $this->get_activity_id($item, $version),
        ];
        // Display names, not accounts: the target site has none of this site's users (q91).
        $names = array_map(
            static fn(array $author): array => ['name' => $author['name'], 'role' => $author['role']],
            item_manager::get_author_names((int) $item->id)
        );
        return [[$row], $names];
    }

    /**
     * xAPI activity id of a version, for information in the backup; '' if it cannot be read.
     *
     * @param stdClass $item Item record.
     * @param stdClass $version Version record.
     * @return string
     */
    private function get_activity_id(stdClass $item, stdClass $version): string {
        if ($item->type !== 'xapi' || !class_exists(\mod_nextpool\local\package::class)) {
            return '';
        }
        try {
            $manifest = \mod_nextpool\local\package::get_manifest($item, $version)
                ?? \mod_nextpool\local\package::validate(\mod_nextpool\local\package::get_snapshot($version));
        } catch (\moodle_exception $e) {
            return '';
        }
        return (string) ($manifest['activityid'] ?? '');
    }
}
