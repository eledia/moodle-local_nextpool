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

use local_nextpool\local\direct_upload;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Choose the storage location for direct uploads from courses (M06, TD-404).
 *
 * Custom data: 'sources' (id => name of enabled storage locations that can be written to).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_source_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $choices = array_map(
            static fn(string $name): string => format_string($name, true, ['escape' => false]),
            (array) $this->_customdata['sources']
        );

        $mform->addElement('select', 'uploadsourceid', get_string('uploadsource', 'local_nextpool'), $choices);
        $mform->setType('uploadsourceid', PARAM_INT);
        $mform->addHelpButton('uploadsourceid', 'uploadsource', 'local_nextpool');
        $mform->addRule('uploadsourceid', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!array_key_exists((int) ($data['uploadsourceid'] ?? 0), direct_upload::get_writable_sources())) {
            $errors['uploadsourceid'] = get_string('error_uploadsourceinvalid', 'local_nextpool');
        }
        return $errors;
    }
}
