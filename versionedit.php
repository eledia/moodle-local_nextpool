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
 * Change label and change log of a version afterwards (task103).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\version_details_form;
use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;

require_once(__DIR__ . '/../../config.php');

$versionid = required_param('versionid', PARAM_INT);

// Not admin_externalpage_setup(): the capability is checked in the item's context below.
require_login();
$version = version_manager::get($versionid);
$item = item_manager::get((int) $version->itemid);
access::require_edit($item);
$itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $item->id]);
$pageurl = new moodle_url('/local/nextpool/versionedit.php', ['versionid' => $versionid]);

$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));
$heading = get_string('editversiondetails', 'local_nextpool', review_manager::get_version_name($version));
editorial_navbar::add($PAGE, $item, $heading);
$PAGE->set_title($heading);

if (!version_manager::can_edit_details($version)) {
    redirect($itemurl, get_string('error_versionnoteditable', 'local_nextpool'), null, notification::NOTIFY_ERROR);
}

$form = new version_details_form($pageurl, [
    'versionid' => $versionid,
    'state' => version_details_form::state($version),
]);
$form->set_data([
    'label' => (string) $version->label,
    'changelog_editor' => ['text' => (string) $version->changelog, 'format' => (int) $version->changelogformat],
]);

if ($form->is_cancelled()) {
    redirect($itemurl);
} else if ($data = $form->get_data()) {
    try {
        version_manager::update_details(
            $versionid,
            (string) $data->label,
            (string) ($data->changelog_editor['text'] ?? ''),
            (int) ($data->changelog_editor['format'] ?? FORMAT_HTML)
        );
        redirect($itemurl, get_string('versiondetailssaved', 'local_nextpool'), null, notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
} else if ($form->get_edit_conflict() !== '') {
    // Someone changed the details since the form was opened; the entries stay in the form (F08.AC09).
    \core\notification::error($form->get_edit_conflict());
}

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
// Plain text with a person's name (bug122).
echo $OUTPUT->heading(s($heading));
$form->display();
echo $OUTPUT->footer();
