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
 * Admin settings for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// One entry into the catalogue area (task64). The area switches between its pages with its own tabs, and
// every page checks its own capabilities, so editors without site configuration rights find it too.
$ADMIN->add('localplugins', new admin_externalpage(
    'local_nextpool_catalogue',
    new lang_string('pluginname', 'local_nextpool'),
    new moodle_url('/local/nextpool/index.php'),
    [
        'local/nextpool:manageitems',
        'local/nextpool:reviewversions',
        'local/nextpool:managecategories',
        'local/nextpool:managesources',
        'moodle/site:config',
    ]
));
