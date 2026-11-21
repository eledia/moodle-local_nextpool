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
 * Assign a review to a reviewer of the item, or clear the assignment (F07.AC13, q72, task85).
 *
 * The list holds only people who may review the item ({@see \local_nextpool\local\access::get_reviewers()}); the
 * page checks the choice again on the server. A plain select, not an autocomplete: the list is short and the core
 * autocomplete fails the accessibility checks (t84-k).
 *
 * Custom data: 'versionid', 'reviewers' (user id => full name, plain text).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_assign_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'action', 'assign');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'versionid', (int) $this->_customdata['versionid']);
        $mform->setType('versionid', PARAM_INT);

        // Full names are plain text; core prints option texts as HTML (bug122).
        $options = [0 => get_string('reviewnotassigned', 'local_nextpool')] + array_map('s', $this->_customdata['reviewers']);
        $mform->addElement('select', 'assignedto', get_string('reviewassignedto', 'local_nextpool'), $options);
        $mform->setType('assignedto', PARAM_INT);
        $mform->addHelpButton('assignedto', 'reviewassignedto', 'local_nextpool');

        $this->add_action_buttons(true, get_string('reviewassign', 'local_nextpool'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $assignee = (int) ($data['assignedto'] ?? 0);
        if ($assignee && !array_key_exists($assignee, $this->_customdata['reviewers'])) {
            $errors['assignedto'] = get_string('error_reviewassignee', 'local_nextpool');
        }
        return $errors;
    }
}
