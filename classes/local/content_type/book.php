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
 * Content type 'book' as a core Book (mod_book), updated in place (q16).
 *
 * A version is a ZIP with one HTML file per chapter at its top level, in file
 * name order; files whose name ends in "_sub" become subchapters; images and
 * other files the chapters link to come along. This is the format of the core book import
 * (booktool_importhtml, "each HTML file is a chapter"). An update replaces all
 * chapters of the same book. Books keep no learner data besides views and
 * completion, so nothing is lost.
 *
 * mod_book shows chapters without cleaning, and the ZIP comes from a storage
 * location people without Moodle rights may write to; every chapter is cleaned.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book extends base {
    /** @var int booktool_importhtml type "each HTML file is a chapter" (1 would be "each folder"). */
    private const IMPORT_EACH_FILE = 2;

    #[\Override]
    public function get_modname(): string {
        return 'book';
    }

    #[\Override]
    public function get_file_extensions(): array {
        return ['zip'];
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_book', 'local_nextpool');
    }

    /**
     * Only a ZIP with at least one HTML file at its top level becomes a version, the rule of count_chapter_files()
     * (bug205).
     *
     * @param string $path Local path of the file.
     * @throws \moodle_exception If the file is not a ZIP or has no chapter.
     */
    #[\Override]
    public function validate_package(string $path): void {
        $zip = self::open_zip($path);
        if ($zip === null) {
            $this->refuse_file();
        }
        $chapters = 0;
        for ($i = 0; $i < $zip->numFiles && !$chapters; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $chapters = !str_contains($name, '/') && mimeinfo('icon', $name) === 'markup' ? 1 : 0;
        }
        $zip->close();
        if (!$chapters) {
            throw new \moodle_exception('error_bookempty', 'local_nextpool');
        }
    }

    #[\Override]
    public function supports_preview(): bool {
        return true;
    }

    #[\Override]
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/tool/importhtml/locallib.php');
        $file = $this->get_snapshot($version);
        $zip = \local_nextpool\local\preview_assets::open($file);
        if (!$zip) {
            return parent::get_preview($item, $version, $courseid);
        }
        try {
            $chapters = [];
            foreach (toolbook_importhtml_get_chapter_files($file, self::IMPORT_EACH_FILE) as $chapter) {
                $name = $chapter->pathname;
                $html = \local_nextpool\local\preview_assets::read($zip, $name);
                if ($html === null) {
                    continue;
                }
                $chapters[] = [
                    'title' => toolbook_importhtml_parse_title($html, pathinfo($name, PATHINFO_FILENAME)),
                    'html' => \local_nextpool\local\preview_assets::html(
                        $html,
                        $version,
                        $courseid,
                        $zip,
                        dirname($name) === '.' ? '' : dirname($name) . '/'
                    ),
                ];
            }
            return new \local_nextpool\local\preview_result('chapters', chapters: $chapters);
        } finally {
            $zip->close();
        }
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $snapshot = $this->get_snapshot($version);
        if (!self::count_chapter_files($snapshot)) {
            throw new \moodle_exception('error_bookempty', 'local_nextpool');
        }

        $config = get_config('book');
        // The chapters come after the module exists; a failing import must not leave an empty book (bug55).
        return $this->create_module_cleanly((object) [
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $item->title,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'numbering' => (int) ($config->numbering ?? 1),
            'navstyle' => (int) ($config->navstyle ?? 1),
            'customtitles' => 0,
        ], fn(\cm_info $cm) => $this->import_chapters($cm, $snapshot));
    }

    #[\Override]
    public function check_update(\cm_info $cm, \stdClass $item, \stdClass $version): ?string {
        return self::count_chapter_files($this->get_snapshot($version)) ? null : get_string('error_bookempty', 'local_nextpool');
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $DB;
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $snapshot = $this->get_snapshot($version);
        if (!self::count_chapter_files($snapshot)) {
            throw new \moodle_exception('error_bookempty', 'local_nextpool');
        }
        $context = \context_module::instance($cm->id);

        $transaction = $DB->start_delegated_transaction();
        get_file_storage()->delete_area_files($context->id, 'mod_book', 'chapter');
        $DB->delete_records('book_chapters', ['bookid' => $cm->instance]);
        $this->import_chapters($cm, $snapshot);
        // The revision is part of the chapter file URLs; raising it stops browsers from showing old images.
        $DB->execute('UPDATE {book} SET revision = revision + 1, timemodified = :now WHERE id = :id', [
            'now' => time(),
            'id' => $cm->instance,
        ]);
        $transaction->allow_commit();

        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
    }

    #[\Override]
    public function supports_extract(): bool {
        return true;
    }

    /**
     * Write the chapters of a book as a ZIP in the format of the core book import (task83).
     *
     * One HTML file per chapter in chapter order ("001.html", subchapters "002_sub.html"), the title in <title>, the
     * chapter files in "files/<chapter number>/". Hidden chapters are left out ((t83-c)): placing would show them.
     *
     * @param \cm_info $cm Course module of the book.
     * @param string $targetfile Absolute path of the ZIP to write.
     * @return string File name.
     * @throws \moodle_exception If the book has no visible chapter.
     */
    #[\Override]
    public function extract(\cm_info $cm, string $targetfile): string {
        global $DB;
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $context = \context_module::instance($cm->id);
        $chapters = $DB->get_records('book_chapters', ['bookid' => $cm->instance, 'hidden' => 0], 'pagenum');
        if (!$chapters) {
            throw new \moodle_exception('error_bookempty', 'local_nextpool');
        }
        $fs = get_file_storage();
        $zip = new \ZipArchive();
        if ($zip->open($targetfile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('error_extractfailed', 'local_nextpool');
        }
        $number = 0;
        foreach ($chapters as $chapter) {
            $number++;
            $html = (string) $chapter->content;
            if ((int) $chapter->contentformat !== FORMAT_HTML) {
                $html = format_text($html, (int) $chapter->contentformat, [
                    'context' => $context,
                    'noclean' => true,
                    'filter' => false,
                    'para' => false,
                ]);
            }
            $folder = 'files/' . $number;
            $files = $fs->get_area_files($context->id, 'mod_book', 'chapter', $chapter->id, 'filepath, filename', false);
            foreach ($files as $file) {
                $path = ltrim($file->get_filepath() . $file->get_filename(), '/');
                $reference = '@@PLUGINFILE@@/' . implode('/', array_map('rawurlencode', explode('/', $path)));
                if (str_contains($html, $reference)) {
                    // The core import looks the files up by their plain path.
                    $html = str_replace($reference, $folder . '/' . $path, $html);
                    $zip->addFromString($folder . '/' . $path, $file->get_content());
                }
            }
            // The core import reads the title up to the first "<" as it is.
            $title = str_replace('<', '&lt;', (string) $chapter->title);
            $document = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>' . $title
                . "</title></head>\n<body>\n" . $html . "\n</body></html>\n";
            $zip->addFromString(sprintf('%03d', $number) . (!empty($chapter->subchapter) ? '_sub' : '') . '.html', $document);
        }
        $zip->close();
        return self::get_extract_filename($cm, 'zip');
    }

    /**
     * Import the chapters of a ZIP into a book with the core book import, then clean them.
     *
     * @param \cm_info $cm Course module of the book.
     * @param \stored_file $package ZIP with one HTML file per chapter.
     */
    protected function import_chapters(\cm_info $cm, \stored_file $package): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        require_once($CFG->dirroot . '/mod/book/tool/importhtml/locallib.php');
        $context = \context_module::instance($cm->id);
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);

        toolbook_importhtml_import_chapters($package, self::IMPORT_EACH_FILE, $book, $context, false);
        get_file_storage()->delete_area_files($context->id, 'mod_book', 'importhtmltemp');
        foreach ($DB->get_records('book_chapters', ['bookid' => $book->id], '', 'id, content') as $chapter) {
            $DB->set_field('book_chapters', 'content', purify_html($chapter->content), ['id' => $chapter->id]);
        }
        book_preload_chapters($book);
    }

    /**
     * Number of HTML files the core import would turn into chapters.
     *
     * @param \stored_file $package ZIP.
     * @return int
     */
    public static function count_chapter_files(\stored_file $package): int {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/tool/importhtml/locallib.php');
        try {
            return count(toolbook_importhtml_get_chapter_files($package, self::IMPORT_EACH_FILE));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
