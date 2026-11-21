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

use local_nextpool\local\source\entry;

/**
 * Avoid downloading a revision that already failed package validation on every polling run.
 *
 * Manual imports always retry. The bounded cache also expires so corrected validators or settings
 * can retry a revision whose backend marker did not change.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rejected_source_revision {
    /**
     * Record only deterministic validation failures, after the source download succeeded.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $latest Latest version.
     * @param entry $entry Downloaded source entry.
     */
    public static function remember(\stdClass $item, \stdClass $latest, entry $entry): void {
        if ($entry->revision !== '') {
            \cache::make('local_nextpool', 'rejectedrevision')->set(self::key($item, $latest, $entry), true);
        }
    }

    /**
     * Whether polling already rejected this exact item, baseline and backend revision.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $latest Latest version.
     * @param entry $entry Source entry to import.
     * @return bool
     */
    public static function contains(\stdClass $item, \stdClass $latest, entry $entry): bool {
        return $entry->revision !== ''
            && \cache::make('local_nextpool', 'rejectedrevision')->get(self::key($item, $latest, $entry)) === true;
    }

    /**
     * An opaque key stores no source paths, account information or file content in the cache.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $latest Latest version.
     * @param entry $entry Source entry.
     * @return string
     */
    private static function key(\stdClass $item, \stdClass $latest, entry $entry): string {
        return hash('sha256', json_encode([
            (int) $item->id, (int) $item->sourceid, $item->type, (int) $item->timemodified,
            (int) $latest->id, $entry->path, $entry->externalid, $entry->revision,
        ], JSON_THROW_ON_ERROR));
    }
}
