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

namespace nextpoolsource_moodle;

use local_nextpool\local\h5p_guard;
use local_nextpool\local\source\base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\path;
use local_nextpool\local\source\source_exception;

/**
 * Storage backend on the Moodle file API.
 *
 * Files live in the system context, component nextpoolsource_moodle, file
 * area 'content', itemid = backend instance id. The folder structure of the
 * backend maps to Moodle file paths. The revision is the content hash.
 *
 * Files belong to the guest user, so core's H5P player never shows or deploys
 * them (bug238, see {@see h5p_guard}).
 *
 * @package   nextpoolsource_moodle
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source extends base {
    /** @var string File area holding the content. */
    public const FILEAREA = 'content';

    #[\Override]
    public function stat(string $path): ?entry {
        // The base folder always exists; Moodle creates folder records on first write.
        if (path::normalise($path) === '') {
            return new entry('', true);
        }
        $full = $this->resolve($path);
        [$filepath, $filename] = self::split($full);
        $fs = get_file_storage();
        $file = $fs->get_file($this->contextid(), 'nextpoolsource_moodle', self::FILEAREA, $this->get_id(), $filepath, $filename);
        if ($file && !$file->is_directory()) {
            return $this->to_entry($file);
        }
        $dir = $fs->get_file($this->contextid(), 'nextpoolsource_moodle', self::FILEAREA, $this->get_id(), '/' . $full . '/', '.');
        return $dir ? $this->to_entry($dir) : null;
    }

    #[\Override]
    public function find_by_externalid(string $externalid): ?entry {
        if (!ctype_digit($externalid)) {
            return null;
        }
        $file = get_file_storage()->get_file_by_id((int) $externalid);
        $ours = $file && !$file->is_directory()
            && (int) $file->get_contextid() === $this->contextid()
            && $file->get_component() === 'nextpoolsource_moodle'
            && $file->get_filearea() === self::FILEAREA
            && (int) $file->get_itemid() === $this->get_id();
        if (!$ours) {
            return null;
        }
        try {
            return $this->to_entry($file);
        } catch (source_exception $e) {
            // Outside the base folder: not found for this storage location.
            return null;
        }
    }

    #[\Override]
    public function list_entries(string $path = ''): array {
        $full = $this->resolve($path);
        $filepath = $full === '' ? '/' : '/' . $full . '/';
        if (path::normalise($path) !== '' && ($this->stat($path)?->isfolder ?? false) === false) {
            throw new source_exception('error_notfound');
        }
        $files = get_file_storage()->get_directory_files(
            $this->contextid(),
            'nextpoolsource_moodle',
            self::FILEAREA,
            $this->get_id(),
            $filepath,
            false,
            true
        );
        $entries = [];
        foreach ($files as $file) {
            $entries[] = $this->to_entry($file);
        }
        return self::sort_entries($entries);
    }

    #[\Override]
    public function read(string $path, string $targetfile): void {
        $file = $this->get_stored_file($path);
        if (!$file->copy_content_to($targetfile)) {
            throw new source_exception('error_read');
        }
    }

    #[\Override]
    public function write(string $path, string $sourcefile): entry {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_invalidpath');
        }
        [$filepath, $filename] = self::split($full);
        $fs = get_file_storage();
        $existing = $fs->get_file(
            $this->contextid(),
            'nextpoolsource_moodle',
            self::FILEAREA,
            $this->get_id(),
            $filepath,
            $filename
        );
        if ($existing) {
            if ($existing->is_directory()) {
                throw new source_exception('error_notafile');
            }
            $existing->delete();
        }
        $file = $fs->create_file_from_pathname([
            'contextid' => $this->contextid(),
            'component' => 'nextpoolsource_moodle',
            'filearea' => self::FILEAREA,
            'itemid' => $this->get_id(),
            'filepath' => $filepath,
            'filename' => $filename,
            'userid' => h5p_guard::get_owner(),
        ], $sourcefile);
        return $this->to_entry($file);
    }

    #[\Override]
    public function delete(string $path): void {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_notafile');
        }
        [$filepath, $filename] = self::split($full);
        $file = get_file_storage()->get_file(
            $this->contextid(),
            'nextpoolsource_moodle',
            self::FILEAREA,
            $this->get_id(),
            $filepath,
            $filename
        );
        if (!$file) {
            return;
        }
        if ($file->is_directory()) {
            throw new source_exception('error_notafile');
        }
        $file->delete();
    }

    #[\Override]
    public function delete_instance_data(): void {
        get_file_storage()->delete_area_files($this->contextid(), 'nextpoolsource_moodle', self::FILEAREA, $this->get_id());
    }

    /**
     * Load a stored file by relative path.
     *
     * @param string $path Path relative to the base folder.
     * @return \stored_file
     * @throws source_exception If missing or a folder.
     */
    private function get_stored_file(string $path): \stored_file {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_notafile');
        }
        [$filepath, $filename] = self::split($full);
        $file = get_file_storage()->get_file(
            $this->contextid(),
            'nextpoolsource_moodle',
            self::FILEAREA,
            $this->get_id(),
            $filepath,
            $filename
        );
        if (!$file) {
            throw new source_exception('error_notfound');
        }
        if ($file->is_directory()) {
            throw new source_exception('error_notafile');
        }
        return $file;
    }

    /**
     * Convert a stored file into an entry relative to the base folder.
     *
     * @param \stored_file $file File or directory record.
     * @return entry
     */
    private function to_entry(\stored_file $file): entry {
        if ($file->is_directory()) {
            return new entry($this->unresolve(trim($file->get_filepath(), '/')), true, 0, '', '', (int) $file->get_timemodified());
        }
        return new entry(
            path: $this->unresolve(ltrim($file->get_filepath(), '/') . $file->get_filename()),
            size: (int) $file->get_filesize(),
            mimetype: (string) $file->get_mimetype(),
            revision: $file->get_contenthash(),
            mtime: (int) $file->get_timemodified(),
            externalid: (string) $file->get_id(),
        );
    }

    /**
     * Split a full path into Moodle file path and file name.
     *
     * @param string $full Normalised full path, e.g. "a/b/c.pdf".
     * @return array{0:string,1:string} e.g. ["/a/b/", "c.pdf"].
     */
    private static function split(string $full): array {
        $pos = strrpos($full, '/');
        if ($pos === false) {
            return ['/', $full];
        }
        return ['/' . substr($full, 0, $pos) . '/', substr($full, $pos + 1)];
    }

    /**
     * System context id.
     *
     * @return int
     */
    private function contextid(): int {
        return \context_system::instance()->id;
    }
}
