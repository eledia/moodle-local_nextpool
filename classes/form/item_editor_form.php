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
 * Add an item editor (F08, task84, q68), on its own page linked from the item page (t84-k).
 *
 * Any account of the site can be chosen; the search is local_nextpool_search_users, which needs the right to
 * edit the item. Custom data: 'id' (item id).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_editor_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', (int) $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('autocomplete', 'editoruserid', get_string('itemeditoradd', 'local_nextpool'), [], [
            'ajax' => 'local_nextpool/user_selector',
            'multiple' => false,
            'noselectionstring' => get_string('itemeditornone', 'local_nextpool'),
        ]);
        $mform->setType('editoruserid', PARAM_INT);
        $mform->addRule('editoruserid', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('add'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['editoruserid'])) {
            $errors['editoruserid'] = get_string('required');
        }
        return $errors;
    }
}
