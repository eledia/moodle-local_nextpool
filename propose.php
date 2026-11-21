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
 * Propose from a course: a new version of a catalogue item, an activity as new item, or a returned proposal again
 * (F07, task82).
 *
 * Parameters: cmid (activity placed from the catalogue: new version; with new=1: the activity as new item),
 * courseid and itemid (new version from the course view of an item), or versionid (change a returned proposal).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\proposal_form;
use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;

require_once(__DIR__ . '/../../config.php');

$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$itemid = optional_param('itemid', 0, PARAM_INT);
$versionid = optional_param('versionid', 0, PARAM_INT);
$new = optional_param('new', 0, PARAM_BOOL);

$item = null;
$version = null;
$cm = null;
if ($versionid) {
    // A returned proposal, changed and submitted again (F07.AC05).
    $version = version_manager::get($versionid);
    $item = item_manager::get((int) $version->itemid);
    $courseid = (int) $version->courseid;
    $course = $courseid ? get_course($courseid) : get_site();
    require_login($course);
    if (!proposal_manager::is_own($version) || $version->status !== status::DRAFT) {
        throw new moodle_exception('error_notownproposal', 'local_nextpool');
    }
    $mode = proposal_form::MODE_RESUBMIT;
    $params = ['versionid' => $versionid];
    $returnurl = new moodle_url('/local/nextpool/myproposals.php', ['courseid' => $courseid ?: SITEID]);
} else if ($cmid) {
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = (int) $course->id;
    require_login($course, false, $cm);
    $returnurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
    if ($new) {
        // An activity of the course as new item (F07.AC10).
        if (!proposal_manager::can_propose_item($cm)) {
            throw new required_capability_exception(context_course::instance($courseid), access::PROPOSE, 'nopermissions', '');
        }
        $mode = proposal_form::MODE_ITEM;
        $params = ['cmid' => $cmid, 'new' => 1];
    } else {
        $placement = placement_manager::get_by_cmid($cmid);
        if ($placement === null) {
            throw new moodle_exception('error_placementgone', 'local_nextpool');
        }
        $item = item_manager::get((int) $placement->itemid);
        $mode = proposal_form::MODE_VERSION;
        $params = ['cmid' => $cmid];
    }
} else {
    $course = get_course($courseid);
    require_login($course);
    $item = item_manager::get(required_param('itemid', PARAM_INT));
    $mode = proposal_form::MODE_VERSION;
    $params = ['courseid' => $courseid, 'itemid' => $item->id];
    $returnurl = new moodle_url('/local/nextpool/feedback.php', ['itemid' => $item->id, 'courseid' => $courseid]);
}
if (
    ($mode === proposal_form::MODE_VERSION && !access::can_propose($item, $courseid))
        || ($mode === proposal_form::MODE_RESUBMIT && !proposal_manager::can_resubmit($version))
) {
    // Only items the course can see (F07.AC18).
    throw new required_capability_exception(context_course::instance($courseid), access::PROPOSE, 'nopermissions', '');
}

$pageurl = new moodle_url('/local/nextpool/propose.php', $params);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');

