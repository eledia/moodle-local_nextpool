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

use local_nextpool\local\content_type\base;
use local_nextpool\local\policy;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Place a catalogue item in a course: section, update policy and the fields of the item's type (F05, task45).
 *
 * Custom data: 'courseid', 'itemid', 'sections' (array<int,string> section number => name),
 * 'handler' (content_type\base of the item's type, optional), which may add its own fields.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class place_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid', (int) $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'itemid', (int) $this->_customdata['itemid']);
        $mform->setType('itemid', PARAM_INT);

        $mform->addElement('select', 'section', get_string('section'), $this->_customdata['sections']);
        $mform->setType('section', PARAM_INT);

        $mform->addElement('select', 'policy', get_string('policy', 'local_nextpool'), policy::get_menu());
        $mform->setType('policy', PARAM_ALPHA);
        $mform->setDefault('policy', policy::NOTIFY);
        $mform->addHelpButton('policy', 'policy', 'local_nextpool');

        $handler = $this->_customdata['handler'] ?? null;
        if ($handler instanceof base) {
            $handler->extend_place_form($mform);
        }

        $this->add_action_buttons(true, get_string('placeincourse', 'local_nextpool'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!array_key_exists((int) $data['section'], $this->_customdata['sections'])) {
            $errors['section'] = get_string('invalidsection', 'error');
        }
        if (!policy::is_valid((string) $data['policy'])) {
            $errors['policy'] = get_string('error_invalidpolicy', 'local_nextpool');
        }
        return $errors;
    }
}
