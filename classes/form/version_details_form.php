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
 * Change label and change log of a version afterwards (task103).
 *
 * Custom data: 'versionid' (int), 'state' (int, see {@see state()}: the stored label and change log, for the edit
 * lock, t84-b).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version_details_form extends \moodleform {
    use edit_lock;

    #[\Override]
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'versionid', (int) $this->_customdata['versionid']);
        $mform->setType('versionid', PARAM_INT);
        $this->add_edit_lock('detailsstate', (int) $this->_customdata['state']);

        $mform->addElement('text', 'label', get_string('versionlabel', 'local_nextpool'), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('label', 'versionlabel', 'local_nextpool');

        $mform->addElement('editor', 'changelog_editor', get_string('changelog', 'local_nextpool'), null, ['maxfiles' => 0]);
        $mform->setType('changelog_editor', PARAM_RAW);
        $mform->addHelpButton('changelog_editor', 'changelog', 'local_nextpool');

        $this->add_action_buttons(true);
    }

    #[\Override]
    public function definition_after_data() {
        parent::definition_after_data();
        $this->refresh_edit_lock('detailsstate', (int) $this->_customdata['state']);
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $errors + $this->check_edit_lock($data, 'detailsstate', (int) $this->_customdata['state'], static function () {
            return get_string('error_editconflictdetails', 'local_nextpool');
        });
    }

    /**
     * Number that changes when label or change log of the version change.
     *
     * @param \stdClass $version Version record.
     * @return int
     */
    public static function state(\stdClass $version): int {
        return crc32((string) $version->label . "\n" . (string) $version->changelog);
    }
}
