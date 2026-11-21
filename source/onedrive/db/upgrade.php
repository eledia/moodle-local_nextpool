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
 * Upgrade steps for nextpoolsource_onedrive.
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove cached bearer tokens written before encrypted token caching (bug139).
 *
 * @param int $oldversion Installed version.
 * @return bool
 */
function xmldb_nextpoolsource_onedrive_upgrade(int $oldversion): bool {
    if ($oldversion < 2026111550) {
        cache::make('nextpoolsource_onedrive', 'tokens')->purge();
        upgrade_plugin_savepoint(true, 2026111550, 'nextpoolsource', 'onedrive');
    }
    return true;
}
