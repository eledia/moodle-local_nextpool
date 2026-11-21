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
 * Restore of catalogue placements with their course module.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\catalogue;
use local_nextpool\local\direct_upload;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;

/**
 * Links a restored course module to its catalogue item again.
 *
 * The item is found by its UUID and the version by its number. On a site
 * without that item (e.g. a backup from another site), or if the item is not
 * published or not available in the target course, the activity is restored
 * as a normal activity without catalogue link.
 *
 * Items available only in their course (M06, TD-408, adr31): an existing
 * published item with the version is linked even if it is not available in the
 * target course (copy and duplicate, q56), if it really is course-bound and the
 * restoring person can access its course (bug104). Otherwise, if the backup carries the
 * package, a new course-bound item with the same version number is created in
 * the target course through the direct upload service (q57), falling back to the
 * first Moodle file storage location if the storage location for direct uploads
 * cannot be written to (q92). Without package the activity stays without
 * catalogue link, with a note in the restore log.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_nextpool_plugin extends restore_local_plugin {
    /** @var stdClass|null Course-bound item to create after the restore, with package, authors and placement data. */
    protected ?stdClass $newitem = null;

    /** @var bool Whether the placement of the course module still needs a new course-bound item. */
    protected bool $needsitem = false;

    /** @var int Number of the applied version in the backup, kept for the new item. */
    protected int $versionnr = 0;

    /**
     * Paths of the placement inside the module backup.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('nextpool_placement', $this->get_pathfor('/placement')),
            new restore_path_element('nextpool_courseitem', $this->get_pathfor('/placement/courseitem')),
            new restore_path_element('nextpool_author', $this->get_pathfor('/placement/courseitem/authors/author')),
        ];
    }

    /**
     * Recreate the placement for the restored course module.
     *
     * @param array $data Placement data from the backup.
     */
    public function process_nextpool_placement($data) {
        global $DB, $USER;
        $data = (object) $data;
        $cmid = (int) $this->task->get_moduleid();
        $coursebound = !empty($data->coursebound);
        $this->needsitem = false;

        if ($DB->record_exists(placement_manager::TABLE, ['cmid' => $cmid])) {
            return;
        }
        $item = $DB->get_record('local_nextpool_item', ['uuid' => $data->itemuuid]);
        if ($item) {
            $handler = \local_nextpool\local\item_types::get_handler($item->type);
            if (!$handler || $handler->get_modname() !== $this->task->get_modulename()) {
                // A forged archive cannot attach an unrelated activity to a catalogue item's versions (bug130).
                return;
            }
        }
        // Proposals have no number yet; as keys they would collide (bug167).
        $versions = $item ? $DB->get_records_select_menu(
            'local_nextpool_version',
            'itemid = :itemid AND versionnr IS NOT NULL AND status = :status',
            ['itemid' => $item->id, 'status' => status::PUBLISHED],
            '',
            'versionnr, id'
        ) : [];
        $applied = $versions[(int) $data->versionnr] ?? null;
        if ($coursebound && $item && !$this->is_course_bound_item($item)) {
            // The flag comes from the backup file: an item of the catalogue follows the placing rule (bug104).
            $coursebound = false;
        }
        if ($coursebound) {
            // Copy and duplicate link the same item, even outside its course (q56); a withdrawn one is not used, nor
            // one of a course the restoring person cannot access (bug104).
            if (!$item || !$applied || $item->status !== status::PUBLISHED || !$this->may_link_course_bound_item($item)) {
                $this->needsitem = true;
                $this->versionnr = (int) $data->versionnr;
                return;
            }
        } else if (!$item || !$applied || !catalogue::is_available_in_course($item, (int) $this->task->get_courseid())) {
            // Same rule as placing: only published items visible in the target course. The activity is kept either way.
            return;
        }
        $pending = isset($data->pendingversionnr) ? ($versions[(int) $data->pendingversionnr] ?? null) : null;
        $now = time();
        $placementid = $DB->insert_record(placement_manager::TABLE, (object) [
            'itemid' => $item->id,
            'courseid' => $this->task->get_courseid(),
            'cmid' => $cmid,
            'policy' => policy::is_valid((string) $data->policy) ? $data->policy : policy::NOTIFY,
            'appliedversionid' => $applied,
            'pendingversionid' => $pending,
            'placedby' => (int) $USER->id,
            'timeplaced' => $now,
            'timeupdated' => $now,
        ]);
        $DB->insert_record(placement_manager::LOG_TABLE, (object) [
            'placementid' => $placementid,
            'action' => placement_manager::ACTION_RESTORE,
            'fromversionid' => null,
            'toversionid' => $applied,
            'result' => 'ok',
            'message' => null,
            'userid' => (int) $USER->id,
            'timecreated' => $now,
        ]);
    }

    /**
     * Whether an item on this site is available only in its course (M06, adr30): its context is a course.
     *
     * @param stdClass $item Item record.
     * @return bool
     */
    protected function is_course_bound_item(stdClass $item): bool {
        $context = context::instance_by_id((int) $item->contextid, IGNORE_MISSING);
        return $context && $context->contextlevel == CONTEXT_COURSE && !empty($item->origincourseid);
    }

    /**
     * Whether the restore may link an existing course-bound item (q56, bug104).
     *
     * In its own course, or by a person who can access that course, as when copying or duplicating there. Anyone
     * else gets a new course-bound item from the package in the backup.
     *
     * @param stdClass $item Course-bound item record.
     * @return bool
     */
    protected function may_link_course_bound_item(stdClass $item): bool {
        global $DB;
        $origin = (int) $item->origincourseid;
        if ($origin === (int) $this->task->get_courseid()) {
            return true;
        }
        $course = $DB->get_record('course', ['id' => $origin]);
        return $course && can_access_course($course);
    }

    /**
     * Keep the metadata and the package of a course-bound item for {@see after_restore_module()}.
     *
     * The package is copied out of the backup now: the backup directory is gone when after_restore runs.
     *
     * @param array $data Item metadata from the backup.
     */
    public function process_nextpool_courseitem($data) {
        if (!$this->needsitem) {
            return;
        }
        $data = (object) $data;
        $data->authors = [];
        $data->localfile = null;
        $hash = (string) ($data->contenthash ?? '');
        // Files of a backup are stored by content hash, see backup_file_manager::get_backup_content_file_location().
        $backupfile = $this->task->get_basepath() . '/files/' . substr($hash, 0, 2) . '/' . $hash;
        if (preg_match('/^[0-9a-f]{40}$/', $hash) && is_readable($backupfile) && sha1_file($backupfile) === $hash) {
            $data->localfile = make_request_directory() . '/package.zip';
            copy($backupfile, $data->localfile);
        }
        $this->newitem = $data;
    }

    /**
     * Collect an author of the course-bound item (display name, q91).
     *
     * @param array $data Author from the backup.
     */
    public function process_nextpool_author($data) {
        if ($this->newitem === null) {
            return;
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $role = trim((string) ($data['role'] ?? ''));
            $this->newitem->authors[] = ['name' => $name, 'role' => $role === '' ? null : $role];
        }
    }

    /**
     * Create the new course-bound item for the restored course module, or note why there is none (TD-408).
     *
     * Runs after the activity is complete, so the placement is registered on a finished Nextpool activity.
     */
    public function after_restore_module() {
        if (!$this->needsitem) {
            return;
        }
        $this->needsitem = false;
        $data = $this->newitem;
        $this->newitem = null;
        if ($data === null || $data->localfile === null) {
            $this->log_without_item($data->title ?? '', get_string('restore_packagemissing', 'local_nextpool'));
            return;
        }
        $meta = $this->get_meta($data);
        $courseid = (int) $this->task->get_courseid();
        $cmid = (int) $this->task->get_moduleid();
        try {
            $this->create_item($courseid, $cmid, $data, $meta);
        } catch (\moodle_exception $e) {
            $this->log_without_item($data->title, $e->getMessage());
        }
    }

    /**
     * Create the item in the storage location for direct uploads, else in the first Moodle file storage (q92).
     *
     * @param int $courseid Target course.
     * @param int $cmid Restored course module.
     * @param stdClass $data Item data from the backup.
     * @param stdClass $meta Metadata for {@see direct_upload::create()}.
     * @throws \moodle_exception If no storage location takes the package or the package is refused.
     */
    protected function create_item(int $courseid, int $cmid, stdClass $data, stdClass $meta): void {
        $filename = (string) ($data->filename ?? '') ?: direct_upload::DEFAULT_FILENAME;
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        $type = $data->type ?? array_search($cm->modname, [
            'xapi' => 'nextpool', 'scorm' => 'scorm', 'h5p' => 'h5pactivity', 'file' => 'resource',
        ], true);
        if (
            !in_array($type, direct_upload::get_types(), true)
                || \local_nextpool\local\item_types::get_handler($type)->get_modname() !== $cm->modname
        ) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $meta->type = $type;
        $uploadsourceid = direct_upload::get_sourceid();
        if ($uploadsourceid) {
            try {
                direct_upload::create($courseid, $cmid, $data->localfile, $filename, $meta);
                return;
            } catch (source_exception $e) {
                // Not reachable or not writable: the fallback below takes the package (q92).
                unset($e);
            }
        }
        $fallbackid = $this->get_fallback_sourceid($uploadsourceid);
        if (!$fallbackid) {
            throw new \moodle_exception('error_nouploadsource', 'local_nextpool');
        }
        $meta->sourceid = $fallbackid;
        direct_upload::create($courseid, $cmid, $data->localfile, $filename, $meta);
        $this->task->log(get_string('restore_packagefallback', 'local_nextpool', (object) [
            'title' => $data->title,
            'source' => source_manager::get($fallbackid)->get_name(),
        ]), backup::LOG_WARNING, null, null, true);
    }

    /**
     * First enabled Moodle file storage location other than the one that failed (q92), 0 if there is none.
     *
     * @param int $failedid Storage location for direct uploads that could not be used, 0 if none is set.
     * @return int
     */
    protected function get_fallback_sourceid(int $failedid): int {
        $writable = direct_upload::get_writable_sources();
        foreach (source_manager::get_records(true) as $record) {
            $id = (int) $record->id;
            if ($record->plugin === 'moodle' && $id !== $failedid && array_key_exists($id, $writable)) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * Metadata of the new item from the backup, with values the target site does not know left to its defaults.
     *
     * @param stdClass $data Item data from the backup.
     * @return stdClass
     */
    protected function get_meta(stdClass $data): stdClass {
        global $CFG, $USER;
        require_once($CFG->libdir . '/licenselib.php');
        $license = (string) ($data->license ?? '');
        if ($license !== '' && \license_manager::get_license_by_shortname($license) === null) {
            $license = '';
        }
        $language = (string) ($data->language ?? '');
        if ($language !== '' && !array_key_exists($language, get_string_manager()->get_list_of_languages())) {
            $language = '';
        }
        return (object) [
            'title' => (string) $data->title,
            'description' => (string) ($data->description ?? ''),
            'descriptionformat' => (int) ($data->descriptionformat ?? FORMAT_HTML),
            'license' => $license,
            'language' => $language,
            // Free-text authors from the display names (q91); without any, the restoring user.
            'authors' => $data->authors ?: [['userid' => (int) $USER->id]],
            'changelog' => $data->changelog ?? null,
            'changelogformat' => (int) ($data->changelogformat ?? FORMAT_HTML),
            'versionnr' => $this->versionnr,
        ];
    }

    /**
     * Note in the restore log that an activity has no catalogue item.
     *
     * @param string $title Title of the item in the backup.
     * @param string $reason Why no item was created.
     */
    protected function log_without_item(string $title, string $reason): void {
        $this->task->log(get_string('restore_packagenotrestored', 'local_nextpool', (object) [
            'title' => $title,
            'reason' => $reason,
        ]), backup::LOG_WARNING, null, null, true);
    }
}
