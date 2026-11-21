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
 * "My items": the catalogue items the current user is item editor of (F08, task84, adr25).
 *
 * The way in for item editors without editorial rights, linked from the user menu. Everyone may open the page;
 * it lists only their own entries in local_nextpool_item_editor.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\status;
use local_nextpool\output\area_nav;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

require_login();
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
$pageurl = new moodle_url('/local/nextpool/myitems.php');
$title = get_string('myitems', 'local_nextpool');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($SITE->fullname));
$PAGE->navbar->add($title);

$items = item_editor_manager::get_items((int) $USER->id);

echo $OUTPUT->header();
echo area_nav::heading($title);
echo html_writer::tag('p', get_string('myitems_desc', 'local_nextpool'));

if (!$items) {
    echo $OUTPUT->notification(get_string('myitems_none', 'local_nextpool'), \core\output\notification::NOTIFY_INFO, false);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-nextpool-myitems';
    $table->head = [
        get_string('title', 'local_nextpool'),
        get_string('itemtype', 'local_nextpool'),
        get_string('status'),
        get_string('lastmodified'),
        get_string('actions'),
    ];
    foreach ($items as $item) {
        $context = access::get_context($item);
        $itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $item->id]);
        $rowname = format_string($item->title, true, ['context' => $context, 'escape' => false]);
        $actions = [];
        if (access::can_edit($item)) {
            $actions[] = table_actions::icon_link(
                new moodle_url('/local/nextpool/edit.php', ['id' => $item->id]),
                't/edit',
                get_string('edit'),
                $rowname
            );
        }
        $table->data[] = [
            table_actions::row_header(html_writer::link($itemurl, format_string($item->title, true, ['context' => $context]))),
            item_types::is_valid($item->type) ? get_string('type_' . $item->type, 'local_nextpool') : s($item->type),
            status::badge($item->status),
            html_writer::span(
                userdate($item->timemodified, get_string('strftimedatefullshort', 'core_langconfig')),
                '',
                ['title' => userdate($item->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'))]
            ),
            table_actions::group($actions, table_actions::name(get_string('actions'), $rowname)),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
