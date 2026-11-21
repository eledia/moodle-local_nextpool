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
 * Catalogue content placed in a course: versions, update policy, updates (F05, F06).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
$pageurl = new moodle_url('/local/nextpool/placements.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/nextpool:viewcatalogue', $context);
$canmanage = has_capability('moodle/course:manageactivities', $context);

if ($action !== '') {
    require_sesskey();
    require_capability('moodle/course:manageactivities', $context);
    $placement = placement_manager::get(required_param('placementid', PARAM_INT));
    if ((int) $placement->courseid !== $courseid) {
        throw new moodle_exception('invalidrecord', 'error', '', placement_manager::TABLE);
    }
    $message = '';
    $type = notification::NOTIFY_SUCCESS;
    try {
        switch ($action) {
            case 'apply':
                $versionid = required_param('versionid', PARAM_INT);
                $placement = placement_manager::apply((int) $placement->id, $versionid, (int) $USER->id);
                $version = version_manager::get((int) $placement->appliedversionid);
                $message = get_string('updateapplied', 'local_nextpool', (int) $version->versionnr);
                break;
            case 'alongside':
                $versionid = required_param('versionid', PARAM_INT);
                $placement = placement_manager::place_alongside((int) $placement->id, $versionid, (int) $USER->id);
                $version = version_manager::get((int) $placement->appliedversionid);
                $message = get_string('placedalongside', 'local_nextpool', (int) $version->versionnr);
                break;
            case 'skip':
                placement_manager::skip((int) $placement->id);
                $message = get_string('updateskipped', 'local_nextpool');
                break;
            case 'policy':
                require_capability('moodle/course:manageactivities', context_module::instance($placement->cmid));
                placement_manager::set_policy((int) $placement->id, required_param('policy', PARAM_ALPHA));
                $message = get_string('policychanged', 'local_nextpool');
                break;
            default:
                throw new moodle_exception('invalidaction', 'local_nextpool');
        }
    } catch (moodle_exception $e) {
        if ($action === 'apply' && $e->errorcode === 'error_noinplace') {
            // Keep the reason with the version, so the page explains it and offers the way out (q29).
            placement_manager::offer((int) $placement->id, required_param('versionid', PARAM_INT), (string) $e->a, false);
        }
        $message = $e->getMessage();
        $type = notification::NOTIFY_ERROR;
    }
    redirect($pageurl, $message, null, $type);
}

$title = get_string('placementsheading', 'local_nextpool');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->set_pagelayout('incourse');
$catalogueurl = new moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid]);
$PAGE->navbar->add(get_string('coursecatalogue', 'local_nextpool'), $catalogueurl);
$PAGE->navbar->add($title);

$placements = placement_manager::get_overview($courseid);

$PAGE->requires->js_call_amd('local_nextpool/placement_policy', 'init');
echo $OUTPUT->header();
echo area_nav::course($courseid, area_nav::PLACEMENTS, count(placement_manager::get_for_course($courseid)));
echo area_nav::heading($title);
echo html_writer::tag('p', get_string('placements_desc', 'local_nextpool'));

if (!$placements) {
    echo $OUTPUT->notification(get_string('noplacements', 'local_nextpool'), notification::NOTIFY_INFO);
    echo $OUTPUT->single_button($catalogueurl, get_string('coursecatalogue', 'local_nextpool'), 'get');
    echo $OUTPUT->footer();
    die();
}

$modinfo = get_fast_modinfo($course);

$table = new html_table();
$table->attributes['data-tour'] = 'placementstable';
$table->caption = get_string('placementstablecaption', 'local_nextpool');
$table->captionhide = true;
$table->head = [
    get_string('activity'),
    get_string('catalogueitem', 'local_nextpool'),
    get_string('versionincourse', 'local_nextpool'),
    get_string('policy', 'local_nextpool'),
    get_string('status'),
    get_string('actions'),
];
$cms = $modinfo->get_cms();
foreach ($placements as $placement) {
    // A course module that is being deleted is skipped; its placement goes with the deletion event.
    $cm = $cms[(int) $placement->cmid] ?? null;
    if ($cm === null || !empty($cm->deletioninprogress)) {
        continue;
    }
    // Item and version numbers come with the placement (bug56).
    $item = (object) ['id' => $placement->itemid, 'contextid' => $placement->itemcontextid, 'title' => $placement->itemtitle,
        'status' => $placement->itemstatus];
    $applied = (int) $placement->appliedversionnr;
    $rowname = $cm->get_formatted_name(['escape' => false]);

    if ($canmanage && has_capability('moodle/course:manageactivities', context_module::instance($cm->id))) {
        $policycell = \local_nextpool\output\update_policy::render($placement, $rowname, $pageurl);
    } else {
        $policycell = policy::get_name($placement->policy);
    }

    $rowactions = [];
    $statuscell = html_writer::div(
        \local_nextpool\output\placement_status::render($placement, $rowname, $pageurl, $canmanage),
        '',
        ['data-region' => 'placement-status', 'data-placementid' => $placement->id]
    );

    if ($canmanage && \local_nextpool\local\direct_upload::can_upload($cm) && $cm->modname !== 'nextpool') {
        if (\local_nextpool\local\direct_upload::has_course_changes($cm)) {
            $statuscell .= html_writer::div(s(get_string('uploadcoursechanged', 'local_nextpool')), 'mt-1');
        }
        $rowactions[] = table_actions::icon_link(
            new moodle_url('/local/nextpool/uploadversion.php', ['cmid' => $cm->id]),
            'i/upload',
            get_string('uploadnewversion', 'local_nextpool'),
            $rowname
        );
    }

    // The item links to its course view with the feedback (F08, task81), while it is available in the course.
    $itemcell = format_string($item->title, true, ['context' => \local_nextpool\local\access::get_context($item)]);
    if (\local_nextpool\local\catalogue::is_available_in_course($item, $courseid)) {
        $itemcell = html_writer::link(
            new moodle_url('/local/nextpool/feedback.php', ['itemid' => $item->id, 'courseid' => $courseid]),
            $itemcell
        );
    }
    // Propose a new version from the activity (F07, task82; F07.AC18).
    // Items in this course's own context take new versions in the activity settings (M06).
    $owncourseitem = (int) $item->contextid === (int) $context->id;
    if (!$owncourseitem && \local_nextpool\local\access::can_propose($item, $courseid)) {
        $rowactions[] = table_actions::icon_link(
            new moodle_url('/local/nextpool/propose.php', ['cmid' => $cm->id]),
            't/sendmessage',
            get_string('proposeversion', 'local_nextpool'),
            $cm->get_formatted_name(['escape' => false]),
            ['data-tour' => 'placementproposal']
        );
    }

    $table->data[] = [
        table_actions::row_header(
            html_writer::link($cm->url ?? new moodle_url('/course/view.php', ['id' => $courseid]), $cm->get_formatted_name())
        ),
        $itemcell,
        get_string('versionnr', 'local_nextpool', $applied),
        $policycell,
        $statuscell,
        table_actions::group($rowactions, $rowname),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
