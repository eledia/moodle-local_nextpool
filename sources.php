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
 * Manage storage backend instances (F03).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nextpool\form\source_form;
use local_nextpool\form\upload_source_form;
use local_nextpool\local\direct_upload;
use local_nextpool\local\source\manager;
use local_nextpool\local\status;
use local_nextpool\output\area_nav;
use local_nextpool\output\table_actions;

require_once(__DIR__ . '/../../config.php');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$plugin = optional_param('plugin', '', PARAM_PLUGIN);

require_login();
$baseurl = new moodle_url('/local/nextpool/sources.php');
area_nav::setup_system_page($PAGE, $baseurl, area_nav::SOURCES, get_string('sources', 'local_nextpool'));

if ($action === 'add' || $action === 'edit') {
    if ($action === 'edit') {
        $record = $DB->get_record(manager::TABLE, ['id' => $id], '*', MUST_EXIST);
        $plugin = $record->plugin;
    }
    $class = manager::get_class($plugin);
    if ($class === null) {
        throw new moodle_exception('error_sourcemissing', 'local_nextpool', $baseurl, $plugin);
    }
    $existing = $action === 'edit' ? manager::get_stored_config($id) : [];
    $form = new source_form(
        new moodle_url($baseurl, ['action' => $action, 'id' => $id, 'plugin' => $plugin]),
        ['plugin' => $plugin, 'class' => $class, 'existing' => $existing]
    );
    if ($action === 'edit') {
        $form->set_data(['id' => $id, 'plugin' => $plugin, 'name' => $record->name, 'enabled' => $record->enabled]
            + manager::get_form_config($id));
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        $config = (array) $data;
        if ($action === 'edit') {
            manager::update($id, $data->name, $config, !empty($data->enabled));
        } else {
            manager::create($plugin, $data->name, $config, !empty($data->enabled));
        }
        redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo area_nav::editorial(area_nav::SOURCES, true);
    $heading = get_string(
        $action === 'edit' ? 'editsource' : 'addsource',
        'local_nextpool',
        get_string('pluginname', 'nextpoolsource_' . $plugin)
    );
    echo $OUTPUT->heading($heading);
    $form->display();
    echo $OUTPUT->footer();
    die();
}

if ($action !== '' && $id) {
    // Changes come as POST from the buttons in the table (bug66).
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($baseurl);
    }
    require_sesskey();
    switch ($action) {
        case 'enable':
        case 'disable':
            manager::set_enabled($id, $action === 'enable');
            $name = format_string($DB->get_field(manager::TABLE, 'name', ['id' => $id], MUST_EXIST));
            redirect(
                $baseurl,
                get_string($action === 'enable' ? 'sourceenabled' : 'sourcedisabled', 'local_nextpool', $name),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
        case 'test':
            try {
                manager::get($id, false)->test_connection();
                $message = get_string('testconnection_ok', 'local_nextpool');
                $type = \core\output\notification::NOTIFY_SUCCESS;
            } catch (\moodle_exception $e) {
                $message = get_string('testconnection_failed', 'local_nextpool', $e->getMessage());
                $type = \core\output\notification::NOTIFY_ERROR;
            }
            redirect($baseurl, $message, null, $type);
            break;
        case 'delete':
            if (!optional_param('confirm', 0, PARAM_BOOL)) {
                $record = $DB->get_record(manager::TABLE, ['id' => $id], '*', MUST_EXIST);
                echo $OUTPUT->header();
                echo area_nav::editorial(area_nav::SOURCES, true);
                echo $OUTPUT->confirm(
                    get_string('deletesourceconfirm', 'local_nextpool', format_string($record->name)),
                    new single_button(
                        new moodle_url($baseurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
                        get_string('delete'),
                        'post',
                        single_button::BUTTON_DANGER
                    ),
                    $baseurl
                );
                echo $OUTPUT->footer();
                die();
            }
            manager::delete($id);
            redirect($baseurl, get_string('sourcedeleted', 'local_nextpool'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Storage location for direct uploads from courses (M06, TD-404), chosen below the list.
$writable = direct_upload::get_writable_sources();
$uploadform = null;
if ($writable) {
    $uploadform = new upload_source_form($baseurl, ['sources' => $writable]);
    $uploadform->set_data(['uploadsourceid' => direct_upload::get_sourceid() ?: array_key_first($writable)]);
    if ($data = $uploadform->get_data()) {
        direct_upload::set_sourceid((int) $data->uploadsourceid);
        redirect($baseurl, get_string('uploadsourcesaved', 'local_nextpool'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}
$uploadsourceid = direct_upload::get_sourceid();

echo $OUTPUT->header();
echo area_nav::editorial(area_nav::SOURCES);
echo area_nav::heading(get_string('sources', 'local_nextpool'));
echo html_writer::tag('p', get_string('sources_desc', 'local_nextpool'));

$plugins = manager::get_available_plugins();
$records = manager::get_records();
if ($records) {
    $table = new html_table();
    $table->head = [
        get_string('sourcename', 'local_nextpool'),
        get_string('sourcetype', 'local_nextpool'),
        get_string('status'),
        get_string('actions'),
    ];
    foreach ($records as $record) {
        $rowname = format_string($record->name, true, ['escape' => false]);
        $actionurl = static fn(string $action): moodle_url => new moodle_url($baseurl, ['action' => $action, 'id' => $record->id]);
        $toggle = $record->enabled ? 'disable' : 'enable';
        $actions = table_actions::link($actionurl('edit'), get_string('edit'), $rowname) . ' '
            . table_actions::button($actionurl($toggle), get_string($toggle), $rowname)
            . table_actions::button($actionurl('test'), get_string('testconnection', 'local_nextpool'), $rowname);
        if (manager::can_delete($record->id)) {
            $actions .= table_actions::button($actionurl('delete'), get_string('delete'), $rowname, single_button::BUTTON_DANGER);
        }
        // Facts such as the expiry date of an app secret, and warnings to act on (task73).
        $type = $plugins[$record->plugin] ?? s($record->plugin);
        $state = $record->enabled ? get_string('enabled', 'local_nextpool') : get_string('disabled', 'local_nextpool');
        if ((int) $record->id === $uploadsourceid) {
            $state .= html_writer::div(
                status::text_badge(get_string('uploadsourcebadge', 'local_nextpool'), 'bg-light text-dark border')
            );
        }
        try {
            $source = manager::get((int) $record->id, false);
            $summary = $source->get_summary();
            if ($summary !== '') {
                $type .= html_writer::div(s($summary), 'small text-muted');
            }
            foreach ($source->get_status_warnings() as $warning) {
                $state .= html_writer::div(status::text_badge($warning, 'bg-warning text-dark'));
            }
        } catch (moodle_exception $e) {
            // A missing backend plugin shows its name only.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
        $table->data[] = [
            table_actions::row_header(format_string($record->name)),
            $type,
            $state,
            $actions,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nosources', 'local_nextpool'), \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->heading(get_string('uploadsource', 'local_nextpool'), 3);
if ($uploadsourceid === 0) {
    $nouploadkey = direct_upload::get_configured_sourceid() ? 'uploadsourceunavailable'
        : ($writable ? 'uploadsourcechoose' : 'nouploadsource');
    echo $OUTPUT->notification(get_string($nouploadkey, 'local_nextpool'), \core\output\notification::NOTIFY_WARNING);
}
if ($uploadform) {
    $uploadform->display();
}

if ($plugins) {
    $select = new single_select(
        new moodle_url($baseurl, ['action' => 'add']),
        'plugin',
        $plugins,
        '',
        ['' => get_string('choosedots')]
    );
    $select->set_label(get_string('addsourcelabel', 'local_nextpool'));
    echo $OUTPUT->render($select);
}

echo $OUTPUT->footer();
