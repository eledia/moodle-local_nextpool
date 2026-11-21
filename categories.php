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
 * Manage the catalogue category tree (F10).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\form\category_form;
use local_nextpool\local\category_manager;
use local_nextpool\local\item_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$parent = optional_param('parent', 0, PARAM_INT);

require_login();
$baseurl = new moodle_url('/local/nextpool/categories.php');
area_nav::setup_system_page($PAGE, $baseurl, area_nav::CATEGORIES, get_string('categories', 'local_nextpool'));

if ($action === 'add' || $action === 'edit') {
    $form = new category_form(new moodle_url($baseurl, ['action' => $action, 'id' => $id]), ['id' => $id]);
    if ($action === 'edit') {
        $category = category_manager::get($id);
        $form->set_data(['id' => $id, 'name' => $category->name, 'parent' => $category->parent]);
    } else {
        $form->set_data(['parent' => $parent]);
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        if ($action === 'edit') {
            category_manager::update($id, $data->name, (int) $data->parent);
        } else {
            category_manager::create($data->name, (int) $data->parent);
        }
        redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo area_nav::editorial(area_nav::CATEGORIES, true);
    echo $OUTPUT->heading(get_string($action === 'edit' ? 'editcategory' : 'addcategory', 'local_nextpool'));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

if ($action !== '' && $id) {
    // Changes come as POST from the buttons in the table (bug66). Only the confirmation page for
    // deleting is opened by a link from the row menu; deleting itself is a POST from there.
    $isconfirmpage = $action === 'delete' && !optional_param('confirm', 0, PARAM_BOOL);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isconfirmpage) {
        redirect($baseurl);
    }
    if (!$isconfirmpage) {
        require_sesskey();
    }
    switch ($action) {
        case 'up':
        case 'down':
            category_manager::move($id, $action === 'up');
            redirect(
                $baseurl,
                get_string('categorymoved', 'local_nextpool', format_string(category_manager::get($id)->name)),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
        case 'delete':
            $category = category_manager::get($id);
            if (!optional_param('confirm', 0, PARAM_BOOL)) {
                echo $OUTPUT->header();
                echo area_nav::editorial(area_nav::CATEGORIES, true);
                echo $OUTPUT->confirm(
                    get_string('deletecategoryconfirm', 'local_nextpool', format_string($category->name)),
                    new single_button(
                        new moodle_url($baseurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
                        get_string('delete'),
                        'post',
                        single_button::BUTTON_DANGER
                    ),
                    $baseurl
                );
                echo $OUTPUT->footer();
                die();
            }
            category_manager::delete($id);
            redirect($baseurl, get_string('categorydeleted', 'local_nextpool'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::CATEGORIES);
echo area_nav::heading(
    get_string('categories', 'local_nextpool'),
    $OUTPUT->single_button(new moodle_url($baseurl, ['action' => 'add']), get_string('addcategory', 'local_nextpool'), 'get', [
        'type' => single_button::BUTTON_PRIMARY,
    ])
);
echo html_writer::tag('p', get_string('categories_desc', 'local_nextpool'));

$tree = category_manager::get_tree();
if ($tree) {
    $itemcounts = $DB->get_records_sql_menu(
        'SELECT categoryid, COUNT(1) FROM {' . item_manager::TABLE . '} WHERE categoryid IS NOT NULL GROUP BY categoryid'
    );
    $names = category_manager::get_menu();
    $siblings = [];
    foreach ($tree as $category) {
        $siblings[(int) $category->parent][] = (int) $category->id;
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-nextpool-table';
    $table->colclasses = ['', 'text-center local-nextpool-countcell', 'local-nextpool-actionscell'];
    $table->head = [
        get_string('categoryname', 'local_nextpool'),
        get_string('items', 'local_nextpool'),
        get_string('actions'),
    ];
    foreach ($tree as $category) {
        $categoryid = (int) $category->id;
        $group = $siblings[(int) $category->parent];
        $rowname = format_string($category->name, true, ['escape' => false]);
        $actionurl = static fn(string $action): moodle_url => new moodle_url($baseurl, ['action' => $action, 'id' => $categoryid]);

        $actions = [
            table_actions::icon_link(
                new moodle_url($baseurl, ['action' => 'edit', 'id' => $categoryid]),
                't/edit',
                get_string('edit'),
                $rowname
            ),
            // Its own icon instead of a menu entry (task167).
            table_actions::icon_link(
                new moodle_url($baseurl, ['action' => 'add', 'parent' => $categoryid]),
                't/add',
                get_string('addsubcategory', 'local_nextpool'),
                $rowname
            ),
            $group[0] !== $categoryid ? table_actions::icon_button($actionurl('up'), 't/up', get_string('moveup'), $rowname)
                : table_actions::spacer(),
            end($group) !== $categoryid ? table_actions::icon_button($actionurl('down'), 't/down', get_string('movedown'), $rowname)
                : table_actions::spacer(),
        ];
        $more = [];
        if (category_manager::can_delete($categoryid)) {
            $more[] = new action_menu_link_secondary(
                $actionurl('delete'),
                new pix_icon('t/delete', ''),
                get_string('delete'),
                ['class' => 'text-danger']
            );
        }
        // A single action is an icon link to its confirmation, a menu only from two on (task167).
        $actions[] = table_actions::more($more, $rowname) ?: table_actions::spacer();
        // The count leads to the item list filtered by the category (task167).
        $count = (int) ($itemcounts[$categoryid] ?? 0);
        $countcell = $count ? html_writer::link(
            new moodle_url('/local/nextpool/items.php', ['category' => $categoryid]),
            html_writer::span($count, '', ['aria-hidden' => 'true']) . html_writer::span(
                get_string('categoryitemslink', 'local_nextpool', (object) ['name' => $names[$categoryid], 'count' => $count]),
                'visually-hidden'
            )
        ) : 0;
        $table->data[] = [
            table_actions::row_header($names[$categoryid]),
            $countcell,
            table_actions::group($actions, table_actions::name(get_string('actions'), $rowname)),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nocategories', 'local_nextpool'), \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->footer();
