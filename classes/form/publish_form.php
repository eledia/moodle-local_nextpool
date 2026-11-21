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

namespace local_nextpool\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Publish an H5P version, with the explicit option to update H5P libraries (q39).
 *
 * Custom data: 'id' (item id), 'versionid', 'versionnr' (int) and 'librarychanges'
 * (string[], libraries the package would install or update, see h5p::get_library_changes()).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $changes = (array) ($this->_customdata['librarychanges'] ?? []);

        foreach (['id' => $this->_customdata['id'], 'versionid' => $this->_customdata['versionid']] as $name => $value) {
            $mform->addElement('hidden', $name, (int) $value);
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement('hidden', 'action', 'versionstatus');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'to', \local_nextpool\local\status::PUBLISHED);
        $mform->setType('to', PARAM_ALPHA);
        $mform->addElement('hidden', 'confirm', 1);
        $mform->setType('confirm', PARAM_BOOL);

        if ($changes) {
            $list = \html_writer::alist(array_map('s', $changes));
            $mform->addElement('html', \html_writer::div(
                get_string('h5plibrarychanges', 'local_nextpool') . $list,
                'alert alert-warning'
            ));
        } else {
            $mform->addElement('html', \html_writer::tag('p', get_string('h5plibrarynochanges', 'local_nextpool')));
        }

        $mform->addElement('advcheckbox', 'h5plibraries', get_string('h5plibraries', 'local_nextpool'));
        $mform->addHelpButton('h5plibraries', 'h5plibraries', 'local_nextpool');
        $mform->setDefault('h5plibraries', 0);

        $this->add_action_buttons(
            true,
            get_string('versiontransition_published', 'local_nextpool', (int) $this->_customdata['versionnr'])
        );
    }
}
