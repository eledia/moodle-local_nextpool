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

/**
 * Sends version snapshots to the editorial team (local_nextpool_pluginfile).
 *
 * Snapshots come from external storage locations and may be HTML, SVG or anything else, so they are always sent
 * as a download and never shown inline in the Moodle origin (bug38). Resolved through \core\di, so tests can
 * see what would be sent.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot_sender {
    /**
     * Send a snapshot as a download.
     *
     * @param \stored_file $file Snapshot.
     * @param array $options Send options from the pluginfile request.
     */
    public function send(\stored_file $file, array $options): void {
        send_stored_file($file, null, 0, true, $options);
    }
}
