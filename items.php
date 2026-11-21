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
 * Editorial list of catalogue items (F01).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\category_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_viewer;
use local_nextpool\local\status;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\item_list;
use local_nextpool\output\next_step;
use local_nextpool\output\table_actions;
use local_nextpool\output\type_icon;

require_once(__DIR__ . '/../../config.php');

$filterstatus = optional_param('status', '', PARAM_ALPHA);
$filtercategory = optional_param('category', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$filterorigin = optional_param('origin', '', PARAM_ALPHA) === item_manager::ORIGIN_COURSE ? item_manager::ORIGIN_COURSE : '';
$filterunplaced = optional_param('unplaced', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
// Sort key and direction against the whitelist, unknown values fall back to the default (task168).
[$sort, $dir] = item_manager::normalise_sort(optional_param('sort', '', PARAM_ALPHA), optional_param('dir', '', PARAM_ALPHA));
$defaultsort = item_manager::normalise_sort('', '');
$sortparams = [$sort, $dir] === $defaultsort ? [] : ['sort' => $sort, 'dir' => $dir];
$perpage = 50;

if ($filterstatus !== '' && !in_array($filterstatus, status::all(true), true)) {
    $filterstatus = '';
}

// Not admin_externalpage_setup(): it checks the capabilities in the system context only, while editors may hold
// them in a course category.
require_login();
access::require_editorial();
$baseurl = new moodle_url('/local/nextpool/items.php', array_filter([
    'status' => $filterstatus,
    'category' => $filtercategory,
    'search' => $search,
    'origin' => $filterorigin,
    'unplaced' => (int) $filterunplaced,
]) + $sortparams);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($baseurl, ['page' => $page]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('items', 'local_nextpool'));
$PAGE->set_heading(format_string($SITE->fullname));
editorial_navbar::add($PAGE);

// Only items in contexts where the user holds an editorial capability.
$contextids = access::get_editorial_contextids();
$filters = ['status' => $filterstatus, 'categoryid' => $filtercategory, 'search' => $search, 'origin' => $filterorigin,
    'unplaced' => $filterunplaced];
$total = item_manager::count_search($contextids, $filters);
$items = item_manager::search($contextids, $filters, $page * $perpage, $perpage, $sort, $dir);

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS);
$addbutton = '';
if (access::can_create()) {
    $addbutton = $OUTPUT->single_button(
        new moodle_url('/local/nextpool/edit.php'),
        get_string('additem', 'local_nextpool'),
        'get',
        ['type' => single_button::BUTTON_PRIMARY]
    );
}
echo area_nav::heading(get_string('items', 'local_nextpool'), $addbutton);

// Filter bar (GET, no state change).
$statusmenu = ['' => get_string('allstatuses', 'local_nextpool')];
foreach (status::all(true) as $value) {
    $statusmenu[$value] = status::get_name($value);
}
$categorymenu = [0 => get_string('allcategories', 'local_nextpool'), -1 => get_string('nocategory', 'local_nextpool')]
    + category_manager::get_menu();
$filter = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/nextpool/items.php'))->out(false),
    'class' => 'local-nextpool-filter d-flex flex-wrap align-items-end gap-3 p-3 mb-3',
    'role' => 'search',
    'data-tour' => 'editorialfilters',
]);
// Filtering keeps the sorting (task168).
foreach ($sortparams as $name => $value) {
    $filter .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
$filter .= html_writer::div(
    html_writer::label(get_string('search'), 'local_nextpool_search', true, ['class' => 'form-label d-block']) .
    html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'id' => 'local_nextpool_search',
        'value' => $search, 'class' => 'form-control']),
    'mw-100'
);
$filter .= html_writer::div(
    html_writer::label(get_string('status'), 'local_nextpool_status', true, ['class' => 'form-label d-block']) .
    html_writer::select(
        $statusmenu,
        'status',
        $filterstatus,
        false,
        ['id' => 'local_nextpool_status', 'class' => 'form-select mw-100']
    ),
    'mw-100'
);
$filter .= html_writer::div(
    html_writer::label(
        get_string('category', 'local_nextpool'),
        'local_nextpool_category',
        true,
        ['class' => 'form-label d-block']
    ) .
    html_writer::select(
        $categorymenu,
        'category',
        $filtercategory,
        false,
        ['id' => 'local_nextpool_category', 'class' => 'form-select mw-100']
    ),
    'mw-100'
);
$filter .= html_writer::div(
    html_writer::label(
        get_string('filter_origin', 'local_nextpool'),
        'local_nextpool_origin',
        true,
        ['class' => 'form-label d-block']
    ) .
    html_writer::select(
        ['' => get_string('filter_origin_all', 'local_nextpool'),
            item_manager::ORIGIN_COURSE => get_string('filter_origin_course', 'local_nextpool')],
        'origin',
        $filterorigin,
        false,
        ['id' => 'local_nextpool_origin', 'class' => 'form-select mw-100']
    ),
    'mw-100'
);
$filter .= html_writer::div(
    html_writer::checkbox('unplaced', 1, $filterunplaced, get_string('filter_unplaced', 'local_nextpool'), [
        'id' => 'local_nextpool_unplaced',
        'class' => 'form-check-input',
    ], ['class' => 'form-check-label']),
    'form-check mb-2'
);
$filter .= html_writer::div(html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary']));
$filter .= html_writer::end_tag('form');
echo $filter;

if (!$items) {
    // Say whether the filter or the catalogue is empty, and how to go on (bug77).
    if ($filterstatus !== '' || $filtercategory || $search !== '' || $filterorigin !== '' || $filterunplaced) {
        echo $OUTPUT->notification(get_string('noitemsmatch', 'local_nextpool'), \core\output\notification::NOTIFY_INFO, false);
        echo html_writer::tag('p', html_writer::link(
            new moodle_url('/local/nextpool/items.php', $sortparams),
            get_string('resetfilter', 'local_nextpool')
        ));
    } else {
        echo $OUTPUT->notification(get_string('noitems', 'local_nextpool'), \core\output\notification::NOTIFY_INFO, false);
    }
    echo $OUTPUT->footer();
    die();
}

$categorynames = category_manager::get_menu();
// Courses the items were uploaded from, with one query (task166).
$origincourseids = array_filter(array_map(static fn($item) => (int) $item->origincourseid, $items));
$origincourses = $origincourseids ? $DB->get_records_list('course', 'id', array_unique($origincourseids)) : [];

$table = new html_table();
$table->attributes['data-tour'] = 'editorialtable';
// Below 768 px each row is stacked with the column name before each value, so the list never needs horizontal
// scrolling (WCAG 1.4.10, pipeline #66719).
$table->attributes['class'] = 'generaltable local-nextpool-table local-nextpool-table--stack';
// Explicit roles retain the native table structure when CSS stacks the rows (bug198).
$table->attributes['role'] = 'table';
$table->colclasses = [6 => 'local-nextpool-actionscell'];
// The content type is an icon before the title (task166). Plain column names for the stacked rows.
$labels = [
    get_string('title', 'local_nextpool'),
    get_string('status'),
    get_string('currentversion', 'local_nextpool'),
    get_string('category', 'local_nextpool'),
    get_string('source', 'local_nextpool'),
    get_string('lastmodified'),
    get_string('actions'),
];
// Title, status, category and last modified sort by a click on the heading (task168).
$sortcolumns = [
    0 => item_manager::SORT_TITLE,
    1 => item_manager::SORT_STATUS,
    3 => item_manager::SORT_CATEGORY,
    5 => item_manager::SORT_TIMEMODIFIED,
];
$table->head = [];
foreach ($labels as $index => $label) {
    $header = isset($sortcolumns[$index])
        ? item_list::sort_header($label, $sortcolumns[$index], $sort, $dir, $baseurl) : new html_table_cell($label);
    $header->header = true;
    $header->scope = 'col';
    $header->attributes['role'] = 'columnheader';
    $table->head[$index] = $header;
}
foreach ($items as $item) {
    if (!access::can_view($item)) {
        continue;
    }
    $statuscell = status::badge($item->status);
    if ($item->reviewcount > 0) {
        $statuscell .= ' ' . status::review_pending_badge((int) $item->reviewcount);
    }
    if (!empty($item->timesourcemissing)) {
        $statuscell .= ' ' . status::text_badge(get_string('sourcemissing_badge', 'local_nextpool'), 'bg-warning text-dark');
    }
    if (!empty($item->origincourseid) && empty($item->placementcount)) {
        $statuscell .= ' ' . status::text_badge(get_string('unplaced_badge', 'local_nextpool'), 'bg-light text-dark border');
    }
    $title = format_string($item->title, true, ['context' => access::get_context($item)]);
    // Short pill with the course link beside the title (M06.AC04, task166).
    $origin = item_list::origin_pill($item, $origincourses);
    $rowname = format_string($item->title, true, ['context' => access::get_context($item), 'escape' => false]);
    $viewurl = item_viewer::get_url($item);
    // The next due step as an icon (task102): the latest version while draft or in review, else the item status.
    $latest = $item->latestversionid ? (object) [
        'id' => (int) $item->latestversionid,
        'itemid' => (int) $item->id,
        'versionnr' => (int) $item->latestversionnr,
        'status' => $item->latestversionstatus,
    ] : null;
    $next = next_step::for_item($item, $latest, $PAGE->url);
    $more = [];
    if (access::can_change_status($item, $item->status, status::WITHDRAWN)) {
        // Withdrawing asks first on the item page and comes back here (PO decision 2).
        $more[] = new action_menu_link_secondary(
            new moodle_url('/local/nextpool/item.php', ['id' => $item->id, 'action' => 'itemstatus', 'to' => status::WITHDRAWN,
                'returnurl' => $PAGE->url->out_as_local_url(false)]),
            new pix_icon('t/hide', ''),
            get_string('itemtransition_withdrawn', 'local_nextpool')
        );
    }
    $actions = [
        $viewurl ? table_actions::icon_link($viewurl, 'i/externallink', get_string('view'), $rowname, ['target' => '_blank'])
            : table_actions::spacer(),
        access::can_edit($item) ? table_actions::icon_link(
            new moodle_url('/local/nextpool/edit.php', ['id' => $item->id]),
            't/edit',
            get_string('edit'),
            $rowname
        ) : table_actions::spacer(),
        $next ? $next->icon($rowname) : table_actions::spacer(),
        // A single action is an icon link to its confirmation, a menu only from two on (task166).
        table_actions::more($more, $rowname),
    ];
    $cells = [
        html_writer::div(
            type_icon::render($item->type) .
            html_writer::link(new moodle_url('/local/nextpool/item.php', ['id' => $item->id]), $title) .
            ($origin !== '' ? ' ' . $origin : ''),
            'local-nextpool-titlecell d-flex flex-wrap align-items-center'
        ),
        $statuscell,
        $item->currentversionnr ? get_string('versionnr', 'local_nextpool', (int) $item->currentversionnr) : '-',
        // The one catalogue category as pill (task166).
        $item->categoryid && isset($categorynames[(int) $item->categoryid])
            ? item_list::category_pill($categorynames[(int) $item->categoryid]) : '-',
        format_string((string) $item->sourcename),
        // Short date, the time in the tooltip (task105).
        html_writer::span(
            userdate($item->timemodified, get_string('strftimedatefullshort', 'core_langconfig')),
            '',
            ['title' => userdate($item->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'))]
        ),
        table_actions::group($actions, table_actions::name(get_string('actions'), $rowname)),
    ];
    $row = new html_table_row();
    $row->attributes['role'] = 'row';
    foreach ($cells as $index => $content) {
        $cell = $index === 0 ? table_actions::row_header($content) : new html_table_cell($content);
        $cell->attributes['role'] = $index === 0 ? 'rowheader' : 'cell';
        $cell->attributes['data-label'] = $labels[$index];
        if ($index === 6) {
            $cell->attributes['data-tour'] = 'editorialactions';
        }
        $row->cells[] = $cell;
    }
    $table->data[] = $row;
}
// Below 768 px the heading row is hidden, so the sorting is a menu there (task168).
echo item_list::sort_menu($sort, $dir, $baseurl);
echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
echo $OUTPUT->footer();
