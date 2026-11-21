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

use local_nextpool\local\content_type\page;

/**
 * Safe, permission-checked inline assets for previews without course modules (F16 p176-n/o).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_assets {
    /** @var string[] Formats that may be delivered inline. No SVG, HTML or script formats. */
    public const MIME_TYPES = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
    ];
    /** @var int Maximum bytes read into memory for one text or image asset. */
    public const MAX_ENTRY = 10485760;

    /**
     * URL through the preview callback; the course id is an access context, never a capability.
     *
     * @param int $versionid Version id.
     * @param int $courseid Course id.
     * @param string $path Relative asset path.
     * @return \moodle_url
     */
    public static function url(int $versionid, int $courseid, string $path): \moodle_url {
        return \moodle_url::make_pluginfile_url(
            \context_system::instance()->id,
            'local_nextpool',
            'preview',
            $versionid,
            '/' . $courseid . '/' . (dirname($path) === '.' ? '' : dirname($path) . '/'),
            basename($path),
            false
        );
    }

    /**
     * Open a snapshot archive with bounded entries and total size.
     *
     * @param \stored_file $file Snapshot.
     * @return \ZipArchive|null
     */
    public static function open(\stored_file $file): ?\ZipArchive {
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file, true);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        if ($zip->numFiles > 2000) {
            $zip->close();
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += $zip->statIndex($i)['size'];
        }
        if ($zip->numFiles > 2000 || $total > page::get_max_unpacked_size()) {
            $zip->close();
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        return $zip;
    }

    /**
     * Read a bounded archive entry, counting actual output as well as declared size.
     *
     * @param \ZipArchive $zip Archive.
     * @param string $name Entry name.
     * @return string|null
     */
    public static function read(\ZipArchive $zip, string $name): ?string {
        $stat = $zip->statName($name);
        if (!$stat || $stat['size'] > self::MAX_ENTRY) {
            return null;
        }
        $stream = $zip->getStream($name);
        if (!$stream) {
            return null;
        }
        try {
            $content = stream_get_contents($stream, self::MAX_ENTRY + 1);
            return $content !== false && strlen($content) === $stat['size'] ? $content : null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Clean HTML and copy only referenced, safe inline assets from the package.
     *
     * @param string $html HTML.
     * @param \stdClass $version Version.
     * @param int $courseid Course id.
     * @param \ZipArchive|null $zip Optional archive.
     * @param string $base Directory of a book chapter, relative to archive root.
     * @return string Purified HTML.
     */
    public static function html(
        string $html,
        \stdClass $version,
        int $courseid,
        ?\ZipArchive $zip = null,
        string $base = ''
    ): string {
        $html = page::get_body($html);
        if ($zip) {
            $html = preg_replace_callback(
                '~\b(src|href)\s*=\s*([\'"])(.*?)\2~is',
                static function (array $match) use ($zip, $version, $courseid, $base): string {
                    $value = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (str_contains($value, ':') || str_starts_with($value, '/') || str_starts_with($value, '#')) {
                        return $match[0];
                    }
                    $path = page::normalise_path($base . rawurldecode(explode('?', explode('#', $value)[0])[0]));
                    if ($path === null || !in_array(mimeinfo('type', $path), self::MIME_TYPES, true)) {
                        return '';
                    }
                    $content = self::read($zip, $path);
                    if ($content === null) {
                        return '';
                    }
                    $contextid = \context_system::instance()->id;
                    $filepath = dirname('/' . $path) . '/';
                    $filepath = $filepath === '//' ? '/' : $filepath;
                    $fs = get_file_storage();
                    $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('preview_' . $version->id, 10);
                    if (!$lock) {
                        throw new \moodle_exception('previewunavailable', 'local_nextpool');
                    }
                    try {
                        $exists = $fs->file_exists(
                            $contextid,
                            'local_nextpool',
                            'previewassets',
                            $version->id,
                            $filepath,
                            basename($path)
                        );
                        if (!$exists) {
                            $fs->create_file_from_string([
                                'contextid' => $contextid, 'component' => 'local_nextpool', 'filearea' => 'previewassets',
                                'itemid' => $version->id, 'filepath' => $filepath, 'filename' => basename($path),
                                'userid' => h5p_guard::get_owner(),
                            ], $content);
                        }
                    } finally {
                        $lock->release();
                    }
                    return $match[1] . '="' . s(self::url((int) $version->id, $courseid, $path)->out(false)) . '"';
                },
                $html
            );
        }
        return purify_html($html);
    }

    /**
     * Resolve an inline asset after checking the current version and course catalogue access.
     *
     * @param int $versionid Version id.
     * @param int $courseid Course id.
     * @param string $path Asset path, or snapshot file name for type file.
     * @return \stored_file|null
     */
    public static function find(int $versionid, int $courseid, string $path): ?\stored_file {
        global $DB;
        $version = $DB->get_record(version_manager::TABLE, ['id' => $versionid, 'status' => status::PUBLISHED]);
        if (!$version) {
            return null;
        }
        $item = item_manager::get((int) $version->itemid);
        if (
            (int) $item->currentversionid !== $versionid || !access::can_preview($item, $courseid)
                || page::normalise_path($path) !== $path
        ) {
            return null;
        }
        if ($item->type === 'file' && $path === $version->filename) {
            $file = version_manager::get_file($versionid);
        } else {
            $filepath = dirname('/' . $path) . '/';
            $file = get_file_storage()->get_file(
                \context_system::instance()->id,
                'local_nextpool',
                'previewassets',
                $versionid,
                $filepath === '//' ? '/' : $filepath,
                basename($path)
            );
        }
        return $file && !$file->is_directory() && in_array($file->get_mimetype(), self::MIME_TYPES, true) ? $file : null;
    }

    /**
     * Remove all preview assets with the version.
     *
     * @param int $versionid Version id.
     */
    public static function delete(int $versionid): void {
        global $DB;
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        h5p_preview::delete($versionid);
        $fs->delete_area_files($contextid, 'local_nextpool', 'previewassets', $versionid);
        foreach (
            $DB->get_fieldset('files', 'DISTINCT contextid', [
            'component' => 'local_nextpool', 'filearea' => 'scormpackage', 'itemid' => $versionid,
            ]) as $scormcontextid
        ) {
            $fs->delete_area_files($scormcontextid, 'local_nextpool', 'scormpackage', $versionid);
        }
    }
}
