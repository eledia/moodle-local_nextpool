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

use local_nextpool\local\source\base;
use local_nextpool\local\source\path;
use local_nextpool\local\source\source_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit a storage backend instance.
 *
 * Custom data: 'plugin' (string), 'class' (class-string of base), 'existing' (stored config, [] on create).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        /** @var class-string<base> $class */
        $class = $this->_customdata['class'];

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'plugin', $this->_customdata['plugin']);
        $mform->setType('plugin', PARAM_PLUGIN);

        $mform->addElement('text', 'name', get_string('sourcename', 'local_nextpool'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_nextpool'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'basefolder', get_string('basefolder', 'local_nextpool'), ['size' => 50]);
        $mform->setType('basefolder', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('basefolder', 'basefolder', 'local_nextpool');

        $class::add_config_elements($mform);

        $this->add_action_buttons();
    }

    #[\Override]
    public function definition_after_data() {
        parent::definition_after_data();
        /** @var class-string<base> $class */
        $class = $this->_customdata['class'];
        $class::definition_after_data($this->_form, (array) $this->_form->exportValues(), $this->_customdata['existing']);
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        /** @var class-string<base> $class */
        $class = $this->_customdata['class'];
        try {
            $basefolder = path::normalise((string) ($data['basefolder'] ?? ''));
            if ($basefolder === '' && $class::requires_basefolder()) {
                $errors['basefolder'] = get_string('error_basefolderrequired', 'local_nextpool');
            }
        } catch (source_exception $e) {
            $errors['basefolder'] = $e->getMessage();
        }
        return array_merge($errors, $class::validate_config($data, $this->_customdata['existing']));
    }
}
