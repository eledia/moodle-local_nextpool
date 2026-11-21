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
 * Tab "Statistics" of a catalogue item: anonymous figures from the last calculation (F15, task127).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_statistics;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\item_statistics_page;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

// Like item.php: editors may hold their rights in a course category, so the right is checked in the item's context.
require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/nextpool/statistics.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = item_manager::get($id);
// Whoever may edit or review the item (q112, F15.AC03).
access::require_view($item);

$title = get_string('statistics', 'local_nextpool');
editorial_navbar::add($PAGE, $item, $title);
$PAGE->set_title(get_string(
    'statspagetitle',
    'local_nextpool',
    format_string($item->title, true, ['context' => access::get_context($item)])
));

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
echo area_nav::item($item, area_nav::ITEM_STATISTICS);
// Stored figures only: opening the page computes nothing (FR-F15-06).
$page = new item_statistics_page($item, item_statistics::get($id));
echo $OUTPUT->render_from_template('local_nextpool/item_statistics_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
