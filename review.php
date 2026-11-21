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
 * Review a version: publish into the storage location, return for revision or reject (F07, task80).
 *
 * Reviewers decide here ({@see access::can_review()}); editors of the item see the page read-only, and the person
 * who proposed a version sees its status and the reasons of the decisions (F07.AC06).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\review_form;
use local_nextpool\local\access;
use local_nextpool\local\content_type\h5p;
use local_nextpool\local\direct_upload;
use local_nextpool\local\item_manager;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\review_page;

require_once(__DIR__ . '/../../config.php');

$versionid = required_param('versionid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

require_login();
$pageurl = new moodle_url('/local/nextpool/review.php', ['versionid' => $versionid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$version = version_manager::get($versionid);
$item = item_manager::get((int) $version->itemid);
$canreview = access::can_review($item);
$isproposer = $version->origin === version_manager::ORIGIN_PROPOSAL && (int) $version->createdby === (int) $USER->id;
if (!$canreview && !$isproposer) {
    access::require_view($item);
}
$itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $item->id]);
$candecide = $canreview && $version->status === status::REVIEW;

if ($action === 'sync') {
    // Check the storage location after a conflict (q74): the change becomes a draft.
    require_sesskey();
    if (!$canreview) {
        throw new required_capability_exception(access::get_context($item), access::REVIEW, 'nopermissions', '');
    }
    try {
        $draft = review_manager::sync_now((int) $item->id);
        [$message, $type] = $draft
            ? [get_string('reviewsynced', 'local_nextpool', (int) $draft->versionnr), notification::NOTIFY_SUCCESS]
            : [get_string('checkupdate_unchanged', 'local_nextpool'), notification::NOTIFY_INFO];
    } catch (moodle_exception $e) {
        [$message, $type] = [$e->getMessage(), notification::NOTIFY_ERROR];
    }
    redirect($pageurl, $message, null, $type);
}

if ($action === 'delete') {
    // The editorial team deletes a rejected or cancelled proposal before the retention period ends (q67, task86).
    if (!proposal_manager::can_delete($item, $version)) {
        throw new required_capability_exception(access::get_context($item), access::MANAGE, 'nopermissions', '');
    }
    $versionname = s(review_manager::get_version_name($version));
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        $itemdeleted = proposal_manager::delete($versionid);
        redirect(
            $itemdeleted ? new moodle_url('/local/nextpool/items.php') : $itemurl,
            get_string($itemdeleted ? 'proposaldeleteditem' : 'proposaldeleted', 'local_nextpool', $versionname),
            null,
            notification::NOTIFY_SUCCESS
        );
    }
    $heading = get_string('proposaldelete', 'local_nextpool');
    $PAGE->set_title($heading);
    $PAGE->navbar->add($heading);
    echo $OUTPUT->header();
    $message = get_string('proposaldeleteconfirm', 'local_nextpool', $versionname);
    if (proposal_manager::deletes_item($version)) {
        $message .= ' ' . get_string('proposaldeleteconfirmitem', 'local_nextpool', format_string(
            $item->title,
            true,
            ['context' => access::get_context($item)]
        ));
    }
    echo $OUTPUT->confirm(
        $message,
        new single_button(
            new moodle_url($pageurl, ['action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('proposaldelete', 'local_nextpool'),
            'post',
            single_button::BUTTON_DANGER
        ),
        $pageurl
    );
    echo $OUTPUT->footer();
    die();
}

$form = null;
if ($candecide) {
    $needslocation = review_manager::needs_location($item);
    $librarychanges = null;
    if ($item->type === 'h5p') {
        $file = version_manager::get_file($versionid);
        $librarychanges = $file ? h5p::get_library_changes($file) : [];
    }
    // Open comments the version takes up, to mark as done with publishing (task82).
    $resolvable = [];
    foreach (proposal_manager::get_linked_comments($versionid) as $comment) {
        if (empty($comment->deleted) && empty($comment->timeresolved)) {
            $resolvable[(int) $comment->id] = shorten_text(trim(html_to_text((string) $comment->content, 0, false)), 80);
        }
    }
    $form = new review_form($pageurl, [
        'versionid' => $versionid,
        'publishlabel' => $version->versionnr === null ? get_string('reviewpublishproposal', 'local_nextpool')
            : get_string('versiontransition_published', 'local_nextpool', (int) $version->versionnr),
        'sources' => $needslocation ? direct_upload::get_writable_sources() : [],
        'librarychanges' => $librarychanges,
        'comments' => $resolvable,
    ]);
    $defaults = [
        'versionid' => $versionid,
        'changelog_editor' => ['text' => (string) $version->changelog, 'format' => (int) $version->changelogformat],
    ];
    if ($needslocation) {
        $defaults['sourceid'] = direct_upload::get_sourceid();
        $defaults['folder'] = review_manager::get_default_folder($version);
    }
    $form->set_data($defaults);
    if ($form->is_cancelled()) {
        redirect($itemurl);
    }
    $data = $form->get_data();
    if ($data) {
        $decision = review_form::get_decision($data);
        try {
            if ($decision === status::PUBLISHED) {
                $options = [
                    'changelog' => $data->changelog_editor['text'] ?? '',
                    'changelogformat' => (int) ($data->changelog_editor['format'] ?? FORMAT_HTML),
                    'h5plibraries' => !empty($data->h5plibraries),
                    'resolvecomments' => array_keys(array_filter((array) ($data->resolvecomments ?? []))),
                ];
                if ($needslocation) {
                    $options['sourceid'] = (int) $data->sourceid;
                    $options['folder'] = (string) $data->folder;
                }
                $version = review_manager::publish($versionid, $options);
            } else if ($decision === status::DRAFT) {
                $version = review_manager::return_for_revision($versionid, (string) $data->reason);
            } else {
                $version = review_manager::reject($versionid, (string) $data->reason);
            }
            redirect(
                $itemurl,
                get_string('reviewdone_' . $decision, 'local_nextpool', s(review_manager::get_version_name($version))),
                null,
                notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            if ($e->errorcode === review_manager::ERROR_SOURCECHANGED) {
                // Offer "Check now"; the version stays in review (q74).
                \core\notification::error($e->getMessage() . ' ' . $OUTPUT->single_button(
                    new moodle_url($pageurl, ['action' => 'sync', 'sesskey' => sesskey()]),
                    get_string('reviewsyncnow', 'local_nextpool'),
                    'post',
                    ['type' => single_button::BUTTON_SECONDARY]
                ));
            } else {
                \core\notification::error($e->getMessage());
            }
        }
    }
}

$heading = get_string('reviewheading', 'local_nextpool', review_manager::get_version_name($version));
if (access::can_view($item)) {
    editorial_navbar::add($PAGE, $item, $heading);
} else if ($isproposer) {
    // The way back to "My proposals" (task82).
    $PAGE->navbar->add(get_string('myproposals', 'local_nextpool'), new moodle_url('/local/nextpool/myproposals.php', [
        'courseid' => (int) $version->courseid ?: SITEID,
    ]));
    $PAGE->navbar->add($heading);
}

// The person who proposed it changes a returned proposal or cancels it until the decision (F07.AC05, AC11).
$proposeractions = '';
if ($isproposer && in_array($version->status, [status::DRAFT, status::REVIEW], true)) {
    if ($version->status === status::DRAFT) {
        $proposeractions .= $OUTPUT->single_button(
            new moodle_url('/local/nextpool/propose.php', ['versionid' => $versionid]),
            get_string('proposalchange', 'local_nextpool'),
            'get',
            ['type' => single_button::BUTTON_PRIMARY]
        );
    }
    $proposeractions .= $OUTPUT->single_button(
        new moodle_url('/local/nextpool/myproposals.php', [
            'courseid' => (int) $version->courseid ?: SITEID,
            'action' => 'cancel',
            'versionid' => $versionid,
        ]),
        get_string('proposalcancel', 'local_nextpool'),
        'get',
        ['type' => single_button::BUTTON_SECONDARY]
    );
}
if (proposal_manager::can_delete($item, $version)) {
    $proposeractions .= $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'delete']),
        get_string('proposaldelete', 'local_nextpool'),
        'get',
        ['type' => single_button::BUTTON_DANGER]
    );
}
$PAGE->set_title($heading);

echo $OUTPUT->header();
if (access::can_use_editorial()) {
    echo area_nav::editorial(area_nav::ITEMS, true);
}
$page = new review_page($item, $version, $form ? $form->render() : '', $proposeractions);
echo $OUTPUT->render_from_template('local_nextpool/review_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
