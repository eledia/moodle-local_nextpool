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

namespace nextpoolsource_nextcloud\local;

/**
 * Immutable description of a single Nextcloud file version.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_version {
    /**
     * Constructor.
     *
     * @param string $versionkey Version identifier used by Nextcloud (usually the mtime timestamp).
     * @param int $mtime Modification time of this version as a unix timestamp.
     * @param int $size Size in bytes.
     * @param string $mimetype MIME type.
     * @param string $label Optional human readable version label (Nextcloud 26+).
     */
    public function __construct(
        /** @var string Version identifier. */
        public readonly string $versionkey,
        /** @var int Modification time of this version. */
        public readonly int $mtime = 0,
        /** @var int Size in bytes. */
        public readonly int $size = 0,
        /** @var string MIME type. */
        public readonly string $mimetype = '',
        /** @var string Optional human readable label. */
        public readonly string $label = '',
    ) {
    }
}
