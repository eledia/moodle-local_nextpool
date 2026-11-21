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
 * Compare two versions of a catalogue item (F02, metadata only in R1).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$fromid = required_param('from', PARAM_INT);
$toid = required_param('to', PARAM_INT);

// Not admin_externalpage_setup(): editors may hold their capabilities in a course category only.
require_login();
$pageurl = new moodle_url('/local/nextpool/compare.php', ['id' => $id, 'from' => $fromid, 'to' => $toid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = item_manager::get($id);
access::require_view($item);
$context = access::get_context($item);
foreach ([$fromid, $toid] as $versionid) {
    if ((int) version_manager::get($versionid)->itemid !== $id) {
        throw new moodle_exception('invalidrecord', 'error', '', version_manager::TABLE);
    }
}
$comparison = version_manager::compare($fromid, $toid);
$from = $comparison['from'];
$to = $comparison['to'];

$itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $id]);
// A proposal has no number yet (q67): it is named by person and date (task80).
$title = $to->versionnr === null
    ? get_string('compareproposal', 'local_nextpool', [
        'from' => (int) $from->versionnr,
        'to' => review_manager::get_version_name($to),
    ])
    : get_string('compareversions', 'local_nextpool', ['from' => (int) $from->versionnr, 'to' => (int) $to->versionnr]);
editorial_navbar::add($PAGE, $item, $title);
$PAGE->set_title($title);

$format = static function (string $field, $value) use ($context): string {
    return match ($field) {
        'label' => $value !== null && $value !== '' ? format_string($value, true, ['context' => $context]) : '-',
        'status' => status::get_name((string) $value),
        'filesize' => display_size((int) $value),
        'contenthash' => s(substr((string) $value, 0, 12)),
        default => $value !== null && $value !== '' ? s((string) $value) : '-',
    };
};

$table = new html_table();
$table->head = [
    get_string('comparefield', 'local_nextpool'),
    s(review_manager::get_version_name($from)),
    s(review_manager::get_version_name($to)),
    get_string('comparechanged', 'local_nextpool'),
];
$table->attributes['class'] = 'generaltable';
foreach ($comparison['rows'] as $field => $row) {
    $table->data[] = [
        table_actions::row_header(get_string('comparefield_' . $field, 'local_nextpool')),
        $format($field, $row['from']),
        $format($field, $row['to']),
        $row['changed'] ? html_writer::tag('strong', get_string('yes')) : get_string('no'),
    ];
}

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
// Plain text with a person's name (bug122).
echo $OUTPUT->heading(s($title));
echo html_writer::tag('p', $comparison['rows']['contenthash']['changed']
    ? get_string('comparecontentchanged', 'local_nextpool')
    : get_string('comparecontentsame', 'local_nextpool'));
echo html_writer::table($table);

echo $OUTPUT->heading(get_string('comparechangelogs', 'local_nextpool', (int) $from->versionnr), 3);
foreach ($comparison['between'] as $version) {
    $changelog = $version->changelog !== null && trim($version->changelog) !== ''
        ? format_text($version->changelog, (int) $version->changelogformat, ['context' => $context])
        : html_writer::tag('p', get_string('nochangelog', 'local_nextpool'), ['class' => 'text-muted']);
    echo html_writer::tag('h4', get_string('versionnr', 'local_nextpool', (int) $version->versionnr), ['class' => 'h5']);
    echo $changelog;
}
echo html_writer::link($itemurl, get_string('back'), ['class' => 'btn btn-secondary mt-3']);
echo $OUTPUT->footer();
