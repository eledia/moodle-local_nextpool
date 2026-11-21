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
 * Change the text of an own comment (F08, task81).
 *
 * Custom data: 'itemid', 'courseid' and 'commentid' (int).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comment_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        foreach (['itemid', 'courseid', 'commentid'] as $name) {
            $mform->addElement('hidden', $name, (int) ($this->_customdata[$name] ?? 0));
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('textarea', 'content', get_string('commenttext', 'local_nextpool'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('content', PARAM_RAW);
        $mform->addRule('content', get_string('error_commentempty', 'local_nextpool'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files) {
        return parent::validation($data, $files) + self::validate_content($data);
    }

    /**
     * Reject a comment text that is empty or only white space; the item dialog uses the same rule (task173).
     *
     * @param array $data Submitted data with 'content'.
     * @return array Errors keyed by element name.
     */
    public static function validate_content(array $data): array {
        if (trim(html_to_text((string) ($data['content'] ?? ''), 0, false)) === '') {
            return ['content' => get_string('error_commentempty', 'local_nextpool')];
        }
        return [];
    }
}
