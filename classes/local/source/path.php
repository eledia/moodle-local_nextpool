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
 * Path rules shared by all storage backends.
 *
 * Every path handed to a backend is relative to that backend's base folder.
 * Normalising rejects anything that could leave the base folder (dot segments,
 * backslashes, control characters), so a backend can only ever address files
 * below the folder an administrator configured (fixes bug01).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class path {
    /**
     * Normalise a relative path: trim, collapse slashes, reject unsafe segments.
     *
     * @param string $path Path as entered or stored, e.g. "/Docs//handout.pdf ".
     * @return string Normalised path without leading or trailing slash, e.g. "Docs/handout.pdf"; '' for the root.
     * @throws source_exception If the path contains dot segments, backslashes or control characters.
     */
    public static function normalise(string $path): string {
        $path = trim($path);
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
            throw new source_exception('error_invalidpath');
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..' || trim($segment) === '') {
                throw new source_exception('error_invalidpath');
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    /**
     * Join two already normalised paths.
     *
     * @param string $base Normalised base path ('' for the root).
     * @param string $relative Normalised relative path ('' for the base itself).
     * @return string
     */
    public static function join(string $base, string $relative): string {
        if ($base === '') {
            return $relative;
        }
        if ($relative === '') {
            return $base;
        }
        return $base . '/' . $relative;
    }

    /**
     * Strip a base folder from a full path.
     *
     * @param string $base Normalised base path.
     * @param string $full Normalised full path.
     * @return string|null Path relative to the base, or null if $full is not inside $base.
     */
    public static function relative_to(string $base, string $full): ?string {
        if ($base === '') {
            return $full;
        }
        if ($full === $base) {
            return '';
        }
        if (str_starts_with($full, $base . '/')) {
            return substr($full, strlen($base) + 1);
        }
        return null;
    }
}
