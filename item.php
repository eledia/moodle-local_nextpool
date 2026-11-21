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
 * Editorial view of a catalogue item and its versions, with workflow actions (F01, F02).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\publish_form;
use local_nextpool\local\access;
use local_nextpool\local\content_type\h5p;
use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\item_page;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
// Workflow icons in the item list come back to the list with its filters (task102).
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

// Not admin_externalpage_setup(): it checks the capabilities in the system context only, while editors may hold
// them in a course category. The capability is checked in the item's context below.
require_login();
$pageurl = new moodle_url('/local/nextpool/item.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = item_manager::get($id);
access::require_view($item);

/**
 * Ask before a status change or deletion and stop: before withdrawing with the consequence for courses (task105,
 * decision 2), and for every change that came as a link from a menu without sesskey (bug112). Only the button
 * of the confirmation posts the change.
 *
 * @param stdClass $item Item record.
 * @param string $message Question.
 * @param moodle_url $continue The action with its parameters; confirm and sesskey are added.
 * @param moodle_url $cancel Where cancelling leads.
 * @param string $heading Heading, default "Withdraw".
 */
function local_nextpool_item_confirm(
    stdClass $item,
    string $message,
    moodle_url $continue,
    moodle_url $cancel,
    string $heading = ''
): void {
    global $OUTPUT, $PAGE;
    $heading = $heading !== '' ? $heading : get_string('withdraw', 'local_nextpool');
    editorial_navbar::add($PAGE, $item, $heading);
    $PAGE->set_title($heading);
    echo $OUTPUT->header();
    echo area_nav::editorial(area_nav::ITEMS, true);
    echo $OUTPUT->confirm($message, new single_button(
        new moodle_url($continue, ['confirm' => 1, 'sesskey' => sesskey()]),
        $heading,
        'post',
        $continue->get_param('to') === status::WITHDRAWN ? single_button::BUTTON_DANGER : single_button::BUTTON_PRIMARY
    ), $cancel);
    echo $OUTPUT->footer();
    die();
}

if ($action !== '') {
    // Status changes and deleting may come as menu links without sesskey: they only show a confirmation that posts
    // (bug112). Everything else needs the sesskey right away.
    $hassesskey = optional_param('sesskey', '', PARAM_RAW) !== '' && confirm_sesskey();
    if (!in_array($action, ['itemstatus', 'versionstatus', 'delete'], true)) {
        require_sesskey();
    }
    $backurl = $returnurl !== '' ? new moodle_url($returnurl) : $pageurl;
    $confirmed = optional_param('confirm', 0, PARAM_BOOL);
    $message = '';
    $type = notification::NOTIFY_SUCCESS;
    try {
        switch ($action) {
            case 'itemstatus':
                $to = required_param('to', PARAM_ALPHA);
                access::require_change_status($item, $item->status, $to);
                if (!$confirmed && ($to === status::WITHDRAWN || !$hassesskey)) {
                    $label = get_string('itemtransition_' . $to, 'local_nextpool');
                    local_nextpool_item_confirm(
                        $item,
                        $to === status::WITHDRAWN
                            ? get_string('withdrawitemconfirm', 'local_nextpool', format_string($item->title))
                            : get_string('statuschangeconfirm', 'local_nextpool', (object) [
                                'action' => $label,
                                'title' => format_string($item->title),
                            ]),
                        new moodle_url($pageurl, array_filter(['action' => $action, 'to' => $to, 'returnurl' => $returnurl])),
                        $backurl,
                        $label
                    );
                }
                require_sesskey();
                item_manager::set_status($id, $to);
                $message = get_string('statuschanged', 'local_nextpool', status::get_name($to));
                break;

            case 'versionstatus':
                $version = version_manager::get(required_param('versionid', PARAM_INT));
                if ((int) $version->itemid !== $id) {
                    throw new moodle_exception('invalidrecord', 'error', '', version_manager::TABLE);
                }
                $to = required_param('to', PARAM_ALPHA);
                access::require_change_status($item, $version->status, $to, $version);
                $decisions = [status::PUBLISHED, status::DRAFT, status::REJECTED];
                if ($version->status === status::REVIEW && in_array($to, $decisions, true)) {
                    // Review decisions are taken on the review page, which writes into the storage location (task80).
                    redirect(new moodle_url('/local/nextpool/review.php', ['versionid' => $version->id]));
                }
                $h5pform = $to === status::PUBLISHED && $item->type === 'h5p';
                // The H5P publish form carries its own sesskey; everything else asks first without one (bug112).
                if (!$confirmed && ($to === status::WITHDRAWN || (!$hassesskey && !$h5pform))) {
                    $label = get_string('versiontransition_' . $to, 'local_nextpool', (int) $version->versionnr);
                    local_nextpool_item_confirm(
                        $item,
                        $to === status::WITHDRAWN
                            ? get_string('withdrawversionconfirm', 'local_nextpool', (object) [
                                'versionnr' => (int) $version->versionnr,
                                'title' => format_string($item->title),
                            ])
                            : get_string('statuschangeconfirm', 'local_nextpool', (object) [
                                'action' => $label,
                                'title' => format_string($item->title),
                            ]),
                        new moodle_url($pageurl, ['action' => $action, 'versionid' => $version->id, 'to' => $to]),
                        $backurl,
                        $label
                    );
                }
                $h5plibraries = false;
                if ($h5pform) {
                    // H5P libraries are only updated with an explicit option when publishing (q39).
                    $file = version_manager::get_file((int) $version->id);
                    $form = new publish_form($pageurl, [
                        'id' => $id,
                        'versionid' => $version->id,
                        'versionnr' => $version->versionnr,
                        'librarychanges' => $file ? h5p::get_library_changes($file) : [],
                    ]);
                    if ($form->is_cancelled()) {
                        redirect($pageurl);
                    }
                    $data = $form->get_data();
                    if (!$data) {
                        $heading = get_string('versiontransition_published', 'local_nextpool', (int) $version->versionnr);
                        editorial_navbar::add($PAGE, $item, $heading);
                        $PAGE->set_title($heading);
                        echo $OUTPUT->header();
                        echo area_nav::editorial(area_nav::ITEMS, true);
                        echo $OUTPUT->heading($heading);
                        $form->display();
                        echo $OUTPUT->footer();
                        die();
                    }
                    $h5plibraries = !empty($data->h5plibraries);
                }
                require_sesskey();
                version_manager::set_status((int) $version->id, $to, 0, $h5plibraries);
                $message = get_string('statuschanged', 'local_nextpool', status::get_name($to));
                break;

            case 'removeeditor':
                access::require_manage($item);
                $userid = required_param('userid', PARAM_INT);
                if (item_editor_manager::remove($id, $userid)) {
                    $message = get_string('itemeditorremoved', 'local_nextpool', s(fullname(core_user::get_user($userid))));
                }
                break;

            case 'opensource':
                // Link "Open in storage location" (F08.AC10, q69): asks the storage location only when followed.
                access::require_edit($item);
                $latest = version_manager::get_latest($id);
                $weburl = source_manager::get((int) $item->sourceid)->get_web_url($latest ? (string) $latest->sourceref : '');
                if ($weburl === null) {
                    throw new moodle_exception('error_noweburl', 'local_nextpool');
                }
                redirect(new moodle_url($weburl));
                break;

            case 'checkupdate':
                access::require_edit($item);
                $entry = version_manager::check_for_update($id);
                if ($entry) {
                    $message = get_string('checkupdate_changed', 'local_nextpool', s($entry->path));
                    $type = notification::NOTIFY_INFO;
                } else if ($DB->get_field(item_manager::TABLE, 'timesourcemissing', ['id' => $id])) {
                    $message = get_string('checkupdate_missing', 'local_nextpool');
                    $type = notification::NOTIFY_WARNING;
                } else {
                    $message = get_string('checkupdate_unchanged', 'local_nextpool');
                }
                break;

            case 'delete':
                access::require_manage($item);
                if (!optional_param('confirm', 0, PARAM_BOOL)) {
                    editorial_navbar::add($PAGE, $item, get_string('delete'));
                    $PAGE->set_title(get_string('delete') . ': ' . format_string($item->title));
                    echo $OUTPUT->header();
                    echo area_nav::editorial(area_nav::ITEMS, true);
                    echo $OUTPUT->confirm(
                        get_string('deleteitemconfirm', 'local_nextpool', format_string($item->title)),
                        new single_button(
                            new moodle_url($pageurl, ['action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]),
                            get_string('delete'),
                            'post',
                            single_button::BUTTON_DANGER
                        ),
                        $pageurl
                    );
                    echo $OUTPUT->footer();
                    die();
                }
                require_sesskey();
                item_manager::delete($id);
                redirect(
                    new moodle_url('/local/nextpool/items.php'),
                    get_string('itemdeleted', 'local_nextpool'),
                    null,
                    notification::NOTIFY_SUCCESS
                );
                break;

            default:
                throw new moodle_exception('invalidaction', 'local_nextpool');
        }
    } catch (moodle_exception $e) {
        // Workflow rules (e.g. withdrawing the last published version) are reported, not shown as an error page.
        if ($e instanceof required_capability_exception) {
            throw $e;
        }
        $message = $e->getMessage();
        $type = notification::NOTIFY_ERROR;
    }
    redirect($backurl, $message, null, $type);
}

$title = format_string($item->title, true, ['context' => access::get_context($item)]);
editorial_navbar::add($PAGE, $item);
$PAGE->set_title($title);

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
echo area_nav::item($item, area_nav::ITEM);
// The template prints the title as the page heading, next to the type and status.
echo $OUTPUT->render_from_template(
    'local_nextpool/item_page',
    (new item_page($item))->export_for_template($OUTPUT)
);
// Section "Feedback" (F08, task81); its forms post to feedback.php.
$feedback = new \local_nextpool\output\feedback_section(
    $item,
    0,
    optional_param('filter', \local_nextpool\output\feedback_section::FILTER_ALL, PARAM_ALPHANUM)
);
echo $OUTPUT->render_from_template('local_nextpool/feedback_section', $feedback->export_for_template($OUTPUT));
echo $OUTPUT->footer();
