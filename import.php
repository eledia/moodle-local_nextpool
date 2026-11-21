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
 * Import a file from the item's storage location as a new draft version (F02, F03).
 *
 * The page browses the storage location. Choosing or uploading a file creates the draft version at once, with
 * the automatic label (task103); a version imported meanwhile is refused. Paths are relative to the base folder and
 * validated by path::normalise(), so browsing cannot leave the base folder.
 *
 * Browsing, importing any file and uploading into any folder are for the editorial team only. Item editors (F08,
 * q68, q69) import the file of their item again or upload a new file to its path (access::can_import_path(), bug102).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\upload_form;
use local_nextpool\local\access;
use local_nextpool\local\direct_upload;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\path;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
// Raw on purpose: storage paths may contain any character; path::normalise() validates them below.
$folder = optional_param('path', null, PARAM_RAW);
$file = optional_param('file', '', PARAM_RAW);
$action = optional_param('action', '', PARAM_ALPHA);
// Id of the latest numbered version when the page was opened (F08.AC09, t84-b): 0 for none.
$knownlatest = optional_param('latestversionid', 0, PARAM_INT);

// Not admin_externalpage_setup(): it checks the capabilities in the system context only, while editors may hold
// them in a course category. The capability is checked in the item's context below.
require_login();
$baseurl = new moodle_url('/local/nextpool/import.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = item_manager::get($id);
access::require_edit($item);
// The page works with the service account of the storage location: only the editorial team browses it (bug102).
$canbrowse = access::can_manage($item);
$itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $id]);
$latest = version_manager::get_latest($id);
// Which file the item's type expects (bug206); null for types that cannot be placed yet, which take any file.
$handler = item_types::get_handler($item->type);

$heading = get_string('importversion', 'local_nextpool');
editorial_navbar::add($PAGE, $item, $heading);
$PAGE->set_title($heading);

/**
 * Message if someone imported a version since the page was opened, '' if not (F08.AC09).
 *
 * @param stdClass|null $latest Latest numbered version now.
 * @param int $known Id of the latest version when the page was opened, 0 for none.
 * @return string
 */
function local_nextpool_import_conflict(?stdClass $latest, int $known): string {
    if ((int) ($latest->id ?? 0) === $known) {
        return '';
    }
    $user = $latest->createdby ? core_user::get_user((int) $latest->createdby) : null;
    if (!$user || $user->deleted) {
        // Imported by the storage location check.
        return get_string('error_importconflictauto', 'local_nextpool', (int) $latest->versionnr);
    }
    return get_string('error_importconflict', 'local_nextpool', (object) [
        'user' => s(fullname($user)),
        'versionnr' => (int) $latest->versionnr,
    ]);
}

/**
 * Print an error with a link back to the item and stop.
 *
 * @param string $message Error message.
 * @param moodle_url $itemurl Item page.
 */
function local_nextpool_import_fail(string $message, moodle_url $itemurl): void {
    global $OUTPUT;
    echo $OUTPUT->header();
    echo area_nav::editorial(area_nav::ITEMS, true);
    echo $OUTPUT->heading(get_string('importversion', 'local_nextpool'));
    echo $OUTPUT->notification($message, notification::NOTIFY_ERROR);
    echo $OUTPUT->continue_button($itemurl);
    echo $OUTPUT->footer();
    die();
}

/**
 * Accepted types for the file picker: the extensions of the type if core knows all of them, else any file (bug206).
 *
 * @param \local_nextpool\local\content_type\base|null $handler Handler of the item's type.
 * @return string|string[]
 */
function local_nextpool_import_accepted_types(?\local_nextpool\local\content_type\base $handler): string|array {
    $extensions = $handler ? $handler->get_file_extensions() : [];
    $known = core_filetypes::get_types();
    foreach ($extensions as $extension) {
        if (!isset($known[$extension])) {
            return '*';
        }
    }
    return $extensions ? array_map(static fn(string $extension): string => '.' . $extension, $extensions) : '*';
}

try {
    $source = source_manager::get((int) $item->sourceid);
} catch (source_exception $e) {
    local_nextpool_import_fail($e->getMessage(), $itemurl);
}

