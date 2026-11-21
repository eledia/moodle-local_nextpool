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
 * Manage the custom fields of catalogue items (F01).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_customfield\output\management;
use local_nextpool\customfield\item_handler;
use local_nextpool\output\area_nav;

require_once(__DIR__ . '/../../config.php');

require_login();
$url = new moodle_url('/local/nextpool/customfields.php');
area_nav::setup_system_page($PAGE, $url, area_nav::CUSTOMFIELDS, get_string('customfields', 'local_nextpool'));

$output = $PAGE->get_renderer('core_customfield');
$handler = item_handler::instance();

echo $output->header(),
    area_nav::editorial(area_nav::CUSTOMFIELDS),
    area_nav::heading(get_string('customfields', 'local_nextpool')),
    $output->render(new management($handler)),
    $output->footer();
