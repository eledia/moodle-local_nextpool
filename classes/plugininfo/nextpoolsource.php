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

namespace local_nextpool\plugininfo;

/**
 * Plugin info for storage backend subplugins (nextpoolsource).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nextpoolsource extends \core\plugininfo\base {
    /**
     * Backends can be uninstalled when no configured instance uses them.
     *
     * @return bool
     */
    public function is_uninstall_allowed() {
        global $DB;
        return !$DB->record_exists('local_nextpool_source', ['plugin' => $this->name]);
    }

    /**
     * Where backend instances are managed.
     *
     * @return \moodle_url
     */
    public static function get_manage_url() {
        return new \moodle_url('/local/nextpool/sources.php');
    }
}
