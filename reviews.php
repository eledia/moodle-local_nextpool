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
 * "Waiting for review" and "Completed reviews" (F07, task85): what waits for the reviewers, and who reviews it.
 *
 * Linked from the user menu and the tabs of the editorial area. Rows offer "I review" (POST) and "Assign" (own form
 * with the reviewers of the item, {@see access::get_reviewers()}); both are checked on the server with
 * {@see access::can_review()}.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use core_reportbuilder\system_report_factory;
use local_nextpool\form\review_assign_form;
use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\review_queue;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\table_actions;
use local_nextpool\reportbuilder\local\systemreports\review_queue as review_queue_report;
use local_nextpool\reportbuilder\local\systemreports\reviews_done;

require_once(__DIR__ . '/../../config.php');

$view = optional_param('view', '', PARAM_ALPHA) === 'done' ? 'done' : '';
$action = optional_param('action', '', PARAM_ALPHA);

require_login();
if (!review_queue::can_view()) {
    throw new required_capability_exception(context_system::instance(), access::REVIEW, 'nopermissions', '');
}
$pageurl = new moodle_url('/local/nextpool/reviews.php', array_filter(['view' => $view]));
$queueurl = new moodle_url('/local/nextpool/reviews.php');
$title = get_string($view === 'done' ? 'reviewsdone' : 'reviewqueue', 'local_nextpool');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($SITE->fullname));
$PAGE->navbar->add(get_string('pluginname', 'local_nextpool'), new moodle_url('/local/nextpool/index.php'));

if ($action === 'take' || $action === 'assign') {
    $versionid = required_param('versionid', PARAM_INT);
    $version = version_manager::get($versionid);
    $item = item_manager::get((int) $version->itemid);
    if (!access::can_review($item)) {
        throw new required_capability_exception(access::get_context($item), access::REVIEW, 'nopermissions', '');
    }
    $a = (object) [
        'item' => format_string($item->title, true, ['context' => access::get_context($item)]),
        // HTML: both go into notifications and the heading (bug106).
        'version' => s(review_manager::get_version_name($version)),
    ];

    if ($action === 'take') {
        require_sesskey();
        try {
            review_queue::assign($versionid, (int) $USER->id);
            redirect($queueurl, get_string('reviewtaken', 'local_nextpool', $a), null, notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($queueurl, $e->getMessage(), null, notification::NOTIFY_ERROR);
        }
    }

    $reviewers = array_map(static fn(stdClass $user): string => fullname($user), access::get_reviewers($item));
    $assignurl = new moodle_url('/local/nextpool/reviews.php', ['action' => 'assign', 'versionid' => $versionid]);
    $form = new review_assign_form($assignurl, ['versionid' => $versionid, 'reviewers' => $reviewers]);
    $form->set_data(['assignedto' => (int) $version->assignedto]);
    if ($form->is_cancelled()) {
        redirect($queueurl);
    } else if ($data = $form->get_data()) {
        try {
            $assigned = review_queue::assign($versionid, (int) $data->assignedto);
            $a->user = $assigned->assignedto ? s($reviewers[(int) $assigned->assignedto] ?? '-') : '';
            $message = $assigned->assignedto ? get_string('reviewassigned', 'local_nextpool', $a)
                : get_string('reviewunassigned', 'local_nextpool', $a);
            redirect($queueurl, $message, null, notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($queueurl, $e->getMessage(), null, notification::NOTIFY_ERROR);
        }
    }

    $heading = get_string('reviewassignheading', 'local_nextpool', $a);
    $PAGE->set_url($assignurl);
    $PAGE->set_title($heading);
    $PAGE->navbar->add(get_string('reviewqueue', 'local_nextpool'), $queueurl);
    $PAGE->navbar->add(get_string('reviewassign', 'local_nextpool'));
    echo $OUTPUT->header();
    echo area_nav::editorial(area_nav::REVIEWS, true);
    echo area_nav::heading($heading);
    if (!review_queue::is_waiting($version)) {
        echo $OUTPUT->notification(get_string('error_reviewnotwaiting', 'local_nextpool'), notification::NOTIFY_WARNING, false);
    } else {
        echo html_writer::tag('p', get_string('reviewassign_desc', 'local_nextpool'));
        $form->display();
    }
    echo $OUTPUT->footer();
    exit;
}

if ($view === 'done') {
    $PAGE->navbar->add(get_string('reviewqueue', 'local_nextpool'), $queueurl);
}
$PAGE->navbar->add($title);

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::REVIEWS, $view === 'done');
$switch = $view === 'done'
    ? html_writer::link($queueurl, get_string('reviewqueue', 'local_nextpool'), ['class' => 'btn btn-secondary'])
    : html_writer::link(
        new moodle_url('/local/nextpool/reviews.php', ['view' => 'done']),
        get_string('reviewsdone', 'local_nextpool'),
        ['class' => 'btn btn-secondary']
    );
echo area_nav::heading(s($title), $switch);
$description = get_string($view === 'done' ? 'reviewsdone_desc' : 'reviewqueue_desc', 'local_nextpool', reviews_done::DAYS);
echo html_writer::tag('p', $description);

if ($view !== 'done' && ($waitingitems = review_queue::get_waiting_items())) {
    // Items submitted on the item level without a waiting version: the report lists versions only.
    echo html_writer::tag('h3', get_string('reviewqueue_items', 'local_nextpool'), ['class' => 'h5 mt-4']);
    echo html_writer::tag('p', get_string('reviewqueue_items_desc', 'local_nextpool'));
    $itemtable = new html_table();
    $itemtable->attributes['class'] = 'generaltable local-nextpool-table local-nextpool-table--stack';
    $itemlabels = [get_string('reviewitem', 'local_nextpool'), get_string('lastmodified'), get_string('actions')];
    $itemtable->head = $itemlabels;
    foreach ($waitingitems as $waitingitem) {
        $context = access::get_context($waitingitem);
        $itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $waitingitem->id]);
        $rowname = format_string($waitingitem->title, true, ['context' => $context, 'escape' => false]);
        $row = new html_table_row();
        $cells = [
            html_writer::link($itemurl, format_string($waitingitem->title, true, ['context' => $context])),
            userdate($waitingitem->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            table_actions::icon_link($itemurl, 't/preview', get_string('reviewopenitem', 'local_nextpool'), $rowname),
        ];
        foreach ($cells as $index => $content) {
            $cell = new html_table_cell($content);
            $cell->attributes['data-label'] = $itemlabels[$index];
            $row->cells[] = $cell;
        }
        $itemtable->data[] = $row;
    }
    echo html_writer::table($itemtable);
    echo html_writer::tag('h3', get_string('reviewqueue_versions', 'local_nextpool'), ['class' => 'h5 mt-4']);
}

$report = system_report_factory::create(
    $view === 'done' ? reviews_done::class : review_queue_report::class,
    context_system::instance(),
    'local_nextpool',
    $view === 'done' ? 'reviewsdone' : 'reviewqueue'
);
echo $report->output();
echo $OUTPUT->footer();
