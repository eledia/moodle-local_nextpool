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

use local_nextpool\local\item_manager;
use local_nextpool\local\version_manager;

/**
 * Contract for content type handlers (F04, adr07).
 *
 * A handler turns a catalogue version into a course module (place) and
 * writes a newer version into the same course module (update_in_place), so
 * the cmid and everything attached to it - completion, grades, logs - stays.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /**
     * Activity module the handler creates, e.g. 'resource'.
     *
     * @return string
     */
    abstract public function get_modname(): string;

    /**
     * Create a course module from a version. Runs as the placing teacher.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version record.
     * @param \stdClass $course Course record.
     * @param int $section Section number.
     * @param array $options Handler options from the placement form, see get_place_options().
     * @return int The new course module id.
     */
    abstract public function place(
        \stdClass $item,
        \stdClass $version,
        \stdClass $course,
        int $section,
        array $options = []
    ): int;

    /**
     * Add fields of this type to the placement form (task45), e.g. display or completion rules.
     *
     * The catalogue does not know these fields; it hands what get_place_options() returns to place().
     *
     * @param \MoodleQuickForm $form Placement form.
     */
    public function extend_place_form(\MoodleQuickForm $form): void {
    }

    /**
     * Options for place() from the submitted placement form.
     *
     * @param \stdClass $data Submitted form data.
     * @return array Options, [] if the type has none.
     */
    public function get_place_options(\stdClass $data): array {
        return [];
    }

    /**
     * Write a version into an existing course module, keeping the cmid.
     *
     * May run without a user session (adhoc task).
     *
     * @param \cm_info $cm Course module of the placement.
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version to apply.
     */
    abstract public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void;

    /**
     * Whether a version can be written into the course module without losing learner data.
     *
     * Handlers override this when an in-place update is not always safe, for
     * example a quiz whose structure changes while attempts exist. The update
     * engine then offers the version to the teacher instead of applying it
     * automatically (F06, downgrade to notify).
     *
     * @param \cm_info $cm Course module of the placement.
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version to apply.
     * @return string|null Null if the update is possible, otherwise a localised reason.
     */
    public function check_update(\cm_info $cm, \stdClass $item, \stdClass $version): ?string {
        return null;
    }

    /**
     * Whether a version can be placed as a new activity in a course.
     *
     * Handlers override this when a version cannot work in a course on this site, for example an H5P
     * package whose libraries the site does not have and may not install (bug88). Placing is then refused
     * and the placement page explains why instead of showing the form.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version to place.
     * @return string|null Null if the version can be placed, otherwise a localised reason for teachers.
     */
    public function check_place(\stdClass $item, \stdClass $version): ?string {
        return null;
    }

    /**
     * Check a file before it becomes a version of an item of this type (F04-quiz, F04Q.AC06).
     *
     * @param string $path Local path of the file.
     * @throws \moodle_exception With a reason the editorial team can act on, if the file cannot become a version.
     */
    public function validate_package(string $path): void {
    }

    /**
     * Whether this handler provides a preview without creating a course activity.
     *
     * @return bool
     */
    public function supports_preview(): bool {
        return false;
    }

    /**
     * Preview of a published catalogue version (F16).
     *
     * Caller checks preview access; providers must not record learning data.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Published version.
     * @param int $courseid Course from which the preview was opened.
     * @return \local_nextpool\local\preview_result
     */
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        $hook = new \local_nextpool\hook\collect_preview_providers();
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $provider = $hook->get_providers()[$item->type] ?? null;
        if ($provider !== null) {
            return $provider::get_preview($item, $version, $courseid);
        }
        return new \local_nextpool\local\preview_result(message: get_string('previewunavailable', 'local_nextpool'));
    }

    /**
     * Current activity content hash, null when this type cannot compare it.
     *
     * @param \cm_info $cm Course module.
     * @return string|null
     */
    public function get_course_content_hash(\cm_info $cm): ?string {
        return null;
    }

    /**
     * Comparable hash of a version.
     *
     * @param \stdClass $version Version record.
     * @return string|null
     */
    public function get_version_content_hash(\stdClass $version): ?string {
        return $version->contenthash ?: null;
    }

    /**
     * Metadata suggested for a direct upload (M08).
     *
     * @param string $path Local file.
     * @param string $filename Original file name.
     * @return array Suggested title and optional description, standard or missinglibraries.
     */
    public function read_upload_metadata(string $path, string $filename): array {
        return ['title' => pathinfo($filename, PATHINFO_FILENAME)];
    }

    /**
     * File name extensions of a version of this type, without dot, e.g. ['zip'] (bug206).
     *
     * The import page offers only files with one of them; validate_package() checks the content.
     *
     * @return string[] Lower case; [] if the type takes any file.
     */
    public function get_file_extensions(): array {
        return [];
    }

    /**
     * Which file a version of this type is, for the import page, the item form and error messages (bug206).
     *
     * @return string Localised sentence, '' if the type gives no hint.
     */
    public function get_expected_file(): string {
        return '';
    }

    /**
     * Whether a file name has one of the extensions of get_file_extensions() (bug206).
     *
     * @param string $filename File name.
     * @return bool
     */
    public function accepts_filename(string $filename): bool {
        $extensions = $this->get_file_extensions();
        return !$extensions || in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $extensions, true);
    }

    /**
     * Refuse a file whose content is not of this type, naming the file the type expects (bug205).
     *
     * @throws \moodle_exception Always, error_filenotfortype.
     */
    protected function refuse_file(): void {
        throw new \moodle_exception('error_filenotfortype', 'local_nextpool', '', $this->get_expected_file());
    }

    /**
     * Read a ZIP metadata entry into memory with a declared and actual byte limit (bug131).
     *
     * @param \ZipArchive $zip Open archive.
     * @param string $name Entry name.
     * @param int $maxbytes Maximum bytes held in memory, defaults to ten MiB.
     * @return string|false Content, false if the entry is absent.
     * @throws \moodle_exception If the entry is too large, corrupt or unreadable.
     */
    public static function read_zip_entry(\ZipArchive $zip, string $name, int $maxbytes = 10485760): string|false {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return false;
        }
        if ($stat['size'] > $maxbytes) {
            throw new \moodle_exception('error_archiveentrytoolarge', 'local_nextpool', '', display_size($maxbytes));
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new \moodle_exception('error_archiveentryinvalid', 'local_nextpool');
        }
        try {
            $content = stream_get_contents($stream, $maxbytes + 1);
            if ($content === false || strlen($content) !== (int) $stat['size'] || strlen($content) > $maxbytes) {
                throw new \moodle_exception('error_archiveentryinvalid', 'local_nextpool');
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Open a local file as a ZIP for reading (bug205).
     *
     * @param string $path Local path.
     * @return \ZipArchive|null The open archive (close it), or null if the file is not a ZIP.
     */
    protected static function open_zip(string $path): ?\ZipArchive {
        $zip = new \ZipArchive();
        return is_readable($path) && $zip->open($path, \ZipArchive::RDONLY) === true ? $zip : null;
    }

    /**
     * Whether the type can take an activity of a course back as the content of a proposal (F07, q64, TD-308).
     *
     * @return bool
     */
    public function supports_extract(): bool {
        return false;
    }

    /**
     * Write an activity of this type as a file in the exchange format that place() and update_in_place() read.
     *
     * Placing the file again gives the same content (round trip). The activity is only read.
     *
     * @param \cm_info $cm Course module of the type's activity.
     * @param string $targetfile Absolute path of the file to write.
     * @return string File name for the file, with the extension of the format written, e.g. "Evacuation.html".
     * @throws \moodle_exception With a reason teachers can act on, if the activity cannot be taken over.
     */
    public function extract(\cm_info $cm, string $targetfile): string {
        throw new \coding_exception('Content type ' . $this->get_modname() . ' cannot take over an activity.');
    }

    /**
     * File name from the name of an activity and an extension.
     *
     * @param \cm_info $cm Course module.
     * @param string $extension Extension without dot.
     * @return string
     */
    protected static function get_extract_filename(\cm_info $cm, string $extension): string {
        $name = clean_filename(trim(\core_text::substr(html_to_text($cm->name, 0, false), 0, 100)));
        return ($name === '' || $name === '_' ? $cm->modname : $name) . '.' . $extension;
    }

    /**
     * Whether the type can place a version as a new activity next to the placed one (q29, soft-replace of adr07).
     *
     * @return bool
     */
    public function can_place_alongside(): bool {
        return false;
    }

    /**
     * Place a version as a new activity right after the placed one and hide the old one; it is never deleted (q29).
     *
     * Used when a version cannot be written into the course module, for example a quiz with attempts whose
     * questions change. The teacher's settings of the old activity are kept where the type can do so.
     *
     * @param \cm_info $cm Course module of the placement.
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version to place.
     * @return int The new course module id.
     */
    public function place_alongside(\cm_info $cm, \stdClass $item, \stdClass $version): int {
        throw new \coding_exception('Content type ' . $this->get_modname() . ' cannot place a version alongside.');
    }

    /**
     * Whether activities of this type have learning data the item statistics can show (F15, q111).
     *
     * Types without learning data (file, page, link, ...) show placements and a notice only.
     *
     * @return bool
     */
    public function supports_learning_data(): bool {
        return false;
    }

    /**
     * Anonymous learning statistics of an item over the given course modules of its placements (F15, plan 21 p110-a).
     *
     * The handler computes the figures from the learning data of its activities and returns them already anonymised
     * by the anonymity rule (D9); values of single people never leave the handler. It counts people, not activities
     * (p110-c), and each person in the version they learned last (FR-F15-02). Value names and flags are those of
     * {@see \local_nextpool\local\item_statistics::VALUES} and its FLAG_* constants.
     *
     * Each figure set is an array with
     * - values: value name => int|float|null (null if suppressed or without value);
     * - flags: value name => flag, for every value that is null.
     *
     * @param \stdClass $item Item record.
     * @param int[] $cmids Course modules of the item's placements.
     * @return array{coursecount:?int,total:array,versions:array<int,array>}|null Figures in total and per version number
     *     (only versions someone learned with), coursecount null below two courses with learning data; null if the
     *     type has no learning data.
     */
    public function get_learning_statistics(\stdClass $item, array $cmids): ?array {
        return null;
    }

    /**
     * Snapshot file of a version.
     *
     * @param \stdClass $version Version record.
     * @return \stored_file
     * @throws \moodle_exception If the snapshot is missing.
     */
    protected function get_snapshot(\stdClass $version): \stored_file {
        $file = version_manager::get_file((int) $version->id);
        if (!$file) {
            throw new \moodle_exception('error_snapshotmissing', 'local_nextpool', '', (int) $version->versionnr);
        }
        return $file;
    }

    /**
     * Create a course module and run what completes it; if that fails, the course module is deleted again (bug55).
     *
     * @param \stdClass $moduleinfo Module data for core's create_module().
     * @param callable|null $complete Called with the new cm_info, e.g. to import content.
     * @return int The new course module id.
     */
    protected function create_module_cleanly(\stdClass $moduleinfo, ?callable $complete = null): int {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $cmid = (int) create_module($moduleinfo)->coursemodule;
        if ($complete !== null) {
            try {
                $complete(get_fast_modinfo((int) $moduleinfo->course)->get_cm($cmid));
            } catch (\Throwable $e) {
                self::delete_module_quietly($cmid, (int) $moduleinfo->course);
                throw $e;
            }
        }
        return $cmid;
    }

    /**
     * Delete a course module that placing created but could not finish; a failure here must not hide the cause.
     *
     * @param int $cmid Course module id.
     * @param int $courseid Course id.
     */
    public static function delete_module_quietly(int $cmid, int $courseid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        try {
            if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
                // Moodle 5.2 and later; course_delete_module() is deprecated there.
                \core_courseformat\formatactions::cm($courseid)->delete($cmid);
            } else {
                course_delete_module($cmid);
            }
        } catch (\Throwable $e) {
            debugging('local_nextpool: could not delete course module ' . $cmid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Intro editor data for a new course module from the item description, cleaned (bug41).
     *
     * Core shows module intros without cleaning, and one item reaches many courses, so the description is
     * converted to HTML and purified before it becomes the intro (like page content, bug05).
     *
     * @param \stdClass $item Item record.
     * @return array{text:string,format:int,itemid:int}
     */
    protected function get_intro_editor(\stdClass $item): array {
        $text = (string) $item->description;
        $format = (int) $item->descriptionformat;
        if ($format !== FORMAT_HTML) {
            $text = format_text($text, $format, ['context' => \context_system::instance(), 'filter' => false, 'para' => false]);
        }
        return ['text' => trim(purify_html($text)), 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
    }

    /**
     * Open a stored ZIP package for reading, in place in the file pool instead of copying it (bug59).
     *
     * @param \stored_file $package Package.
     * @return \ZipArchive|null The open archive (close it), or null if it is not a ZIP.
     */
    protected static function open_package(\stored_file $package): ?\ZipArchive {
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($package, true);
        $zip = new \ZipArchive();
        return is_readable($path) && $zip->open($path, \ZipArchive::RDONLY) === true ? $zip : null;
    }

    /**
     * Licence, author and source to store on files copied into a course (F09).
     *
     * @param \stdClass $item Item record.
     * @return array{license:string,author:string,source:string}
     */
    protected function get_file_attribution(\stdClass $item): array {
        $names = array_column(item_manager::get_author_names((int) $item->id), 'name');
        return [
            'license' => (string) $item->license,
            'author' => \core_text::substr(implode(', ', $names), 0, 255),
            'source' => 'nextpool:' . $item->uuid,
        ];
    }
}
