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
 * Content type 'url': a link file becomes a core URL resource (mod_url).
 *
 * The file holds the address, either as plain text or in the Windows
 * internet shortcut format ("URL=..."). Only http and https are accepted.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url extends base {
    #[\Override]
    public function get_modname(): string {
        return 'url';
    }

    /** @var int Largest link file in bytes; a link file holds one address (bug205). */
    private const MAX_FILE_SIZE = 65536;

    #[\Override]
    public function get_file_extensions(): array {
        return ['url', 'txt'];
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_url', 'local_nextpool');
    }

    /**
     * Only a small file with an http(s) address becomes a version, not e.g. a package (bug205).
     *
     * @param string $path Local path of the file.
     * @throws \moodle_exception If the file is no link file.
     */
    #[\Override]
    public function validate_package(string $path): void {
        $size = is_readable($path) ? (int) filesize($path) : 0;
        $zip = $size ? self::open_zip($path) : null;
        $zip?->close();
        if ($size === 0 || $size > self::MAX_FILE_SIZE || $zip !== null) {
            $this->refuse_file();
        }
        self::extract_url((string) file_get_contents($path));
    }

    #[\Override]
    public function supports_preview(): bool {
        return true;
    }

    #[\Override]
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        $file = $this->get_snapshot($version);
        if ($file->get_filesize() > self::MAX_FILE_SIZE) {
            return parent::get_preview($item, $version, $courseid);
        }
        return new \local_nextpool\local\preview_result('link', self::extract_url($file->get_content()));
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $moduleinfo = create_module((object) [
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $item->title,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'externalurl' => self::extract_url($this->get_snapshot($version)->get_content()),
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'printintro' => 1,
        ]);
        return (int) $moduleinfo->coursemodule;
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $DB;
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $DB->update_record('url', (object) [
            'id' => $cm->instance,
            'externalurl' => self::extract_url($this->get_snapshot($version)->get_content()),
            'timemodified' => time(),
        ]);
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
        $address = (string) $DB->get_field('url', 'externalurl', ['id' => $cm->instance], MUST_EXIST);
        // The same check as placing: only an address the catalogue can place again is taken over.
        $content = "[InternetShortcut]\r\nURL=" . self::extract_url($address) . "\r\n";
        file_put_contents($targetfile, $content);
        return self::get_extract_filename($cm, 'url');
    }

    /**
     * Read the address from a link file.
     *
     * @param string $content File content.
     * @return string The URL.
     * @throws \moodle_exception If the file holds no http(s) address.
     */
    public static function extract_url(string $content): string {
        $candidate = '';
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if (stripos($line, 'URL=') === 0) {
                $candidate = substr($line, 4);
                break;
            }
            if ($candidate === '' && $line !== '' && $line[0] !== '[') {
                $candidate = $line;
            }
        }
        $url = clean_param(trim($candidate), PARAM_URL);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new \moodle_exception('error_invalidurlfile', 'local_nextpool');
        }
        return $url;
    }
}
