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

namespace local_nextpool\local\source;

/**
 * Immutable description of a file or folder in a storage backend.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class entry {
    /**
     * Constructor.
     *
     * @param string $path Path relative to the backend's base folder ('' for the base folder itself).
     * @param bool $isfolder Whether this is a folder.
     * @param int $size Size in bytes (0 for folders).
     * @param string $mimetype MIME type ('' if unknown or folder).
     * @param string $revision Backend change marker (ETag, content hash). Changes whenever the content changes.
     * @param int $mtime Last modified unix timestamp.
     * @param string $externalid Backend-specific stable identifier (e.g. Nextcloud file id), '' if none.
     */
    public function __construct(
        /** @var string Path relative to the base folder. */
        public readonly string $path,
        /** @var bool Whether this is a folder. */
        public readonly bool $isfolder = false,
        /** @var int Size in bytes. */
        public readonly int $size = 0,
        /** @var string MIME type. */
        public readonly string $mimetype = '',
        /** @var string Change marker. */
        public readonly string $revision = '',
        /** @var int Last modified unix timestamp. */
        public readonly int $mtime = 0,
        /** @var string Backend-specific identifier. */
        public readonly string $externalid = '',
    ) {
    }

    /**
     * Base name of the entry.
     *
     * @return string
     */
    public function get_name(): string {
        $pos = strrpos($this->path, '/');
        return $pos === false ? $this->path : substr($this->path, $pos + 1);
    }
}
