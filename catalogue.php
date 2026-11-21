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
 * Browse the catalogue from a course and place items (F05, F10).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\catalogue;
use local_nextpool\local\category_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\catalogue_page;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$categoryid = optional_param('category', 0, PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);
$tag = optional_param('tag', '', PARAM_TAG);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 24;
if (!item_types::is_valid($type)) {
    $type = '';
}
// Coming from the activity chooser: the chooser appends sectionid (and the legacy section number).
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$section = optional_param('section', -1, PARAM_INT);

$course = get_course($courseid);
if ($sectionid) {
    $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $sectionid, 'course' => $courseid]);
    $section = $sectionnum === false ? -1 : (int) $sectionnum;
}
$section = $section >= 0 ? $section : null;
$urlparams = ['courseid' => $courseid] + array_filter([
    'search' => $search,
    'category' => $categoryid,
    'type' => $type,
    'tag' => $tag,
]);
if ($section !== null) {
    $urlparams['section'] = $section;
}
$PAGE->set_url(new moodle_url('/local/nextpool/catalogue.php', $urlparams));
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/nextpool:viewcatalogue', $context);
$canplace = has_capability('moodle/course:manageactivities', $context);

$total = catalogue::count_items($courseid, $search, $categoryid, $type, $tag);
$items = catalogue::get_items($courseid, $search, $categoryid, $type, $tag, $page * $perpage, $perpage);
$placedcounts = [];
foreach (placement_manager::get_for_course($courseid) as $placement) {
    $placedcounts[(int) $placement->itemid] = ($placedcounts[(int) $placement->itemid] ?? 0) + 1;
}

$title = get_string('coursecatalogue', 'local_nextpool');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add($title);

echo $OUTPUT->header();
echo area_nav::course($courseid, area_nav::CATALOGUE, count(placement_manager::get_for_course($courseid)));
echo area_nav::heading($title);
if (
    has_capability('moodle/course:manageactivities', $context)
        && class_exists(\mod_nextpool\local\upload::class)
        && !\mod_nextpool\local\upload::is_available()
) {
    echo $OUTPUT->render_from_template('local_nextpool/hint', [
        'text' => get_string('uploadteachernotice', 'local_nextpool'),
        'icon' => $OUTPUT->pix_icon('i/info', ''),
    ]);
}

echo html_writer::tag('p', get_string('coursecatalogue_desc', 'local_nextpool') . ' ' .
    $OUTPUT->help_icon('coursecatalogue', 'local_nextpool'));

// Filter bar (GET, no state change).
$categorymenu = [0 => get_string('allcategories', 'local_nextpool')] + category_manager::get_menu();
$typemenu = ['' => get_string('alltypes', 'local_nextpool')] + item_types::get_menu();
$filter = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/nextpool/catalogue.php'))->out(false),
    'class' => 'local-nextpool-filter d-flex flex-wrap align-items-end gap-3 p-3 mb-3',
    'role' => 'search',
    'data-tour' => 'cataloguefilters',
]);
$filter .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
if ($section !== null) {
    $filter .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => $section]);
}
$filter .= html_writer::div(
    html_writer::label(get_string('search'), 'local_nextpool_search', true, ['class' => 'form-label d-block']) .
    html_writer::empty_tag('input', [
        'type' => 'search',
        'name' => 'search',
        'id' => 'local_nextpool_search',
        'value' => $search,
        'class' => 'form-control',
    ]),
    'local-nextpool-filter__field'
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
        $categoryid,
        false,
        ['id' => 'local_nextpool_category', 'class' => 'w-100']
    ),
    'local-nextpool-filter__field'
);
$filter .= html_writer::div(
    html_writer::label(get_string('itemtype', 'local_nextpool'), 'local_nextpool_type', true, ['class' => 'form-label d-block']) .
    html_writer::select($typemenu, 'type', $type, false, ['id' => 'local_nextpool_type', 'class' => 'w-100']),
    'local-nextpool-filter__field'
);
$tagmenu = catalogue::get_tags($courseid);
if ($tagmenu) {
    $filter .= html_writer::div(
        html_writer::label(get_string('tags'), 'local_nextpool_tag', true, ['class' => 'form-label d-block']) .
        html_writer::select(
            ['' => get_string('alltags', 'local_nextpool')] + array_map('s', $tagmenu),
            'tag',
            \core_text::strtolower($tag),
            false,
            ['id' => 'local_nextpool_tag', 'class' => 'w-100']
        ),
        'local-nextpool-filter__field'
    );
}
$filter .= html_writer::div(html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary']));
$filter .= html_writer::end_tag('form');
echo $filter;

// Hints when items do not appear (task104): for editors, items that are not published yet; for everyone, results that
// a category filter hides (t104-a).
$hints = [];
$editctx = array_intersect(catalogue::get_visibility_contextids($courseid), access::get_editorial_contextids());
if ($editctx) {
    // Editors of the categories above the course also maintain the items uploaded from it (TD-402).
    $editctx[] = $context->id;
    $unpublished = catalogue::count_unpublished($courseid, $editctx, $search, $categoryid, $type, $tag);
    if ($unpublished) {
        $hints[] = [
            'text' => get_string($unpublished === 1 ? 'cataloguehiddenone' : 'cataloguehiddenmany', 'local_nextpool', $unpublished),
            'linkurl' => (new moodle_url('/local/nextpool/items.php', array_filter(['search' => $search])))->out(false),
            'linktext' => get_string('cataloguehiddenlink', 'local_nextpool'),
        ];
    }
}
if ($categoryid && !$total && catalogue::count_items($courseid, $search, 0, $type, $tag)) {
    $hints[] = [
        'text' => get_string('cataloguecategoryelsewhere', 'local_nextpool'),
        'linkurl' => (new moodle_url($PAGE->url, ['category' => 0]))->out(false),
        'linktext' => get_string('allcategories', 'local_nextpool'),
    ];
}
// Static info notices (MDS Alert, info): the link is the action; no role="alert", they are not announced on load.
foreach ($hints as $hint) {
    echo $OUTPUT->render_from_template('local_nextpool/hint', $hint + ['icon' => $OUTPUT->pix_icon('i/info', '')]);
}

$filtered = $search !== '' || $categoryid || $type !== '' || $tag !== '';
$resetparams = ['courseid' => $courseid] + ($section !== null ? ['section' => $section] : []);
$reseturl = new moodle_url('/local/nextpool/catalogue.php', $resetparams);
$cataloguepage = new catalogue_page($courseid, $items, $placedcounts, $canplace, $section, $filtered, $reseturl);
echo $OUTPUT->render_from_template('local_nextpool/catalogue_page', $cataloguepage->export_for_template($OUTPUT));
// Paging (bug68); the bar keeps the filter of the page URL.
echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
echo $OUTPUT->footer();
