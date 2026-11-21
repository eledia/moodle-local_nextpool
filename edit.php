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
 * Add or edit a catalogue item (F01, F09).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\form\item_form;
use local_nextpool\local\access;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);

// Not admin_externalpage_setup(): it checks the capabilities in the system context only, while editors may hold
// them in a course category. The capability is checked in the item's context below.
require_login();
$pageurl = new moodle_url('/local/nextpool/edit.php', $id ? ['id' => $id] : []);
$listurl = new moodle_url('/local/nextpool/items.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = null;
$authors = [];
$locked = false;
if ($id) {
    $item = item_manager::get($id);
    access::require_edit($item);
    $authors = item_manager::get_authors($id);
    $locked = $DB->record_exists(version_manager::TABLE, ['itemid' => $id]);
    $returnurl = new moodle_url('/local/nextpool/item.php', ['id' => $id]);
} else {
    access::require_create();
    $returnurl = $listurl;
}

$authorusers = [];
$authoruserids = array_filter(array_map(static fn($a) => (int) $a->userid, $authors));
if ($authoruserids) {
    $userfields = 'id' . \core_user\fields::for_name()->get_sql()->selects;
    foreach ($DB->get_records_list('user', 'id', $authoruserids, '', $userfields) as $user) {
        $authorusers[(int) $user->id] = fullname($user);
    }
}

$form = new item_form($pageurl, [
    'id' => $id,
    'locked' => $locked,
    'sourceid' => (int) ($item->sourceid ?? 0),
    'type' => (string) ($item->type ?? ''),
    'contextid' => (int) ($item->contextid ?? 0),
    'authorcount' => count($authors),
    'authorusers' => $authorusers,
    'competencycount' => $id ? count(item_competencies::get($id)) : 0,
    'timemodified' => (int) ($item->timemodified ?? 0),
    'usermodified' => (int) ($item->usermodified ?? 0),
    // Item editors keep the availability; widening it stays with the editorial team (q68, TD-310).
    'visibilitylocked' => $item && !access::can_manage($item),
]);

if ($item) {
    $form->set_data(item_form::prepare_data($item, $authors));
}

if ($form->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $form->get_data()) {
    [$fields, $newauthors] = item_form::extract_data($data);
    if ($locked) {
        unset($fields->type, $fields->sourceid);
    }
    // A file for version 1 of a new item (task101); the item and the version are created together or not at all.
    $uploadname = $item ? '' : clean_param((string) $form->get_new_filename('initialfile'), PARAM_FILE);
    $transaction = $DB->start_delegated_transaction();
    try {
        if ($item) {
            item_manager::update($id, $fields, $newauthors);
            $saved = $item;
        } else {
            $saved = item_manager::create($fields, $newauthors);
        }
        item_manager::set_tags((int) $saved->id, $data->tags ?? []);
        item_competencies::set((int) $saved->id, item_form::extract_competencies($data));
        $data->id = (int) $saved->id;
        \local_nextpool\customfield\item_handler::instance()->instance_form_save($data, !$id);
        if ($uploadname !== '') {
            $tempfile = make_request_directory() . '/upload';
            if (!$form->save_file('initialfile', $tempfile, true)) {
                throw new moodle_exception('error_invalidpath', 'local_nextpool');
            }
            version_manager::create_from_upload((int) $saved->id, $tempfile, $uploadname);
        }
        $transaction->allow_commit();
    } catch (moodle_exception $e) {
        try {
            $transaction->rollback($e);
        } catch (moodle_exception $e) {
            // Domain validation that the form cannot express (e.g. a disabled licence); show it above the form.
            \core\notification::error($e->getMessage());
        }
        $saved = null;
    }
    if ($saved) {
        // Unknown idnumbers are kept but cannot be linked when placing; tell the editor now (F14).
        $unresolved = item_competencies::get_unresolved((int) $saved->id);
        if ($unresolved) {
            \core\notification::warning(get_string('competenciesunresolved', 'local_nextpool', s(implode(', ', $unresolved))));
        }
        redirect(
            new moodle_url('/local/nextpool/item.php', ['id' => $saved->id]),
            $uploadname !== '' ? get_string('itemcreatedwithversion', 'local_nextpool') : get_string('changessaved'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
} else if ($form->get_edit_conflict() !== '') {
    // Someone saved the item since the form was opened (F08.AC09); the entries stay in the form.
    \core\notification::error($form->get_edit_conflict());
}

$heading = $item ? get_string('edititem', 'local_nextpool', format_string($item->title)) : get_string('additem', 'local_nextpool');
editorial_navbar::add($PAGE, $item, $item ? get_string('edit') : get_string('additem', 'local_nextpool'));
$PAGE->set_title($heading);

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
echo $OUTPUT->heading($heading);
if (!$item && !source_manager::get_records(true)) {
    $sourcesurl = new moodle_url('/local/nextpool/sources.php');
    echo $OUTPUT->notification(
        get_string('nosourcesforitem', 'local_nextpool', $sourcesurl->out()),
        \core\output\notification::NOTIFY_WARNING
    );
} else {
    $form->display();
}
echo $OUTPUT->footer();
