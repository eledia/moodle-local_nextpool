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
 * Content type 'page': an HTML file becomes a core Page (mod_page).
 *
 * The HTML is cleaned before it is stored: mod_page displays its content
 * with noclean, and the file comes from a storage location that people
 * without Moodle rights may write to. A version is an HTML file, or a ZIP with
 * index.html and the files it embeds by relative path (task83, (t83-b)); those
 * files are copied into the page. Other images need absolute URLs. An update
 * replaces the content with its files and raises the revision.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page extends base {
    /** @var string Page of a ZIP version. */
    public const INDEX_FILE = 'index.html';

    /** @var string Folder of the embedded files in a ZIP version written by extract(). */
    private const FILES_FOLDER = 'files';

    /** @var int Largest unpacked size of a ZIP version in MB if the site sets no maximum upload size (bug109). */
    public const DEFAULT_MAX_UNPACKED_MB = 500;

    #[\Override]
    public function get_file_extensions(): array {
        return ['html', 'htm', 'zip'];
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_page', 'local_nextpool', self::INDEX_FILE);
    }

    /**
     * An HTML file (text, not binary) or a ZIP with index.html within the size limit becomes a version (bug205).
     *
     * @param string $path Local path of the file.
     * @throws \moodle_exception If the file is neither.
     */
    #[\Override]
    public function validate_package(string $path): void {
        $zip = self::open_zip($path);
        if ($zip === null) {
            // An HTML file: text without NUL bytes, unlike a PDF, an image or a package.
            $start = is_readable($path) ? (string) file_get_contents($path, false, null, 0, 8192) : '';
            if (trim($start) === '' || str_contains($start, "\0")) {
                $this->refuse_file();
            }
            return;
        }
        try {
            self::check_unpacked_size($zip);
            if ($zip->locateName(self::INDEX_FILE) === false) {
                throw new \moodle_exception('error_pagenoindex', 'local_nextpool', '', self::INDEX_FILE);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Largest unpacked size of a ZIP version: the site's maximum upload size, or DEFAULT_MAX_UNPACKED_MB (bug109).
     *
     * @return int Bytes.
     */
    public static function get_max_unpacked_size(): int {
        global $CFG;
        $sitelimit = (int) ($CFG->maxbytes ?? 0);
        return $sitelimit > 0 ? $sitelimit : self::DEFAULT_MAX_UNPACKED_MB * 1024 * 1024;
    }

    /**
     * Refuse a ZIP whose files are larger together than allowed, before any of them is read into memory (bug109).
     *
     * @param \ZipArchive $zip Open ZIP.
     * @throws \moodle_exception If the ZIP is too large unpacked.
     */
    private static function check_unpacked_size(\ZipArchive $zip): void {
        $maxsize = self::get_max_unpacked_size();
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $size += (int) ($zip->statIndex($i)['size'] ?? 0);
            if ($size > $maxsize) {
                throw new \moodle_exception('error_pagetoolarge', 'local_nextpool', '', display_size($maxsize));
            }
        }
    }

    #[\Override]
    public function get_modname(): string {
        return 'page';
    }

    #[\Override]
    public function supports_preview(): bool {
        return true;
    }

    #[\Override]
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        $file = $this->get_snapshot($version);
        $zip = \local_nextpool\local\preview_assets::open($file);
        try {
            $html = $zip ? \local_nextpool\local\preview_assets::read($zip, self::INDEX_FILE)
                : ($file->get_filesize() <= \local_nextpool\local\preview_assets::MAX_ENTRY ? $file->get_content() : null);
            if ($html === null) {
                return parent::get_preview($item, $version, $courseid);
            }
            return new \local_nextpool\local\preview_result('html', html:
                \local_nextpool\local\preview_assets::html($html, $version, $courseid, $zip));
        } finally {
            $zip?->close();
        }
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $snapshot = $this->get_snapshot($version);
        // The embedded files of a ZIP need the page's context, so they come after the module exists (bug55).
        return $this->create_module_cleanly((object) [
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $item->title,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'content' => '',
            'contentformat' => FORMAT_HTML,
            'display' => RESOURCELIB_DISPLAY_OPEN,
            'printintro' => 0,
            'printlastmodified' => 1,
        ], fn(\cm_info $cm) => $this->write_content($cm, $snapshot, false));
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $this->write_content($cm, $this->get_snapshot($version), true);
        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm($cm, \context_module::instance($cm->id))->trigger();
    }

    #[\Override]
    public function supports_extract(): bool {
        return true;
    }

    #[\Override]
    public function extract(\cm_info $cm, string $targetfile): string {
        global $DB;
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $html = (string) $page->content;
        if ((int) $page->contentformat !== FORMAT_HTML) {
            $html = format_text($html, (int) $page->contentformat, [
                'context' => $context,
                'noclean' => true,
                'filter' => false,
                'para' => false,
            ]);
        }

        // Files the content embeds go into the ZIP with a relative path; files nobody references stay behind.
        $files = [];
        foreach (get_file_storage()->get_area_files($context->id, 'mod_page', 'content', 0, 'filepath, filename', false) as $file) {
            $path = ltrim($file->get_filepath() . $file->get_filename(), '/');
            $reference = '@@PLUGINFILE@@/' . implode('/', array_map('rawurlencode', explode('/', $path)));
            if (str_contains($html, $reference)) {
                $html = str_replace($reference, self::FILES_FOLDER . '/' . self::encode_path($path), $html);
                $files[self::FILES_FOLDER . '/' . $path] = $file;
            }
        }
        $document = '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8"><title>' . s($cm->name) . '</title></head>'
            . "\n<body>\n" . $html . "\n</body></html>\n";
        if (!$files) {
            file_put_contents($targetfile, $document);
            return self::get_extract_filename($cm, 'html');
        }
        $zip = new \ZipArchive();
        if ($zip->open($targetfile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('error_extractfailed', 'local_nextpool');
        }
        $zip->addFromString(self::INDEX_FILE, $document);
        foreach ($files as $path => $file) {
            $zip->addFromString($path, $file->get_content());
        }
        $zip->close();
        return self::get_extract_filename($cm, 'zip');
    }

    /**
     * Write the cleaned content of a version and the files it embeds into a page, raising its revision.
     *
     * @param \cm_info $cm Course module of the page.
     * @param \stored_file $snapshot Version file: HTML, or ZIP with index.html.
     * @param bool $replace Whether the page had content before (remove its files).
     */
    private function write_content(\cm_info $cm, \stored_file $snapshot, bool $replace): void {
        global $DB;
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        if ($replace) {
            $fs->delete_area_files($context->id, 'mod_page', 'content');
        }
        $zip = self::open_package($snapshot);
        if ($zip === null) {
            $html = self::get_body($snapshot->get_content());
        } else {
            try {
                $html = $this->import_package($zip, $context);
            } finally {
                $zip->close();
            }
        }
        $DB->execute(
            'UPDATE {page} SET content = :content, contentformat = :format, revision = revision + 1, timemodified = :now
              WHERE id = :id',
            ['content' => trim(purify_html($html)), 'format' => FORMAT_HTML, 'now' => time(), 'id' => $cm->instance]
        );
    }

    /**
     * Copy the files a ZIP version embeds into the page and point the HTML at them.
     *
     * Only files the page references by relative path in a src or href attribute are copied.
     *
     * @param \ZipArchive $zip Open ZIP version.
     * @param \context_module $context Page context.
     * @return string HTML of the page, not cleaned yet.
     * @throws \moodle_exception If the ZIP has no page or is too large unpacked.
     */
    private function import_package(\ZipArchive $zip, \context_module $context): string {
        self::check_unpacked_size($zip);
        $html = $zip->getFromName(self::INDEX_FILE);
        if ($html === false) {
            throw new \moodle_exception('error_pagenoindex', 'local_nextpool', '', self::INDEX_FILE);
        }
        $html = self::get_body($html);
        if (!preg_match_all('~\b(src|href)\s*=\s*"([^"]+)"~i', $html, $matches, PREG_SET_ORDER)) {
            return $html;
        }
        $fs = get_file_storage();
        $done = [];
        foreach ($matches as [$attribute, $name, $value]) {
            if (
                str_contains($value, ':') || str_starts_with($value, '#') || str_starts_with($value, '/')
                    || str_starts_with($value, '@@')
            ) {
                continue;
            }
            $path = self::normalise_path(rawurldecode(explode('#', explode('?', $value)[0])[0]));
            if ($path === null || ($content = $zip->getFromName($path)) === false) {
                continue;
            }
            if (!isset($done[$path])) {
                $filepath = dirname('/' . $path);
                $fs->create_file_from_string([
                    'contextid' => $context->id,
                    'component' => 'mod_page',
                    'filearea' => 'content',
                    'itemid' => 0,
                    'filepath' => $filepath === '/' ? '/' : $filepath . '/',
                    'filename' => basename($path),
                ], $content);
                $done[$path] = true;
            }
            $html = str_replace($attribute, $name . '="@@PLUGINFILE@@/' . self::encode_path($path) . '"', $html);
        }
        return $html;
    }

    /**
     * The body of a full HTML document, or the whole text.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function get_body(string $html): string {
        if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $matches)) {
            $html = $matches[1];
        }
        return $html;
    }

    /**
     * A relative path inside a ZIP without "." and "..", null if it leaves the ZIP or is no file name.
     *
     * @param string $path Relative path.
     * @return string|null
     */
    public static function normalise_path(string $path): ?string {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!$parts) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            if (clean_param($part, PARAM_FILE) !== $part) {
                return null;
            }
            $parts[] = $part;
        }
        return $parts ? implode('/', $parts) : null;
    }

    /**
     * A path with every part URL-encoded, as Moodle writes file references.
     *
     * @param string $path Path.
     * @return string
     */
    private static function encode_path(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
