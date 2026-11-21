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
 * Let the AI propose competencies for a catalogue item (F14).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\form\competency_suggestion_form;
use local_nextpool\local\access;
use local_nextpool\local\competency_suggester;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\output\area_nav;
use local_nextpool\output\editorial_navbar;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$run = optional_param('run', 0, PARAM_BOOL);

// Not admin_externalpage_setup(): editors may hold their capabilities in a course category only.
require_login();
$pageurl = new moodle_url('/local/nextpool/suggest.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(format_string($SITE->fullname));

$item = item_manager::get($id);
access::require_edit($item);
$context = access::get_context($item);
$itemurl = new moodle_url('/local/nextpool/item.php', ['id' => $id]);
$title = get_string('competencysuggest', 'local_nextpool');
editorial_navbar::add($PAGE, $item, $title);
$PAGE->set_title($title);

$vocabulary = competency_suggester::vocabulary();
$existing = array_map(static fn($r) => $r->competency, item_competencies::get($id));
$entries = [];
$submitted = optional_param('suggestions', '', PARAM_RAW);
if ($submitted !== '') {
    // Proposals come back from the browser; validate them again against the site's competencies.
    $entries = competency_suggester::parse('{"competencies":' . $submitted . '}', $vocabulary);
} else if ($run) {
    require_sesskey();
    // Consent is its own checkbox, separate from starting the proposal (bug69).
    if (!\core_ai\manager::get_user_policy_status((int) $USER->id)) {
        if (!optional_param('acceptpolicy', 0, PARAM_BOOL)) {
            redirect(
                $pageurl,
                get_string('competencysuggestpolicyrequired', 'local_nextpool'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        require_capability('moodle/ai:acceptpolicy', $context);
        \core_ai\manager::user_policy_accepted((int) $USER->id, $context->id);
    }
    $entries = competency_suggester::suggest($id);
    if ($entries === null) {
        redirect($itemurl, get_string('competencysuggestfailed', 'local_nextpool'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (!$entries) {
        redirect($itemurl, get_string('competencysuggestnone', 'local_nextpool'), null, \core\output\notification::NOTIFY_INFO);
    }
}

$form = null;
if ($entries) {
    $form = new competency_suggestion_form($pageurl, [
        'id' => $id,
        'entries' => $entries,
        'vocabulary' => $vocabulary,
        'existing' => $existing,
    ]);
    if ($form->is_cancelled()) {
        redirect($itemurl);
    } else if ($data = $form->get_data()) {
        $added = competency_suggester::apply($id, competency_suggestion_form::picked($data, $entries));
        redirect(
            $itemurl,
            get_string('competencysuggestadded', 'local_nextpool', count($added)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::ITEMS, true);
echo $OUTPUT->heading($title);
$vocabularysize = $vocabulary ? competency_suggester::count_vocabulary() : 0;
if ($vocabularysize > competency_suggester::VOCABULARY_LIMIT) {
    // Say so instead of cutting frameworks off silently (bug51).
    echo $OUTPUT->notification(get_string('competencysuggesttruncated', 'local_nextpool', (object) [
        'count' => $vocabularysize,
        'limit' => competency_suggester::VOCABULARY_LIMIT,
    ]), \core\output\notification::NOTIFY_INFO);
}
if ($form) {
    echo html_writer::tag('p', get_string('competencysuggestreview', 'local_nextpool'));
    $form->display();
} else if (!competency_suggester::is_available()) {
    echo $OUTPUT->notification(
        get_string('competencysuggestunavailable', 'local_nextpool'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->continue_button($itemurl);
} else if (!$vocabulary) {
    echo $OUTPUT->notification(
        get_string('competencysuggestnovocabulary', 'local_nextpool'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->continue_button($itemurl);
} else {
    echo html_writer::tag('p', get_string('competencysuggest_desc', 'local_nextpool'));
    $startform = html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $pageurl->out(false),
        'id' => 'local_nextpool-suggest',
    ]);
    foreach (['id' => $id, 'run' => 1, 'sesskey' => sesskey()] as $name => $value) {
        $startform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    if (!\core_ai\manager::get_user_policy_status((int) $USER->id)) {
        $startform .= html_writer::div(get_string('userpolicy', 'core_ai'), 'border rounded p-3 mb-3');
        $startform .= html_writer::div(
            html_writer::checkbox('acceptpolicy', 1, false, get_string('competencysuggestpolicy', 'local_nextpool'), [
                'id' => 'local_nextpool-acceptpolicy',
                'required' => 'required',
                'class' => 'form-check-input',
            ], ['class' => 'form-check-label']),
            'form-check mb-3'
        );
    }
    $startform .= html_writer::div(
        html_writer::tag('button', get_string('competencysuggest', 'local_nextpool'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]) . ' ' . html_writer::link($itemurl, get_string('cancel'), ['class' => 'btn btn-secondary']),
        'd-flex flex-wrap gap-2'
    );
    // Filled when the request starts: the answer of the AI can take a while (bug69).
    $startform .= html_writer::div('', 'mt-3', ['id' => 'local_nextpool-suggest-status', 'role' => 'status']);
    $startform .= html_writer::end_tag('form');
    echo $startform;
    $running = json_encode(get_string('competencysuggestrunning', 'local_nextpool'));
    $PAGE->requires->js_init_code(
        "document.getElementById('local_nextpool-suggest').addEventListener('submit', function(e) {" .
            "var button = e.target.querySelector('button[type=submit]');" .
            "button.disabled = true;" .
            "document.getElementById('local_nextpool-suggest-status').textContent = $running;" .
        "});"
    );
}
echo $OUTPUT->footer();
