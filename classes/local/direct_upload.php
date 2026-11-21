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

use local_nextpool\local\source\base as source_base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;

/**
 * Direct upload of a package from a course (M06, TD-404, TD-405).
 *
 * One flow turns a package into an item available only in its course, a file in the storage location for
 * direct uploads, a published version and a placement on an existing course module, or creates nothing at
 * all: everything runs in one delegated transaction, and a file already written is deleted again (or, for a
 * new version, the previous content written back) when a later step fails.
 *
 * Domain layer like {@see placement_manager}: the methods do not check capabilities; the activity form and
 * nextpool_update_instance() do (TD-410). Publishing deliberately does not require reviewversions (q54).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class direct_upload {
    /** @var string Plugin setting holding the storage location for direct uploads (TD-404). */
    public const SETTING = 'uploadsourceid';
    /** @var string Content type of uploaded packages. */
    public const TYPE = 'xapi';
    /** @var string[] Types supported by direct uploads, in preference order. */
    public const TYPES = ['xapi', 'scorm', 'h5p', 'file'];
    /** @var string File name used when the uploaded name is unusable. */
    public const DEFAULT_FILENAME = 'package.zip';

    /**
     * Supported types with an installed handler.
     *
     * @return string[]
     */
    public static function get_types(): array {
        return array_values(array_filter(self::TYPES, [item_types::class, 'is_placeable']));
    }

    /**
     * Reason a user cannot create the activity of a type, null if allowed (M08.AC08).
     *
     * @param string $type Content type.
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return string|null
     */
    public static function check_allowed(string $type, int $courseid, int $userid): ?string {
        $handler = in_array($type, self::get_types(), true) ? item_types::get_handler($type) : null;
        if ($handler === null) {
            return get_string('error_notplaceable', 'local_nextpool');
        }
        $modname = $handler->get_modname();
        $name = get_string('modulename', 'mod_' . $modname);
        if (!\core\plugininfo\mod::get_enabled_plugin($modname)) {
            return get_string('uploadmoduledisabled', 'local_nextpool', $name);
        }
        $context = \context_course::instance($courseid);
        if (!has_capability('mod/' . $modname . ':addinstance', $context, $userid)) {
            return get_string('uploadmoduleforbidden', 'local_nextpool', $name);
        }
        if ($type === 'h5p' && !has_capability('moodle/h5p:deploy', $context, $userid)) {
            return get_string('uploadh5pforbidden', 'local_nextpool');
        }
        return null;
    }

    /**
     * Detect package content without writing anything (M08.AC03, AC06, AC07).
     *
     * @param string $path Local file.
     * @param string $filename Original file name.
     * @param int $courseid Course id.
     * @param int $userid Uploading user.
     * @return upload_detection
     * @throws \moodle_exception If a package is malformed or unrecognised.
     */
    public static function detect(string $path, string $filename, int $courseid, int $userid): upload_detection {
        $candidates = [];
        $errors = [];
        foreach (array_diff(self::get_types(), ['file']) as $type) {
            $handler = item_types::get_handler($type);
            try {
                $handler->validate_package($path);
                $candidates[$type] = $handler->read_upload_metadata($path, $filename);
            } catch (\moodle_exception $e) {
                $errors[$type] = $e;
            }
        }
        if (!$candidates) {
            if (in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['zip', 'h5p'], true)) {
                $zip = new \ZipArchive();
                if ($zip->open($path, \ZipArchive::RDONLY) === true) {
                    $names = [];
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $names[] = (string) $zip->getNameIndex($i);
                    }
                    $zip->close();
                    foreach (['xapi' => 'tincan.xml', 'scorm' => 'imsmanifest.xml', 'h5p' => 'h5p.json'] as $type => $name) {
                        if (in_array($name, $names, true) && isset($errors[$type])) {
                            throw $errors[$type];
                        }
                    }
                }
                throw new \moodle_exception('error_uploadnotrecognised', 'local_nextpool');
            }
            $candidates['file'] = item_types::get_handler('file')->read_upload_metadata($path, $filename);
        }
        $reasons = [];
        foreach ($candidates as $type => $metadata) {
            $reason = self::check_allowed($type, $courseid, $userid);
            if (
                $reason === null && !empty($metadata['missinglibraries'])
                    && !has_capability('moodle/h5p:updatelibraries', \context_system::instance(), $userid)
            ) {
                $reason = get_string('cannotplace_h5plibraries', 'local_nextpool', implode(', ', $metadata['missinglibraries']));
            }
            if ($reason !== null) {
                $reasons[$type] = $reason;
            }
        }
        return new upload_detection($candidates, $reasons);
    }

    /**
     * Id of the storage location for direct uploads as configured, 0 if none is set.
     *
     * @return int
     */
    public static function get_configured_sourceid(): int {
        return (int) get_config('local_nextpool', self::SETTING);
    }

    /**
     * Set the storage location for direct uploads.
     *
     * @param int $sourceid An enabled storage location that can be written to.
     * @throws \moodle_exception If it is not one of {@see get_writable_sources()}.
     */
    public static function set_sourceid(int $sourceid): void {
        if (!array_key_exists($sourceid, self::get_writable_sources())) {
            throw new \moodle_exception('error_uploadsourceinvalid', 'local_nextpool');
        }
        set_config(self::SETTING, $sourceid, 'local_nextpool');
    }

    /**
     * Enabled storage locations that can be written to, in display order.
     *
     * @return array<int,string> Id => name (not formatted).
     */
    public static function get_writable_sources(): array {
        $sources = [];
        foreach (source_manager::get_records(true) as $record) {
            try {
                if (source_manager::get((int) $record->id)->supports_write()) {
                    $sources[(int) $record->id] = (string) $record->name;
                }
            } catch (source_exception $e) {
                // A backend whose plugin is missing cannot take uploads.
                continue;
            }
        }
        return $sources;
    }

    /**
     * Id of the storage location new direct uploads go to, 0 if there is none (TD-404).
     *
     * The configured one, if it is enabled and can be written to; without a setting the first enabled Moodle
     * file storage. A configured location that is disabled does not fall back to another one: the administrator
     * chose it, and teachers then only see the catalogue.
     *
     * @return int
     */
    public static function get_sourceid(): int {
        $writable = self::get_writable_sources();
        $configured = self::get_configured_sourceid();
        if ($configured) {
            return array_key_exists($configured, $writable) ? $configured : 0;
        }
        foreach (source_manager::get_records(true) as $record) {
            if ($record->plugin === 'moodle' && array_key_exists((int) $record->id, $writable)) {
                return (int) $record->id;
            }
        }
        return 0;
    }

    /**
     * The storage location new direct uploads go to, null if there is none (TD-404, interface for F07 q75).
     *
     * @return source_base|null
     */
    public static function get_source(): ?source_base {
        $sourceid = self::get_sourceid();
        return $sourceid ? source_manager::get($sourceid) : null;
    }

    /**
     * Path of an uploaded package in the storage location (TD-404).
     *
     * The item id keeps two packages with the same file name apart; the course id groups them for administrators.
     *
     * @param int $courseid Origin course.
     * @param int $itemid Item id.
     * @param string $filename Name of the uploaded file.
     * @return string Path relative to the base folder.
     */
    public static function get_path(int $courseid, int $itemid, string $filename): string {
        return 'kurs-' . $courseid . '/' . $itemid . '-' . self::clean_filename($filename);
    }

    /**
     * Whether a teacher may upload a new package for an activity, apart from capabilities (TD-407).
     *
     * Only for activities whose placement belongs to an item that is still available only in this very course.
     * Once the editorial team widened the item, or the activity uses an item of another course (copy, q56),
     * new versions go through the editorial team.
     *
     * @param \cm_info $cm Course module.
     * @return bool
     */
    public static function can_upload(\cm_info $cm): bool {
        $placement = placement_manager::get_by_cmid((int) $cm->id);
        if ($placement === null) {
            return false;
        }
        return self::is_own_course_item(item_manager::get((int) $placement->itemid), (int) $cm->course);
    }

    /**
     * Create a course-bound item and a new activity together (M08.AC01, AC04).
     *
     * @param int $courseid Course id.
     * @param int $section Section number.
     * @param int $beforemod Insert before this course module, 0 to append.
     * @param string $path Local upload.
     * @param string $filename Original file name.
     * @param string $type Selected content type.
     * @param \stdClass $meta Item metadata, including optional visible.
     * @param array $options Handler placement options.
     * @param int $userid Uploading user, 0 for current user.
     * @return \stdClass Placement.
     */
    public static function create_and_place(
        int $courseid,
        int $section,
        int $beforemod,
        string $path,
        string $filename,
        string $type,
        \stdClass $meta,
        array $options = [],
        int $userid = 0
    ): \stdClass {
        $meta = clone $meta;
        $meta->type = $type;
        return self::create_with_placement($courseid, 0, $path, $filename, $meta, $userid, $section, $beforemod, $options);
    }

    /**
     * Register an existing course module, including restored course-bound uploads.
     *
     * @param int $courseid Course id.
     * @param int $cmid Existing module id.
     * @param string $zipfile Local upload.
     * @param string $filename Original file name.
     * @param \stdClass $meta Metadata; type defaults to xapi for pre-M08 backups.
     * @param int $userid Uploading user, 0 for current user.
     * @return \stdClass Placement.
     */
    public static function create(
        int $courseid,
        int $cmid,
        string $zipfile,
        string $filename,
        \stdClass $meta,
        int $userid = 0
    ): \stdClass {
        return self::create_with_placement($courseid, $cmid, $zipfile, $filename, $meta, $userid);
    }

    /**
     * Create item, file, published version and placement for a course module created by the activity form.
     *
     * Runs inside the transaction of add_moduleinfo() when called from nextpool_add_instance(), so the course
     * module goes as well if this fails.
     *
     * @param int $courseid Course the package is uploaded from.
     * @param int $cmid Course module that gets the placement.
     * @param string $zipfile Absolute path of the uploaded package.
     * @param string $filename Name of the uploaded file.
     * @param \stdClass $meta title (required), description, descriptionformat, license (default: site default, else
     *     "All rights reserved", task169), language, authors (as for item_manager::create(), default: the
     *     uploading user), changelog, changelogformat, versionnr (to keep the number of a restored version,
     *     task78f), sourceid (a storage location other than the one for direct uploads, for the restore fallback
     *     of q92).
     * @param int $userid Uploading user, 0 for the current user.
     * @param int|null $section New activity section, null to register an existing module.
     * @param int $beforemod Position before this module.
     * @param array $options Handler options.
     * @return \stdClass The placement; itemid and appliedversionid name the new item and version.
     * @throws \moodle_exception If there is no storage location for direct uploads, the package is not valid or the
     *     storage location fails ({@see source_exception} with the reason).
     */
    private static function create_with_placement(
        int $courseid,
        int $cmid,
        string $zipfile,
        string $filename,
        \stdClass $meta,
        int $userid = 0,
        ?int $section = null,
        int $beforemod = 0,
        array $options = []
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $source = !empty($meta->sourceid) ? source_manager::get((int) $meta->sourceid) : self::get_source();
        if ($source === null) {
            throw new \moodle_exception('error_nouploadsource', 'local_nextpool');
        }
        $type = (string) ($meta->type ?? self::TYPE);
        self::validate_package($zipfile, $type);

        $transaction = $DB->start_delegated_transaction();
        $written = false;
        try {
            $item = item_manager::create((object) [
                'title' => trim((string) ($meta->title ?? '')),
                'description' => (string) ($meta->description ?? ''),
                'descriptionformat' => (int) ($meta->descriptionformat ?? FORMAT_HTML),
                'type' => $type,
                'language' => (string) ($meta->language ?? ''),
                // Without a licence from the form: the site default, else "All rights reserved" (task169).
                'license' => (string) ($meta->license ?? '') ?: license_info::default_for_new(),
                'sourceid' => $source->get_id(),
                'contextid' => \context_course::instance($courseid)->id,
                'origincourseid' => $courseid,
            ], $meta->authors ?? [['userid' => $userid]], $userid);

            $path = self::get_path($courseid, (int) $item->id, $filename);
            $entry = $source->write($path, $zipfile);
            $written = true;
            $version = version_manager::create_from_file((int) $item->id, $zipfile, $filename, $entry, [
                'userid' => $userid,
                'origin' => version_manager::ORIGIN_UPLOAD,
                'changelog' => $meta->changelog ?? null,
                'changelogformat' => (int) ($meta->changelogformat ?? FORMAT_HTML),
                'versionnr' => (int) ($meta->versionnr ?? 0),
            ]);
            self::publish((int) $version->id, $userid, $type, $zipfile);
            item_manager::set_status((int) $item->id, status::REVIEW, $userid);
            item_manager::set_status((int) $item->id, status::PUBLISHED, $userid);
            if ($section === null) {
                $placement = placement_manager::register((int) $item->id, $cmid, policy::AUTO, $userid);
            } else {
                $placement = placement_manager::place(
                    (int) $item->id,
                    $courseid,
                    $section,
                    policy::AUTO,
                    $userid,
                    $options,
                    $beforemod
                );
                if (isset($meta->visible) && !$meta->visible) {
                    \core_courseformat\formatactions::cm($courseid)->set_visibility((int) $placement->cmid, 0);
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($written) {
                self::undo_write($source, $path, null);
            }
            $transaction->rollback($e);
        }
        return $placement;
    }

    /**
     * Upload a new package for an activity: new published version, written into the course module (TD-407).
     *
     * The file replaces the item's file in its storage location, which the item keeps even if the setting for
     * direct uploads changed since (TD-404). Learner data stays, because it belongs to the course module.
     *
     * @param int $placementid Placement of the activity.
     * @param string $zipfile Absolute path of the uploaded package.
     * @param string $filename Name of the uploaded file; becomes the file name of the version.
     * @param bool $confirmactivitychange The teacher confirmed a different xAPI activity id (M06.AC07). Without it,
     *     a package of another activity is refused.
     * @param int $userid Uploading user, 0 for the current user.
     * @param \stdClass|null $meta Optional changelog and changelogformat.
     * @return \stdClass The placement with the new version applied.
     * @throws \moodle_exception If the rule of {@see can_upload()} does not hold, the package is not valid or
     *     unchanged, the storage location fails or the activity id changed without confirmation.
     */
    public static function add_version(
        int $placementid,
        string $zipfile,
        string $filename,
        bool $confirmactivitychange,
        int $userid = 0,
        ?\stdClass $meta = null
    ): \stdClass {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        $placement = placement_manager::get($placementid);
        $item = item_manager::get((int) $placement->itemid);
        if (!self::is_own_course_item($item, (int) $placement->courseid)) {
            throw new \moodle_exception('error_uploadnotallowed', 'local_nextpool');
        }
        // Core deploys the changed H5P using its new publisher as file owner in the activity context.
        // Refuse before any storage write, publication or replacement of the currently working package.
        if (
            $item->type === 'h5p' && !has_capability(
                'moodle/h5p:deploy',
                \context_module::instance((int) $placement->cmid),
                $userid
            )
        ) {
            throw new \moodle_exception('uploadh5pforbidden', 'local_nextpool');
        }
        self::validate_package($zipfile, $item->type);
        $latest = version_manager::get_latest((int) $item->id);
        if ($latest && $latest->contenthash === sha1_file($zipfile) && $latest->filename === clean_param($filename, PARAM_FILE)) {
            // Nothing to write; checked before the storage location is touched.
            throw new \moodle_exception('error_unchanged', 'local_nextpool');
        }
        $source = source_manager::get((int) $item->sourceid);
        if (!$source->supports_write()) {
            throw new source_exception('error_sourcereadonly');
        }
        $path = $latest ? (string) $latest->sourceref : self::get_path((int) $placement->courseid, (int) $item->id, $filename);

        $transaction = $DB->start_delegated_transaction();
        $written = false;
        try {
            $entry = $source->write($path, $zipfile);
            $written = true;
            $version = version_manager::create_from_file((int) $item->id, $zipfile, $filename, $entry, [
                'userid' => $userid,
                'origin' => version_manager::ORIGIN_UPLOAD,
                'changelog' => $meta->changelog ?? null,
                'changelogformat' => (int) ($meta->changelogformat ?? FORMAT_HTML),
            ]);
            self::publish((int) $version->id, $userid, $item->type, $zipfile);
            try {
                $placement = placement_manager::apply(
                    $placementid,
                    (int) $version->id,
                    $userid,
                    $item->type === self::TYPE && $confirmactivitychange
                );
                $placement->applied = true;
                $placement->reason = null;
            } catch (\moodle_exception $e) {
                if ($item->type === self::TYPE || $e->errorcode !== 'error_noinplace') {
                    throw $e;
                }
                placement_manager::offer($placementid, (int) $version->id, (string) $e->a, false);
                $placement = placement_manager::get($placementid);
                $placement->applied = false;
                $placement->reason = (string) $e->a;
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($written) {
                self::undo_write($source, $path, $latest);
            }
            $transaction->rollback($e);
        }
        return $placement;
    }

    /**
     * Whether content in the activity differs from the applied catalogue version (M08 q149).
     *
     * @param \cm_info $cm Course module.
     * @return bool
     */
    public static function has_course_changes(\cm_info $cm): bool {
        $placement = placement_manager::get_by_cmid((int) $cm->id);
        if (!$placement) {
            return false;
        }
        $item = item_manager::get((int) $placement->itemid);
        $handler = item_types::get_handler($item->type);
        $current = $handler?->get_course_content_hash($cm);
        if ($current === null) {
            return false;
        }
        $expected = $handler->get_version_content_hash(version_manager::get((int) $placement->appliedversionid));
        return $expected !== null && $expected !== $current;
    }

    /**
     * Whether an item is available only in the given course, as an upload from that course.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course id.
     * @return bool
     */
    private static function is_own_course_item(\stdClass $item, int $courseid): bool {
        $context = \context::instance_by_id((int) $item->contextid, IGNORE_MISSING);
        return $context
            && $context->contextlevel == CONTEXT_COURSE
            && (int) $context->instanceid === $courseid
            && (int) $item->origincourseid === $courseid
            && in_array($item->type, self::get_types(), true);
    }

    /**
     * Check a package with the handler of the upload type.
     *
     * @param string $zipfile Absolute path of the package.
     * @param string $type Content type.
     * @throws \moodle_exception If the type is not available or the handler refuses the package.
     */
    private static function validate_package(string $zipfile, string $type = self::TYPE): void {
        $handler = in_array($type, self::get_types(), true) ? item_types::get_handler($type) : null;
        if ($handler === null) {
            throw new \moodle_exception('error_notplaceable', 'local_nextpool');
        }
        $handler->validate_package($zipfile);
    }

    /**
     * Publish a draft version of an uploaded package (q54: no review).
     *
     * @param int $versionid Version id.
     * @param int $userid Uploading user.
     * @param string $type Content type.
     * @param string $path Local package.
     */
    private static function publish(int $versionid, int $userid, string $type = self::TYPE, string $path = ''): void {
        $libraries = $type === 'h5p'
            && !empty(item_types::get_handler('h5p')->read_upload_metadata($path, '')['missinglibraries']);
        if ($libraries && !has_capability('moodle/h5p:updatelibraries', \context_system::instance(), $userid)) {
            throw new \moodle_exception('uploadh5pforbidden', 'local_nextpool');
        }
        version_manager::set_status($versionid, status::REVIEW, $userid);
        version_manager::set_status($versionid, status::PUBLISHED, $userid, $libraries);
    }

    /**
     * Undo a write to the storage location after a later step failed.
     *
     * A new file is deleted; a replaced file gets the content of the previous version back, and stays as it is if
     * that version has no snapshot (bug107). Errors are only
     * reported for developers: the original error is the one the teacher has to see. Publishing a reviewed
     * version undoes its write the same way (bug161).
     *
     * @param source_base $source Storage location.
     * @param string $path Path that was written.
     * @param \stdClass|null $previous Version whose file was replaced, null if the file is new.
     * @return entry|null The restored file, null if the file was deleted or could not be restored.
     */
    public static function undo_write(source_base $source, string $path, ?\stdClass $previous): ?entry {
        try {
            if ($previous === null) {
                $source->delete($path);
                return null;
            }
            $snapshot = version_manager::get_file((int) $previous->id);
            if ($snapshot === null) {
                // Never delete the item's file: without the snapshot the new content stays there (bug107).
                debugging('Direct upload: no snapshot of version ' . $previous->id . ' to restore ' . $path, DEBUG_DEVELOPER);
                return null;
            }
            $tempfile = make_request_directory() . '/previous';
            $snapshot->copy_content_to($tempfile);
            return $source->write($path, $tempfile);
        } catch (\Throwable $e) {
            debugging('Direct upload: could not undo the write of ' . $path . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * File name usable in a storage location path.
     *
     * @param string $filename Name of the uploaded file.
     * @return string
     */
    private static function clean_filename(string $filename): string {
        $clean = trim(clean_param($filename, PARAM_FILE), ". \t");
        return $clean === '' ? self::DEFAULT_FILENAME : $clean;
    }
}
