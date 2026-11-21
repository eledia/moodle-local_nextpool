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
 * "My proposals": the user's own proposals with status and reason, change and cancel (F07, task82).
 *
 * Reached from the course catalogue; lists the proposals of the user from all courses.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\local\access;
use local_nextpool\local\catalogue;
use local_nextpool\local\placement_manager;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
$pageurl = new moodle_url('/local/nextpool/myproposals.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
require_login($course);
$context = context_course::instance($courseid);
$incourse = $courseid !== SITEID;
if ($incourse) {
    require_capability('local/nextpool:viewcatalogue', $context);
}

$title = get_string('myproposals', 'local_nextpool');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->set_pagelayout($incourse ? 'incourse' : 'standard');
if ($incourse) {
    $PAGE->navbar->add(
        get_string('coursecatalogue', 'local_nextpool'),
        new moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid])
    );
}
$PAGE->navbar->add($title, $pageurl);

if ($action === 'cancel') {
    // Cancel an own proposal before the decision (F07.AC11), after a confirmation.
    $version = version_manager::get(required_param('versionid', PARAM_INT));
    if (!proposal_manager::is_own($version)) {
        throw new moodle_exception('error_notownproposal', 'local_nextpool');
    }
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        try {
            proposal_manager::cancel((int) $version->id);
            redirect($pageurl, get_string('proposalcancelled', 'local_nextpool'), null, notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($pageurl, $e->getMessage(), null, notification::NOTIFY_ERROR);
        }
    }
    $heading = get_string('proposalcancel', 'local_nextpool');
    $PAGE->navbar->add($heading);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('proposalcancelconfirm', 'local_nextpool', s(review_manager::get_version_name($version))),
        new single_button(
            new moodle_url($pageurl, ['action' => 'cancel', 'versionid' => $version->id, 'confirm' => 1, 'sesskey' => sesskey()]),
            $heading,
            'post',
            single_button::BUTTON_PRIMARY
        ),
        $pageurl
    );
    echo $OUTPUT->footer();
    die();
}

$proposals = proposal_manager::get_for_user((int) $USER->id);

echo $OUTPUT->header();
if ($incourse) {
    echo area_nav::course($courseid, area_nav::MYPROPOSALS, count(placement_manager::get_for_course($courseid)));
}
echo area_nav::heading($title);
echo html_writer::tag('p', get_string('myproposals_desc', 'local_nextpool'), ['class' => 'text-muted']);

if (!$proposals) {
    echo $OUTPUT->notification(get_string('myproposals_none', 'local_nextpool'), notification::NOTIFY_INFO, false);
    echo $OUTPUT->footer();
    die();
}

$short = get_string('strftimedatefullshort', 'core_langconfig');
$table = new html_table();
$table->caption = $title;
$table->captionhide = true;
$table->head = [
    get_string('catalogueitem', 'local_nextpool'),
    get_string('myproposals_proposal', 'local_nextpool'),
    get_string('status'),
    html_writer::span(get_string('actions'), 'visually-hidden'),
];
$table->attributes['class'] = 'generaltable local-nextpool-myproposals';
// Reasons in one query; the item fields come with the proposals (bug111).
$reasons = proposal_manager::get_last_reasons(array_keys($proposals));
foreach ($proposals as $proposal) {
    $itemrecord = (object) [
        'id' => (int) $proposal->itemid,
        'contextid' => (int) $proposal->itemcontextid,
        'status' => (string) $proposal->itemstatus,
    ];
    $itemcontext = access::get_context($itemrecord);
    $itemtitle = format_string($proposal->itemtitle, true, ['context' => $itemcontext]);
    if ($incourse && catalogue::is_available_in_course($itemrecord, $courseid)) {
        $itemtitle = html_writer::link(
            new moodle_url('/local/nextpool/feedback.php', ['itemid' => $proposal->itemid, 'courseid' => $courseid]),
            $itemtitle
        );
    }
    $name = review_manager::get_version_name($proposal, $USER);
    $reviewurl = new moodle_url('/local/nextpool/review.php', ['versionid' => $proposal->id]);

    $statuscell = status::badge($proposal->status);
    $reason = $reasons[(int) $proposal->id] ?? null;
    $decided = in_array($proposal->status, [status::DRAFT, status::REJECTED], true) && $reason !== null;
    if ($decided) {
        $statuscell .= html_writer::div(
            get_string('myproposals_reason', 'local_nextpool', format_text($reason->reason, (int) $reason->reasonformat, [
                'context' => $itemcontext,
                'para' => false,
            ])),
            'small mt-1'
        );
    }

    $actions = [table_actions::icon_link($reviewurl, 't/preview', get_string('myproposals_view', 'local_nextpool'), $name)];
    if ($proposal->status === status::DRAFT) {
        $actions[] = table_actions::icon_link(
            new moodle_url('/local/nextpool/propose.php', ['versionid' => $proposal->id]),
            't/edit',
            get_string('proposalchange', 'local_nextpool'),
            $name
        );
    }
    if (in_array($proposal->status, [status::DRAFT, status::REVIEW], true)) {
        $actions[] = table_actions::icon_link(
            new moodle_url($pageurl, ['action' => 'cancel', 'versionid' => $proposal->id]),
            't/hide',
            get_string('proposalcancel', 'local_nextpool'),
            $name
        );
    }

    $table->data[] = [
        table_actions::row_header($itemtitle),
        html_writer::link($reviewurl, s($name)) . html_writer::div(get_string(
            'myproposals_submitted',
            'local_nextpool',
            userdate((int) ($proposal->timesubmitted ?: $proposal->timecreated), $short)
        ), 'small text-muted'),
        $statuscell,
        table_actions::group($actions, table_actions::name(get_string('actions'), $name)),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
