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
 * Feedback on a catalogue item (F08, task81): course view of the item and the forms of the section "Feedback".
 *
 * With courseid, teachers reach the item from their course (TD-304): the catalogue capability in the course and the
 * item available there. Without courseid, the forms come from the item page (editorial view). The module
 * local_nextpool/feedback sends the same actions through external functions; both ways use feedback_service.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\comment_form;
use local_nextpool\local\access;
use local_nextpool\local\feedback_service;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\feedback_section;

require_once(__DIR__ . '/../../config.php');

$itemid = required_param('itemid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$filter = optional_param('filter', feedback_section::FILTER_ALL, PARAM_ALPHANUM);

$cataloguefilters = \local_nextpool\local\catalogue_return::read();
$pageurl = new moodle_url('/local/nextpool/feedback.php', ['itemid' => $itemid, 'courseid' => $courseid] + $cataloguefilters);
$PAGE->set_url($pageurl);
if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $PAGE->set_pagelayout('incourse');
} else {
    require_login();
    $PAGE->set_context(context_system::instance());
    $PAGE->set_pagelayout('admin');
}

$item = item_manager::get($itemid);
access::require_comment($item, $courseid);

// Where the forms lead back to: the course view or the item page, at the section.
$returnurl = $courseid > 0 ? new moodle_url($pageurl) : new moodle_url('/local/nextpool/item.php', ['id' => $itemid]);
$returnurl->set_anchor('local-nextpool-feedback');
$title = format_string($item->title, true, ['context' => access::get_context($item)]);

// Start a page of the feedback area (course view or editorial view) with the given heading.
$startpage = function (string $heading) use ($PAGE, $OUTPUT, $courseid, $item, $title, $returnurl): void {
    $PAGE->set_title($heading);
    if ($courseid > 0) {
        $PAGE->set_heading(format_string(get_course($courseid)->fullname, true, ['context' => $PAGE->context]));
        $PAGE->navbar->add(
            get_string('coursecatalogue', 'local_nextpool'),
            new moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid])
        );
        $PAGE->navbar->add($title, $returnurl);
        if ($heading !== $title) {
            $PAGE->navbar->add($heading);
        }
        echo $OUTPUT->header();
        echo area_nav::course($courseid, area_nav::CATALOGUE, count(placement_manager::get_for_course($courseid)), true);
    } else {
        $PAGE->set_heading(format_string(get_site()->fullname));
        editorial_navbar::add($PAGE, $item, $heading);
        echo $OUTPUT->header();
        echo area_nav::editorial(area_nav::ITEMS, true);
    }
};

