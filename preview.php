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
 * Course catalogue preview fallback page (F16 task201).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\catalogue_return;
use local_nextpool\local\item_manager;
use local_nextpool\output\item_preview;

require_once(__DIR__ . '/../../config.php');
$courseid = required_param('courseid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);
$chapter = optional_param('chapter', 0, PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/nextpool:viewcatalogue', $context);
$filters = catalogue_return::read();
$PAGE->set_url(new moodle_url('/local/nextpool/preview.php', compact('courseid', 'itemid') + $filters));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading($course->fullname);
$item = $DB->get_record(item_manager::TABLE, ['id' => $itemid]);
if (!$item || !access::can_preview($item, $courseid)) {
    $PAGE->set_title(get_string('previewunavailable', 'local_nextpool'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('previewunavailable', 'local_nextpool'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    die;
}
$PAGE->set_title(format_string($item->title));
$PAGE->navbar->add(get_string('coursecatalogue', 'local_nextpool'), catalogue_return::url($courseid, $itemid, $filters));
$PAGE->navbar->add(format_string($item->title));
$output = $PAGE->get_renderer('core');
$data = item_preview::data($item, $courseid, $output, $filters, $chapter);
if ($chapter === 0) {
    item_preview::log($item, $courseid, 'page');
}
$PAGE->requires->js_call_amd('local_nextpool/item_dialog', 'initPreviewPage');
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($item->title));
echo $OUTPUT->render_from_template('local_nextpool/item_dialog_header', $data);
echo $OUTPUT->render_from_template('local_nextpool/item_preview', $data);
echo html_writer::link(
    new moodle_url($data['feedbackurl']),
    get_string('feedback', 'local_nextpool'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url($data['returnurl']),
    get_string('backtocatalogue', 'local_nextpool'),
    ['class' => 'btn btn-link']
);
echo $OUTPUT->footer();
