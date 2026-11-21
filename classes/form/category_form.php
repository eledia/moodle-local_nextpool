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

use local_nextpool\local\category_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit a catalogue category.
 *
 * Custom data: 'id' (int, 0 when adding).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $id = (int) ($this->_customdata['id'] ?? 0);

        $mform->addElement('hidden', 'id', $id);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('categoryname', 'local_nextpool'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $parents = [0 => get_string('categorytop', 'local_nextpool')] + category_manager::get_menu($id);
        $mform->addElement('select', 'parent', get_string('categoryparent', 'local_nextpool'), $parents);
        $mform->addHelpButton('parent', 'categoryparent', 'local_nextpool');
        $mform->setType('parent', PARAM_INT);

        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string) $data['name']) === '') {
            $errors['name'] = get_string('required');
        }
        return $errors;
    }
}
