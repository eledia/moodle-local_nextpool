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
 * Upload a file into the current folder of a storage location (F03 write).
 *
 * Custom data: 'id' (item id), 'path' (folder relative to the base folder), 'latestversionid' (id of the latest
 * numbered version, 0 for none: the page refuses the import if another version arrived meanwhile, t84-b, t103),
 * 'replace' (optional, true for item editors: the upload replaces the file of the item, bug102), 'accepted_types'
 * (optional, file types of the item's content type for the file picker, default any file, bug206).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {
    use edit_lock;

    #[\Override]
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', (int) $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'path', (string) $this->_customdata['path']);
        $mform->setType('path', PARAM_RAW);
        $mform->addElement('hidden', 'upload', 1);
        $mform->setType('upload', PARAM_BOOL);
        $this->add_edit_lock('latestversionid', (int) $this->_customdata['latestversionid']);

        // Not called 'file': that is the page parameter for the chosen file.
        $label = empty($this->_customdata['replace']) ? 'uploadfile' : 'uploadreplace';
        $mform->addElement('filepicker', 'uploadfile', get_string($label, 'local_nextpool'), null, [
            'accepted_types' => $this->_customdata['accepted_types'] ?? '*',
        ]);
        $mform->addRule('uploadfile', null, 'required', null, 'client');
        $mform->addHelpButton('uploadfile', $label, 'local_nextpool');

        $this->add_action_buttons(false, get_string('upload'));
    }

    #[\Override]
    public function definition_after_data() {
        parent::definition_after_data();
        $this->refresh_edit_lock('latestversionid', (int) $this->_customdata['latestversionid']);
    }
}
