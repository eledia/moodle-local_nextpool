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
 * Entry into the catalogue area: opens the first page the user may see (task64).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\output\area_nav;

require_once(__DIR__ . '/../../config.php');

$PAGE->set_url(new moodle_url('/local/nextpool/index.php'));
$PAGE->set_context(context_system::instance());
require_login();
$tabs = area_nav::get_editorial_tabs();
if (!$tabs && \local_nextpool\local\item_editor_manager::has_items((int) $USER->id)) {
    // Item editors without editorial rights work on their own items only (F08, task84).
    redirect(new moodle_url('/local/nextpool/myitems.php'));
}
if (!$tabs) {
    // Same refusal as the item list for users without any catalogue rights.
    access::require_editorial();
}
redirect(reset($tabs));
