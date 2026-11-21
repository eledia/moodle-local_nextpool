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
 * Place a catalogue item in a course (F05).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_nextpool\form\place_form;
use local_nextpool\local\catalogue;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);

$course = get_course($courseid);
$pageurl = new moodle_url('/local/nextpool/place.php', ['courseid' => $courseid, 'itemid' => $itemid]);
$PAGE->set_url($pageurl);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/nextpool:viewcatalogue', $context);
require_capability('moodle/course:manageactivities', $context);

$item = item_manager::get($itemid);
$handler = item_types::get_handler($item->type);
if (!catalogue::is_available_in_course($item, $courseid) || $handler === null) {
    throw new moodle_exception('error_notavailableincourse', 'local_nextpool');
}
require_capability('mod/' . $handler->get_modname() . ':addinstance', $context);

$catalogueurl = new moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid]);
$title = format_string($item->title, true, ['context' => \local_nextpool\local\access::get_context($item)]);
$version = version_manager::get((int) $item->currentversionid);
$PAGE->set_title(get_string('placeitem', 'local_nextpool', $title));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->navbar->add(get_string('coursecatalogue', 'local_nextpool'), $catalogueurl);
$PAGE->navbar->add($title);

// A version that cannot work on this site is not offered for placing; the teacher learns why (bug88).
$refusal = $handler->check_place($item, $version);
if ($refusal !== null) {
    echo $OUTPUT->header();
    echo area_nav::course($courseid, area_nav::CATALOGUE, count(placement_manager::get_for_course($courseid)), true);
    echo $OUTPUT->heading(get_string('placeitem', 'local_nextpool', $title));
    echo $OUTPUT->notification($refusal, notification::NOTIFY_WARNING, false);
    echo $OUTPUT->continue_button($catalogueurl);
    echo $OUTPUT->footer();
    die();
}
$modinfo = get_fast_modinfo($course);
$sections = [];
foreach ($modinfo->get_section_info_all() as $section) {
    if ($section->is_delegated()) {
        continue;
    }
    $sections[(int) $section->section] = get_section_name($course, $section);
}

$form = new place_form($pageurl, [
    'courseid' => $courseid,
    'itemid' => $itemid,
    'sections' => $sections,
    'handler' => $handler,
]);
$form->set_data(['section' => optional_param('section', 0, PARAM_INT)]);

if ($form->is_cancelled()) {
    redirect($catalogueurl);
} else if ($data = $form->get_data()) {
    try {
        $options = $handler->get_place_options($data);
        $placement = placement_manager::place($itemid, $courseid, (int) $data->section, $data->policy, 0, $options);
        // Competencies that could not go onto the activity (F14); the placement itself succeeded.
        foreach ($placement->competencyresults as $result) {
            if ($result['result'] !== item_competencies::LINKED) {
                $message = get_string('competencynotlinked_' . $result['result'], 'local_nextpool', s($result['competency']));
                \core\notification::warning($message);
            }
        }
        redirect(
            new moodle_url('/course/view.php', ['id' => $courseid], 'module-' . $placement->cmid),
            get_string('placed', 'local_nextpool', format_string($item->title)),
            null,
            notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

echo $OUTPUT->header();
echo area_nav::course($courseid, area_nav::CATALOGUE, count(placement_manager::get_for_course($courseid)), true);
echo $OUTPUT->heading(get_string('placeitem', 'local_nextpool', $title));
echo html_writer::tag('p', get_string('placeitem_desc', 'local_nextpool', [
    'version' => (int) $version->versionnr,
    'module' => get_string('modulename', $handler->get_modname()),
]));
$form->display();
echo $OUTPUT->footer();