$type = $mode === proposal_form::MODE_ITEM ? (string) proposal_manager::get_item_type($cm) : (string) $item->type;
// The activity whose content the proposal can take over (task83).
$extractcm = $cm !== null ? $cm : proposal_manager::get_extract_cm($item, $courseid);
$canextract = $extractcm !== null && proposal_manager::can_extract($extractcm, $type);
$form = new proposal_form($pageurl, [
    'mode' => $mode,
    'type' => $type,
    'itemid' => $item ? (int) $item->id : 0,
    'comments' => $item ? proposal_manager::get_open_comments((int) $item->id) : [],
    'params' => $params,
    'cm' => $canextract ? $extractcm : null,
]);
if ($mode === proposal_form::MODE_ITEM) {
    $form->set_data(['title' => $cm->get_formatted_name(['escape' => false]), 'author' => fullname($USER)]);
} else if ($mode === proposal_form::MODE_RESUBMIT) {
    $form->set_data([
        'changelog_editor' => ['text' => (string) $version->changelog, 'format' => (int) $version->changelogformat],
        'commentids' => array_keys(proposal_manager::get_linked_comments($versionid)),
    ]);
}

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $localfile = null;
    $filename = '';
    $file = proposal_form::get_draft_file((int) ($data->proposalfile ?? 0));
    $fromactivity = proposal_form::get_source($data, $canextract) === proposal_form::SOURCE_ACTIVITY;
    if (!$fromactivity && $file !== null) {
        $localfile = make_request_directory() . '/proposal';
        $file->copy_content_to($localfile);
        $filename = $file->get_filename();
    }
    $changelog = (string) $data->changelog_editor['text'];
    $changelogformat = (int) $data->changelog_editor['format'];
    $commentids = array_map('intval', (array) ($data->commentids ?? []));
    try {
        if ($fromactivity) {
            [$localfile, $filename] = proposal_manager::extract($extractcm, $type, $item ? (int) $item->id : 0);
        }
        if ($mode === proposal_form::MODE_ITEM) {
            $authorname = trim((string) $data->author);
            $version = proposal_manager::propose_item($cm, $localfile, $filename, (object) [
                'title' => (string) $data->title,
                'description' => (string) $data->description_editor['text'],
                'descriptionformat' => (int) $data->description_editor['format'],
                'license' => (string) $data->license,
                'authors' => [$authorname === fullname($USER) ? ['userid' => (int) $USER->id] : ['name' => $authorname]],
                'changelog' => $changelog,
                'changelogformat' => $changelogformat,
            ]);
        } else if ($mode === proposal_form::MODE_RESUBMIT) {
            $version = proposal_manager::resubmit(
                $versionid,
                $changelog,
                $changelogformat,
                $localfile,
                $filename,
                $commentids
            );
        } else {
            $version = proposal_manager::propose_version(
                (int) $item->id,
                $courseid,
                $localfile,
                $filename,
                $changelog,
                $changelogformat,
                $commentids,
                $cmid
            );
        }
        redirect(
            new moodle_url('/local/nextpool/myproposals.php', ['courseid' => $courseid ?: SITEID]),
            get_string('proposalsubmitted', 'local_nextpool'),
            null,
            notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

$context = $item ? access::get_context($item) : context_course::instance($courseid);
$name = match ($mode) {
    proposal_form::MODE_ITEM => $cm->get_formatted_name(),
    proposal_form::MODE_RESUBMIT => s(review_manager::get_version_name($version)),
    default => format_string($item->title, true, ['context' => $context]),
};
$title = get_string('proposeheading_' . $mode, 'local_nextpool', $name);
$PAGE->set_title(strip_tags($title));
// From an activity the page belongs to the activity: its navigation and breadcrumb, no course tab row (task174).
// From the catalogue or My proposals it stays in the course catalogue area with its tab row.
$inactivity = $cm !== null;
if ($courseid) {
    $PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($courseid)]));
}
if ($inactivity) {
    $PAGE->set_secondary_active_tab('local_nextpool_propose');
    $PAGE->navbar->add(
        get_string($mode === proposal_form::MODE_ITEM ? 'proposeitem' : 'proposeversion', 'local_nextpool'),
        $pageurl
    );
    // The heading names the activity, so it takes the place of the activity title; no completion, no description.
    $PAGE->activityheader->set_attrs(['hidecompletion' => true, 'description' => '', 'title' => strip_tags($title)]);
} else {
    if ($courseid) {
        $PAGE->navbar->add(
            get_string('coursecatalogue', 'local_nextpool'),
            new moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid])
        );
        $PAGE->navbar->add(
            get_string('myproposals', 'local_nextpool'),
            new moodle_url('/local/nextpool/myproposals.php', ['courseid' => $courseid])
        );
    }
    $PAGE->navbar->add(strip_tags($title));
}

echo $OUTPUT->header();
if (!$inactivity && $courseid) {
    echo area_nav::course($courseid, area_nav::MYPROPOSALS, count(placement_manager::get_for_course($courseid)), true);
}
// In the activity the activity header shows the title set above, also in themes with "notitle" like Boost: a title set
// on the header is always printed. A second heading here would repeat it (task174).
if (!$inactivity) {
    echo area_nav::heading($title);
}
echo html_writer::tag('p', get_string('propose_desc', 'local_nextpool'), ['class' => 'text-muted']);
$form->display();
echo $OUTPUT->footer();