if ($action !== '') {
    $commentid = optional_param('commentid', 0, PARAM_INT);
    $message = '';
    $type = notification::NOTIFY_SUCCESS;

    if ($action === 'edit') {
        [$comment] = feedback_service::get_accessible($commentid, $courseid);
        if (!access::can_update_comment($comment)) {
            throw new moodle_exception('nopermissions', 'error', '', get_string('commentedit', 'local_nextpool'));
        }
        $form = new comment_form($pageurl, ['itemid' => $itemid, 'courseid' => $courseid, 'commentid' => $commentid]);
        $form->set_data(['content' => $comment->content]);
        if ($form->is_cancelled()) {
            redirect($returnurl);
        }
        if ($data = $form->get_data()) {
            feedback_service::update($commentid, $courseid, $data->content);
            redirect($returnurl, get_string('commentupdated', 'local_nextpool'), null, notification::NOTIFY_SUCCESS);
        }
        $heading = get_string('commentedit', 'local_nextpool');
        $startpage($heading);
        echo $OUTPUT->heading($heading, 2);
        $form->display();
        echo $OUTPUT->footer();
        die();
    }

    if ($action === 'delete' && !optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        feedback_service::get_accessible($commentid, $courseid);
        $heading = get_string('commentdelete', 'local_nextpool');
        $startpage($heading);
        echo $OUTPUT->confirm(
            get_string('commentdeleteconfirm', 'local_nextpool'),
            new single_button(
                new moodle_url($pageurl, ['action' => 'delete', 'commentid' => $commentid, 'confirm' => 1, 'sesskey' => sesskey()]),
                get_string('commentdelete', 'local_nextpool'),
                'post',
                single_button::BUTTON_DANGER
            ),
            $returnurl
        );
        echo $OUTPUT->footer();
        die();
    }

    require_sesskey();
    try {
        switch ($action) {
            case 'add':
                $comment = feedback_service::add(
                    $itemid,
                    $courseid,
                    required_param('content', PARAM_RAW),
                    optional_param('versionid', 0, PARAM_INT),
                    optional_param('parentid', 0, PARAM_INT)
                );
                $message = get_string($comment->parentid ? 'replyposted' : 'commentposted', 'local_nextpool');
                $returnurl->set_anchor('local-nextpool-comment-' . $comment->id);
                break;
            case 'delete':
                feedback_service::delete($commentid, $courseid);
                $message = get_string('commentdeleted', 'local_nextpool');
                break;
            case 'resolve':
            case 'reopen':
                feedback_service::set_resolved($commentid, $courseid, $action === 'resolve');
                $message = get_string($action === 'resolve' ? 'commentresolved' : 'commentreopened', 'local_nextpool');
                break;
            case 'subscribe':
            case 'unsubscribe':
                feedback_service::set_subscription($itemid, $courseid, $action === 'subscribe');
                $message = get_string($action === 'subscribe' ? 'subscribed' : 'unsubscribed', 'local_nextpool');
                break;
            default:
                throw new moodle_exception('invalidaction', 'local_nextpool');
        }
    } catch (moodle_exception $e) {
        if ($e instanceof required_capability_exception) {
            throw $e;
        }
        $message = $e->getMessage();
        $type = notification::NOTIFY_ERROR;
    }
    redirect($returnurl, $message, null, $type);
}

if ($courseid <= 0) {
    // The editorial view of the section is part of the item page.
    redirect(new moodle_url('/local/nextpool/item.php', ['id' => $itemid, 'filter' => $filter], 'local-nextpool-feedback'));
}

$startpage($title);
if ($courseid > 0) {
    echo html_writer::link(
        \local_nextpool\local\catalogue_return::url($courseid, $itemid, $cataloguefilters),
        get_string('backtocatalogue', 'local_nextpool'),
        ['class' => 'btn btn-link']
    );
    echo html_writer::link(new moodle_url('/local/nextpool/preview.php', [
        'itemid' => $itemid, 'courseid' => $courseid,
    ] + $cataloguefilters), get_string('preview', 'local_nextpool'), ['class' => 'btn btn-link']);
}
// Propose a new version from the course view of the item (F07, task82).
$proposebutton = '';
if (access::can_propose($item, $courseid)) {
    $proposebutton = $OUTPUT->single_button(
        new moodle_url('/local/nextpool/propose.php', ['courseid' => $courseid, 'itemid' => $itemid]),
        get_string('proposeversion', 'local_nextpool'),
        'get',
        ['type' => single_button::BUTTON_SECONDARY]
    );
}
echo area_nav::heading($title, $proposebutton);
echo html_writer::tag('p', get_string('type_' . $item->type, 'local_nextpool'), ['class' => 'text-muted small mb-2']);
$description = format_text((string) $item->description, (int) $item->descriptionformat, ['context' => access::get_context($item)]);
if (trim(strip_tags($description)) !== '') {
    echo html_writer::div($description, 'mb-3');
}
$feedbackdata = (new feedback_section($item, $courseid, $filter))->export_for_template($OUTPUT);
$versionid = optional_param('versionid', 0, PARAM_INT);
if ($versionid && $courseid > 0) {
    $selectable = array_column($feedbackdata['versionoptions'], 'value');
    if (in_array($versionid, $selectable, true)) {
        foreach ($feedbackdata['versionoptions'] as &$option) {
            $option['selected'] = $option['value'] === $versionid;
        }
        unset($option);
    }
}
echo $OUTPUT->render_from_template('local_nextpool/feedback_section', $feedbackdata);
echo $OUTPUT->footer();