// Importing a chosen file creates the draft at once; label and change log are edited on the item page afterwards (task103).
if ($action === 'import' && $file !== '') {
    require_sesskey();
    $conflict = local_nextpool_import_conflict($latest, $knownlatest);
    if ($conflict === '') {
        try {
            $file = path::normalise($file);
            access::require_import_path($item, $file);
            $version = version_manager::create_from_source($id, $file, [
                'basedonversionid' => $latest ? (int) $latest->id : 0,
            ]);
            redirect(
                $itemurl,
                get_string('versionimported', 'local_nextpool', s($version->label)),
                null,
                notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            $conflict = $e->getMessage();
        }
    }
    // Back to the file list: the links there carry the state as it is now.
    redirect($baseurl, $conflict, null, notification::NOTIFY_ERROR);
}

// Step 1: browse the storage location. Start next to the file of the latest version; item editors stay there.
if (($folder === null || !$canbrowse) && $latest) {
    $folder = str_contains($latest->sourceref, '/') ? substr($latest->sourceref, 0, strrpos($latest->sourceref, '/')) : '';
}
try {
    $folder = path::normalise((string) $folder);
} catch (source_exception $e) {
    $folder = '';
}

// Upload into the current folder, then continue with the version form for the uploaded file. Item editors replace
// the file of their item (bug102).
$uploadform = null;
$replacepath = $canbrowse ? '' : (string) ($latest->sourceref ?? '');
if ($source->supports_write() && ($canbrowse || $replacepath !== '')) {
    $uploadform = new upload_form(new moodle_url($baseurl, ['path' => $folder]), [
        'id' => $id,
        'path' => $folder,
        'latestversionid' => $latest ? (int) $latest->id : 0,
        'replace' => $replacepath !== '',
        'accepted_types' => local_nextpool_import_accepted_types($handler),
    ]);
    if ($uploaddata = $uploadform->get_data()) {
        $filename = clean_param((string) $uploadform->get_new_filename('uploadfile'), PARAM_FILE);
        // Nothing is written if someone imported a version meanwhile.
        $conflict = local_nextpool_import_conflict($latest, (int) $uploaddata->latestversionid);
        try {
            if ($conflict !== '') {
                \core\notification::error($conflict);
            } else if ($filename === '') {
                throw new source_exception('error_invalidpath');
            } else {
                $tempfile = make_request_directory() . '/upload';
                $uploadform->save_file('uploadfile', $tempfile, true);
                $target = $canbrowse ? path::join($folder, path::normalise($filename)) : $replacepath;
                access::require_import_path($item, $target);
                // A file the type cannot use is not written into the storage location at all (bug205).
                $handler?->validate_package($tempfile);
                // Only the item's own file is replaced; another file may belong to another item (bug162).
                $replaces = $latest && $target === $latest->sourceref;
                if (!$replaces && $source->stat($target) !== null) {
                    throw new source_exception('error_fileexistsfolder', s($target));
                }
                $written = $source->write($target, $tempfile);
                try {
                    $version = version_manager::create_from_source($id, $written->path, [
                        'basedonversionid' => $latest ? (int) $latest->id : 0,
                    ], $source);
                } catch (\Throwable $e) {
                    // Delete the new file or restore the replaced one; the version error is the one to show (bug162).
                    direct_upload::undo_write($source, $written->path, $replaces ? $latest : null);
                    throw $e;
                }
                redirect(
                    $itemurl,
                    get_string('versionimported', 'local_nextpool', s($version->label)),
                    null,
                    notification::NOTIFY_SUCCESS
                );
            }
        } catch (moodle_exception $e) {
            \core\notification::error($e->getMessage());
        }
    }
}

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
// The explanation sits behind the help icon; the way back is the breadcrumb (task105).
echo $OUTPUT->heading_with_help($heading, 'importversion', 'local_nextpool');
$expected = $handler ? $handler->get_expected_file() : '';
if ($expected !== '') {
    $message = get_string('importexpected', 'local_nextpool', (object) [
        'type' => get_string('type_' . $item->type, 'local_nextpool'),
        'expected' => $expected,
    ]);
    if ($handler->get_file_extensions() && $canbrowse) {
        $message .= ' ' . get_string('importotherfiles', 'local_nextpool');
    }
    echo $OUTPUT->notification($message, notification::NOTIFY_INFO, false);
}

if ($latest) {
    echo $OUTPUT->single_button(
        new moodle_url($baseurl, ['action' => 'import', 'file' => $latest->sourceref, 'latestversionid' => $latest->id]),
        get_string('reimport', 'local_nextpool', s($latest->sourceref)),
        'post',
        ['type' => single_button::BUTTON_PRIMARY]
    );
}

if (!$canbrowse) {
    // Item editors see neither the folders nor the other files of the storage location (bug102).
    echo $OUTPUT->notification(
        get_string($latest ? 'importeditoronly' : 'importeditornofile', 'local_nextpool'),
        notification::NOTIFY_INFO
    );
    if ($uploadform) {
        echo $OUTPUT->heading(get_string('uploadreplace', 'local_nextpool'), 3);
        $uploadform->display();
    }
    echo $OUTPUT->footer();
    die();
}

// Breadcrumb inside the storage location.
$crumbs = [html_writer::link(new moodle_url($baseurl, ['path' => '']), format_string($source->get_name()))];
$walked = '';
foreach ($folder === '' ? [] : explode('/', $folder) as $segment) {
    $walked = path::join($walked, $segment);
    $crumbs[] = $walked === $folder ? s($segment) : html_writer::link(new moodle_url($baseurl, ['path' => $walked]), s($segment));
}
echo html_writer::tag(
    'nav',
    html_writer::tag('p', implode(' / ', $crumbs)),
    ['aria-label' => get_string('currentfolder', 'local_nextpool')]
);

try {
    $entries = $source->list_entries($folder);
} catch (source_exception $e) {
    echo $OUTPUT->notification($e->getMessage(), notification::NOTIFY_ERROR);
    echo $OUTPUT->continue_button($itemurl);
    echo $OUTPUT->footer();
    die();
}

if (!$entries) {
    echo $OUTPUT->notification(get_string('emptyfolder', 'local_nextpool'), notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [get_string('name'), get_string('size'), get_string('lastmodified'), get_string('actions')];
    foreach ($entries as $entry) {
        $name = s($entry->get_name());
        if ($entry->isfolder) {
            $folderurl = new moodle_url($baseurl, ['path' => $entry->path]);
            $table->data[] = [
                table_actions::row_header($OUTPUT->pix_icon('i/folder', '') . ' ' . html_writer::link($folderurl, $name)),
                '',
                $entry->mtime ? userdate($entry->mtime, get_string('strftimedatetimeshort', 'core_langconfig')) : '',
                '',
            ];
            continue;
        }
        $mimetype = $entry->mimetype !== '' ? $entry->mimetype : mimeinfo('type', $entry->get_name());
        if ($latest && $entry->path === $latest->sourceref) {
            $name .= ' ' . html_writer::span(
                get_string('sourceofversion', 'local_nextpool', (int) $latest->versionnr),
                'badge bg-light text-dark border'
            );
        }
        // Files of another type stay in the list, so editors see why their file cannot be chosen, but get no button
        // and no tab stop (bug206, t157-b).
        $action = $handler && !$handler->accepts_filename($entry->get_name())
            ? html_writer::span(get_string('filenotfortype', 'local_nextpool'), 'text-muted')
            : null;
        $table->data[] = [
            table_actions::row_header($OUTPUT->pix_icon(file_mimetype_icon($mimetype), '') . ' ' . $name),
            display_size($entry->size),
            $entry->mtime ? userdate($entry->mtime, get_string('strftimedatetimeshort', 'core_langconfig')) : '',
            $action ?? table_actions::button(
                new moodle_url($baseurl, [
                    'action' => 'import',
                    'file' => $entry->path,
                    'latestversionid' => $latest ? (int) $latest->id : 0,
                ]),
                get_string('selectfile', 'local_nextpool'),
                $entry->get_name()
            ),
        ];
    }
    echo html_writer::table($table);
}

if ($uploadform) {
    echo $OUTPUT->heading(get_string('uploadfile', 'local_nextpool'), 3);
    $uploadform->display();
}

echo $OUTPUT->footer();
