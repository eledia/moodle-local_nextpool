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
 * Add an item editor to a catalogue item (F08, task84, q68), linked from the section "Item editors" of the item page.
 *
 * Only the editorial team of the item's context ({@see access::can_manage()}). Any active account of the site can be
 * chosen; removing happens on the item page.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\item_editor_form;
use local_nextpool\local\access;
use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

// Not admin_externalpage_setup(): editors may hold their rights in a course category only.
require_login();
$pageurl = new moodle_url('/local/nextpool/editors.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = item_manager::get($id);
access::require_manage($item);
$itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $id]);

$form = new item_editor_form($pageurl, ['id' => $id]);
if ($form->is_cancelled()) {
    redirect($itemurl);
} else if ($data = $form->get_data()) {
    try {
        $added = item_editor_manager::add($id, (int) $data->editoruserid);
        redirect(
            $itemurl,
            get_string(
                $added ? 'itemeditoradded' : 'itemeditorexists',
                'local_nextpool',
                s(fullname(core_user::get_user((int) $data->editoruserid)))
            ),
            null,
            $added ? notification::NOTIFY_SUCCESS : notification::NOTIFY_INFO
        );
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

$heading = get_string('itemeditoradd', 'local_nextpool');
editorial_navbar::add($PAGE, $item, $heading);
$PAGE->set_title($heading);

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
echo $OUTPUT->heading($heading);
echo html_writer::tag('p', get_string('itemeditoradd_desc', 'local_nextpool', format_string(
    $item->title,
    true,
    ['context' => access::get_context($item)]
)));
$form->display();
echo $OUTPUT->footer();
