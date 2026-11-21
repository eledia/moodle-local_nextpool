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

/**
 * Upgrade steps for nextpoolsource_moodle.
 *
 * @package   nextpoolsource_moodle
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the storage location "Moodle files".
 *
 * @param int $oldversion Version before the upgrade.
 * @return bool
 */
function xmldb_nextpoolsource_moodle_upgrade($oldversion) {
    if ($oldversion < 2026111405) {
        // Files stored before bug238 had no owner, so core's H5P player showed them to anybody and installed the
        // libraries in them. They get the guest user as owner, and contents core already deployed from them are
        // removed. The savepoint follows only when both parts are done; a resumed upgrade finishes the job.
        $removed = \local_nextpool\local\h5p_guard::secure_area(
            'nextpoolsource_moodle',
            \nextpoolsource_moodle\source::FILEAREA,
            \context_system::instance()->id
        );
        \local_nextpool\local\h5p_guard::report_removed(
            'nextpoolsource_moodle',
            $removed,
            'files of the Nextpool storage location "Moodle files"',
            'bug238'
        );

        upgrade_plugin_savepoint(true, 2026111405, 'nextpoolsource', 'moodle');
    }

    return true;
}
