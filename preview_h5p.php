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
 * Catalogue H5P preview with no component, statements, state or activity attempts (F16 p176-p).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\item_manager;

require_once(__DIR__ . '/../../config.php');
$itemid = required_param('itemid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$versionid = required_param('versionid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$item = item_manager::get($itemid);
access::require_preview($item, $courseid);
$PAGE->set_url(new moodle_url('/local/nextpool/preview_h5p.php', compact('itemid', 'courseid', 'versionid')));
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('preview', 'local_nextpool'));
$PAGE->set_heading($item->title);
if ($item->type !== 'h5p' || (int) $item->currentversionid !== $versionid) {
    throw new moodle_exception('previewunavailable', 'local_nextpool');
}
$started = false;
try {
    $preview = \local_nextpool\local\h5p_preview::prepare($versionid, $courseid);
    \local_nextpool\local\h5p_preview::add_assets_to_page($preview);
    echo $OUTPUT->header();
    $started = true;
    echo \local_nextpool\local\h5p_preview::render($preview);
} catch (moodle_exception $e) {
    if (!$started) {
        echo $OUTPUT->header();
    }
    echo $OUTPUT->notification(get_string('previewunavailable', 'local_nextpool'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
