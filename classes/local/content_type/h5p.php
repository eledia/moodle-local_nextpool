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

namespace local_nextpool\local\content_type;

/**
 * Content type 'h5p' as a core H5P activity (mod_h5pactivity), updated in place (task25b).
 *
 * The version snapshot (.h5p) becomes the package of the activity. An update
 * replaces the package under its first file name, so the H5P record stays the
 * same; core deploys the new content on the next view because the content hash
 * changed. Attempts are kept: they do not depend on the content version.
 *
 * The package belongs to the person who published the version (q18): core
 * installs H5P libraries from it only if that person may do so. Since q39 the
 * libraries in the package are only deployed when the version was published
 * with the option "Allow H5P library updates"; otherwise the activity gets the
 * package without its library folders, so core cannot install or update any.
 * Such a version cannot be placed while the site lacks libraries it needs (bug88).
 * Findings and core references: docs/13-spike-inplace.md §2.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p extends base {
    #[\Override]
    public function get_modname(): string {
        return 'h5pactivity';
    }

    #[\Override]
    public function get_file_extensions(): array {
        return ['h5p'];
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_h5p', 'local_nextpool');
    }

    /**
     * Only an H5P package becomes a version: a ZIP with h5p.json at its top level (bug205).
     *
     * @param string $path Local path of the file.
     * @throws \moodle_exception If the file is no H5P package.
     */
    #[\Override]
    public function validate_package(string $path): void {
        $zip = self::open_zip($path);
        try {
            $content = $zip ? self::read_zip_entry($zip, 'h5p.json', 1048576) : false;
        } finally {
            $zip?->close();
        }
        $json = $content === false ? null : json_decode($content, true);
        if (!is_array($json) || empty($json['mainLibrary'])) {
            $this->refuse_file();
        }
    }

    #[\Override]
    public function read_upload_metadata(string $path, string $filename): array {
        $zip = self::open_zip($path);
        try {
            $content = $zip ? self::read_zip_entry($zip, 'h5p.json', 1048576) : false;
            $json = $content === false ? [] : json_decode($content, true);
        } finally {
            $zip?->close();
        }
        return [
            'title' => $json['title'] ?? pathinfo($filename, PATHINFO_FILENAME),
            'missinglibraries' => self::get_missing_libraries($json ?? []),
        ];
    }

    #[\Override]
    public function get_course_content_hash(\cm_info $cm): ?string {
        $file = $this->get_package(\context_module::instance($cm->id));
        if ($file === null) {
            return null;
        }
        $placement = \local_nextpool\local\placement_manager::get_by_cmid((int) $cm->id);
        $version = $placement ? \local_nextpool\local\version_manager::get((int) $placement->appliedversionid) : null;
        if ($version && self::allows_library_updates($version)) {
            return $file->get_contenthash();
        }
        $entries = self::get_content_entries($file);
        return $entries === null ? null : sha1(json_encode($entries));
    }

    #[\Override]
    public function get_version_content_hash(\stdClass $version): ?string {
        if (self::allows_library_updates($version)) {
            return parent::get_version_content_hash($version);
        }
        $entries = self::get_content_entries($this->get_snapshot($version));
        return $entries === null ? null : sha1(json_encode($entries));
    }

    #[\Override]
    public function supports_preview(): bool {
        return true;
    }

    #[\Override]
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        if (!\local_nextpool\local\h5p_preview::supported()) {
            return new \local_nextpool\local\preview_result(message: get_string('previewunavailable', 'local_nextpool'));
        }
        $reason = $this->check_place($item, $version);
        if ($reason !== null) {
            return new \local_nextpool\local\preview_result(message: $reason);
        }
        return new \local_nextpool\local\preview_result('frame', (new \moodle_url('/local/nextpool/preview_h5p.php', [
            'itemid' => $item->id, 'courseid' => $courseid, 'versionid' => $version->id,
        ]))->out(false));
    }

    /**
     * Anonymous, content-only preview cache; the persisted package remains protected by the guest owner.
     *
     * @param \stdClass $version Published version.
     * @return \stored_file
     */
    public function get_preview_package(\stdClass $version): \stored_file {
        return \local_nextpool\local\h5p_preview::package($version);
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');

        $draftitemid = file_get_unused_draft_itemid();
        $snapshot = $this->get_snapshot($version);
        $attribution = $this->get_file_attribution($item);
        $attribution['source'] = serialize((object) ['source' => $attribution['source']]);
        $this->create_package_file([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $snapshot->get_filename(),
            'userid' => $this->get_package_owner($version) ?: null,
        ] + $attribution, $snapshot, $version);

        $moduleinfo = create_module((object) [
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $item->title,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'cmidnumber' => '',
            'packagefile' => $draftitemid,
            'displayoptions' => 0,
            'enabletracking' => 1,
            'grademethod' => 1,
            'reviewmode' => 1,
            'grade' => 100,
        ]);
        return (int) $moduleinfo->coursemodule;
    }

    #[\Override]
    public function check_update(\cm_info $cm, \stdClass $item, \stdClass $version): ?string {
        global $DB;
        $context = \context_module::instance($cm->id);
        $current = $this->get_package($context);
        $new = self::read_h5p_json($this->get_snapshot($version));
        if ($new === null) {
            return get_string('noinplace_h5pinvalid', 'local_nextpool');
        }
        $old = $current ? self::read_h5p_json($current) : null;
        if ($old !== null && ($old['mainLibrary'] ?? '') !== ($new['mainLibrary'] ?? '')) {
            return get_string('noinplace_h5pmainlibrary', 'local_nextpool', (object) [
                'from' => s($old['mainLibrary'] ?? ''),
                'to' => s($new['mainLibrary'] ?? ''),
            ]);
        }
        $missing = self::get_missing_libraries($new);
        if ($missing && !self::allows_library_updates($version)) {
            // Published without the library option (q39): the activity gets no libraries from this package.
            return get_string('noinplace_h5plibrariesoff', 'local_nextpool', s(implode(', ', $missing)));
        }
        $owner = $this->get_package_owner($version);
        if ($missing && !($owner && has_capability('moodle/h5p:updatelibraries', \context_system::instance(), $owner))) {
            return get_string('noinplace_h5plibraries', 'local_nextpool', s(implode(', ', $missing)));
        }
        // Core resets saved progress when it deploys new content; let the teacher decide (q19).
        $openstates = $DB->record_exists_select(
            'xapi_states',
            'component = :component AND itemid = :itemid AND stateid = :stateid AND statedata IS NOT NULL',
            ['component' => 'mod_h5pactivity', 'itemid' => $context->id, 'stateid' => 'state']
        );
        if ($openstates) {
            return get_string('noinplace_h5pstates', 'local_nextpool');
        }
        return null;
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $DB;
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $context = \context_module::instance($cm->id);
        $snapshot = $this->get_snapshot($version);
        $current = $this->get_package($context);
        // Keep the file name: the H5P record is keyed by the file's path, so it stays and is redeployed.
        $filename = $current ? $current->get_filename() : $snapshot->get_filename();
        $fs = get_file_storage();

        $transaction = $DB->start_delegated_transaction();
        $fs->delete_area_files($context->id, 'mod_h5pactivity', 'package', 0);
        $this->create_package_file([
            'contextid' => $context->id,
            'component' => 'mod_h5pactivity',
            'filearea' => 'package',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $this->get_package_owner($version) ?: null,
        ] + $this->get_file_attribution($item), $snapshot, $version);
        $DB->set_field('h5pactivity', 'timemodified', time(), ['id' => $cm->instance]);
        $transaction->allow_commit();

        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
    }

    #[\Override]
    public function supports_extract(): bool {
        return true;
    }

    /**
     * The package of the H5P activity with its content, as the activity has it now (q64 second step, task86).
     *
     * When teachers edit the content in Moodle, core writes a new package into the activity, so the package is
     * the content of the activity. An activity whose content is still that of the version it was placed from gives
     * that version's snapshot back, so "unchanged" is recognised although the activity got the package without its
     * library folders (q39). Libraries in a proposed package follow the rules of q39: they only reach the site if
     * the reviewer publishes with the option to update H5P libraries (t86-c).
     *
     * @param \cm_info $cm Course module of an H5P activity.
     * @param string $targetfile Absolute path of the file to write.
     * @return string File name with the extension .h5p.
     * @throws \moodle_exception If the activity has no valid H5P package.
     */
    #[\Override]
    public function extract(\cm_info $cm, string $targetfile): string {
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $package = $this->get_package(\context_module::instance($cm->id));
        if ($package === null || self::read_h5p_json($package) === null) {
            throw new \moodle_exception('error_extracth5p', 'local_nextpool');
        }
        $placement = \local_nextpool\local\placement_manager::get_by_cmid((int) $cm->id);
        $applied = !empty($placement->appliedversionid)
            ? \local_nextpool\local\version_manager::get_file((int) $placement->appliedversionid) : null;
        if ($applied !== null && self::get_content_entries($applied) === self::get_content_entries($package)) {
            $package = $applied;
        }
        if (!$package->copy_content_to($targetfile)) {
            throw new \moodle_exception('error_extractfailed', 'local_nextpool');
        }
        return self::get_extract_filename($cm, 'h5p');
    }

    /**
     * The content of an H5P package without its libraries: h5p.json and the files under content/ with their checksums.
     *
     * @param \stored_file $package .h5p file.
     * @return array<string,int>|null CRC32 by path, sorted by path; null if the package is not a ZIP.
     */
    private static function get_content_entries(\stored_file $package): ?array {
        $zip = self::open_package($package);
        if ($zip === null) {
            return null;
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if (($name === 'h5p.json' || str_starts_with($name, 'content/')) && !str_ends_with($name, '/')) {
                $entries[$name] = (int) $stat['crc'];
            }
        }
        $zip->close();
        ksort($entries);
        return $entries;
    }

    #[\Override]
    public function check_place(\stdClass $item, \stdClass $version): ?string {
        $missing = self::get_missing_libraries_of_version($version);
        if (!$missing) {
            return null;
        }
        // Published without the library option (q39), the activity would get no libraries and could not show (bug88).
        // With the option, core installs them only for a publisher who may update libraries, as in check_update() (bug98).
        $owner = $this->get_package_owner($version);
        if (
            self::allows_library_updates($version)
            && $owner && has_capability('moodle/h5p:updatelibraries', \context_system::instance(), $owner)
        ) {
            return null;
        }
        return get_string('cannotplace_h5plibraries', 'local_nextpool', s(implode(', ', $missing)));
    }

    /**
     * Libraries a version without the library option needs but the site does not have (bug88).
     *
     * For the editorial view: courses cannot place such a version, and in-place updates to it are refused.
     *
     * @param \stdClass $version Version record.
     * @return string[] Missing libraries as "Machine.Name major.minor"; empty if the version allows library updates.
     */
    public static function get_blocking_libraries(\stdClass $version): array {
        return self::allows_library_updates($version) ? [] : self::get_missing_libraries_of_version($version);
    }

    /**
     * Libraries the package of a version needs that the site does not have in a usable version.
     *
     * @param \stdClass $version Version record.
     * @return string[] Missing libraries as "Machine.Name major.minor"; empty if the package has no h5p.json.
     */
    private static function get_missing_libraries_of_version(\stdClass $version): array {
        $file = \local_nextpool\local\version_manager::get_file((int) $version->id);
        $h5pjson = $file ? self::read_h5p_json($file) : null;
        return $h5pjson === null ? [] : self::get_missing_libraries($h5pjson);
    }

    /**
     * Libraries the package needs that the site does not have in a usable version.
     *
     * Asks the core H5P framework the same way core does when it deploys a package without library folders:
     * a library counts as present when one with the same machine name, major and minor version is installed
     * (any patch version, core_h5p\framework::getLibraryId()) and enabled (core_h5p\api::is_library_enabled(),
     * bug88).
     *
     * @param array $h5pjson Decoded h5p.json of the package.
     * @return string[] Missing libraries as "Machine.Name major.minor".
     */
    public static function get_missing_libraries(array $h5pjson): array {
        $framework = (new \core_h5p\factory())->get_framework();
        $missing = [];
        foreach ($h5pjson['preloadedDependencies'] ?? [] as $dependency) {
            $name = (string) ($dependency['machineName'] ?? '');
            $major = (int) ($dependency['majorVersion'] ?? 0);
            $minor = (int) ($dependency['minorVersion'] ?? 0);
            $libraryid = $name === '' ? false : $framework->getLibraryId($name, $major, $minor);
            if (!$libraryid || !\core_h5p\api::is_library_enabled((object) ['id' => (int) $libraryid])) {
                $missing[] = "$name $major.$minor";
            }
        }
        return $missing;
    }

    /**
     * Whether the version was published with the option to update H5P libraries (q39).
     *
     * @param \stdClass $version Version record.
     * @return bool
     */
    public static function allows_library_updates(\stdClass $version): bool {
        return !empty($version->h5plibraries);
    }

    /**
     * Libraries in the package that the site does not have yet or only in an older patch version (q39).
     *
     * Reads the library.json of every library folder in the package. These are the
     * libraries core would install or update site-wide when it deploys the package
     * on behalf of someone who may update libraries.
     *
     * @param \stored_file $package .h5p file.
     * @return string[] Libraries as "Machine.Name major.minor.patch", sorted.
     */
    public static function get_library_changes(\stored_file $package): array {
        global $DB;
        $zip = self::open_package($package);
        if ($zip === null) {
            return [];
        }
        $changes = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!preg_match('~^[^/]+/library\.json$~', $name)) {
                    continue;
                }
                $library = json_decode((string) self::read_zip_entry($zip, $name, 1048576), true);
                if (!is_array($library) || empty($library['machineName'])) {
                    continue;
                }
                $machinename = (string) $library['machineName'];
                $major = (int) ($library['majorVersion'] ?? 0);
                $minor = (int) ($library['minorVersion'] ?? 0);
                $patch = (int) ($library['patchVersion'] ?? 0);
                $installed = $DB->get_field_sql(
                    'SELECT MAX(patchversion) FROM {h5p_libraries}
                      WHERE machinename = :name AND majorversion = :major AND minorversion = :minor',
                    ['name' => $machinename, 'major' => $major, 'minor' => $minor]
                );
                if ($installed === false || $installed === null || (int) $installed < $patch) {
                    $changes[] = "$machinename $major.$minor.$patch";
                }
            }
        } finally {
            $zip->close();
        }
        sort($changes);
        return $changes;
    }

    /**
     * Copy of an H5P package without its library folders: h5p.json and the content only (q39).
     *
     * Core deploys such a package with the libraries installed on the site and
     * cannot install or update libraries from it.
     *
     * @param \stored_file $package .h5p file.
     * @return string|null Path of a temporary .h5p file, or null if the package is not a ZIP.
     */
    public static function create_content_only_package(\stored_file $package): ?string {
        $source = self::open_package($package);
        if ($source === null) {
            return null;
        }
        $path = make_request_directory() . '/package.h5p';
        $target = new \ZipArchive();
        if ($target->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $source->close();
            return null;
        }
        try {
            $budget = page::get_max_unpacked_size();
            $declared = 0;
            for ($i = 0; $i < $source->numFiles; $i++) {
                $entry = $source->statIndex($i);
                $name = (string) ($entry['name'] ?? '');
                if ($name === 'h5p.json' || str_starts_with($name, 'content/')) {
                    $declared += (int) $entry['size'];
                    if ($declared > $budget) {
                        throw new \moodle_exception('error_h5ptoolarge', 'local_nextpool', '', display_size($budget));
                    }
                }
            }
            $actual = 0;
            // JSON metadata is small; media is streamed rather than materialised in PHP memory (bug131).
            self::read_zip_entry($source, 'h5p.json', 1048576);
            for ($i = 0; $i < $source->numFiles; $i++) {
                $stat = $source->statIndex($i);
                $name = (string) $stat['name'];
                if ($name !== 'h5p.json' && !str_starts_with($name, 'content/')) {
                    continue;
                }
                $directory = str_ends_with($name, '/');
                $relative = $directory ? rtrim($name, '/') : $name;
                if (page::normalise_path($relative) !== $relative) {
                    throw new \moodle_exception('error_archiveentryinvalid', 'local_nextpool');
                }
                if ($directory) {
                    $target->addEmptyDir($relative);
                    continue;
                }
                if (str_ends_with(strtolower($name), '.json')) {
                    self::read_zip_entry($source, $name, $name === 'h5p.json' ? 1048576 : 10485760);
                }
                $entryfile = make_request_directory() . '/entry';
                $input = $source->getStream($name);
                $output = fopen($entryfile, 'wb');
                if (!$input || !$output) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new \moodle_exception('error_archiveentryinvalid', 'local_nextpool');
                }
                try {
                    $copied = stream_copy_to_stream($input, $output, (int) $stat['size'] + 1);
                    $actual += $copied === false ? 0 : $copied;
                    if ($actual > $budget) {
                        throw new \moodle_exception('error_h5ptoolarge', 'local_nextpool', '', display_size($budget));
                    }
                    if ($copied !== (int) $stat['size']) {
                        throw new \moodle_exception('error_archiveentryinvalid', 'local_nextpool');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if (!$target->addFile($entryfile, $name)) {
                    throw new \moodle_exception('error_archiveentryinvalid', 'local_nextpool');
                }
            }
        } finally {
            $source->close();
            $target->close();
        }
        return $path;
    }

    /**
     * Store the package of a version in a file area, without libraries unless the version allows updates (q39).
     *
     * @param array $filerecord File record.
     * @param \stored_file $snapshot Version snapshot.
     * @param \stdClass $version Version record.
     * @return \stored_file
     */
    private function create_package_file(array $filerecord, \stored_file $snapshot, \stdClass $version): \stored_file {
        $fs = get_file_storage();
        if (!self::allows_library_updates($version)) {
            $path = self::create_content_only_package($snapshot);
            if ($path !== null) {
                return $fs->create_file_from_pathname($filerecord, $path);
            }
        }
        return $fs->create_file_from_storedfile($filerecord, $snapshot);
    }

    /**
     * Decoded h5p.json of an H5P package, or null if the package has none.
     *
     * @param \stored_file $package .h5p file.
     * @return array|null
     */
    public static function read_h5p_json(\stored_file $package): ?array {
        $zip = self::open_package($package);
        if ($zip === null) {
            return null;
        }
        try {
            $content = self::read_zip_entry($zip, 'h5p.json', 1048576);
        } finally {
            $zip->close();
        }
        $json = $content === false ? null : json_decode($content, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Current package of an H5P activity.
     *
     * @param \context_module $context Module context.
     * @return \stored_file|null
     */
    private function get_package(\context_module $context): ?\stored_file {
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * Owner of the package file: the person who published the version, else who created it (q18).
     *
     * @param \stdClass $version Version record.
     * @return int User id, 0 if unknown.
     */
    private function get_package_owner(\stdClass $version): int {
        return (int) ($version->publishedby ?: $version->createdby);
    }
}
