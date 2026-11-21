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
 * Replace the content of a course-bound SCORM, H5P or file activity (M08, task194).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\form\upload_version_form;
use local_nextpool\local\direct_upload;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;

require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);
$cm = get_fast_modinfo($course)->get_cm($cmid);
if ($cm->modname === 'nextpool' || !direct_upload::can_upload($cm)) {
    throw new moodle_exception('error_uploadnotallowed', 'local_nextpool');
}
$placement = placement_manager::get_by_cmid($cmid);
$item = item_manager::get((int) $placement->itemid);
if ($item->type === 'h5p' && !has_capability('moodle/h5p:deploy', $context)) {
    throw new moodle_exception('uploadh5pforbidden', 'local_nextpool');
}
$url = new moodle_url('/local/nextpool/uploadversion.php', ['cmid' => $cmid]);
$returnurl = new moodle_url('/local/nextpool/placements.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('uploadnewversion', 'local_nextpool'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('uploadnewversion', 'local_nextpool'));
$form = new upload_version_form($url, [
    'item' => $item, 'context' => $context, 'changed' => direct_upload::has_course_changes($cm),
    'maxbytes' => get_user_max_upload_file_size($context, $CFG->maxbytes, $course->maxbytes),
]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]));
} else if ($data = $form->get_data()) {
    $file = upload_version_form::get_file((int) $data->package);
    $path = make_request_directory() . '/upload';
    $file->copy_content_to($path);
    $result = direct_upload::add_version((int) $placement->id, $path, $file->get_filename(), false, $USER->id, (object) [
        'changelog' => $data->changelog_editor['text'], 'changelogformat' => $data->changelog_editor['format'],
    ]);
    $message = $result->applied ? get_string('changessaved')
        : get_string('uploadversionoffered', 'local_nextpool', $result->reason);
    redirect($returnurl, $message, null, $result->applied
        ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('uploadnewversion', 'local_nextpool'));
$form->display();
echo $OUTPUT->footer();
