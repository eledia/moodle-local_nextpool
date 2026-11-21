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

use local_nextpool\local\item_types;
use local_nextpool\local\version_manager;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * A new version of a course-bound non-xAPI upload (M08, task194).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_version_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $handler = item_types::get_handler($this->_customdata['item']->type);
        $extensions = $handler->get_file_extensions();
        $mform->addElement('filepicker', 'package', get_string('file'), null, [
            'accepted_types' => $extensions ? array_map(fn($ext) => '.' . $ext, $extensions) : '*',
            'maxbytes' => $this->_customdata['maxbytes'],
        ]);
        $mform->addRule('package', null, 'required', null, 'client');
        $mform->addElement('static', 'expected', '', s($handler->get_expected_file()));
        $mform->addElement(
            'editor',
            'changelog_editor',
            get_string('changelog', 'local_nextpool'),
            null,
            ['maxfiles' => 0, 'context' => $this->_customdata['context']]
        );
        $mform->setType('changelog_editor', PARAM_RAW);
        if ($this->_customdata['changed']) {
            $mform->addElement('static', 'changed', '', get_string('uploadreplacechanged', 'local_nextpool'));
        }
        $this->add_action_buttons(true, get_string('uploadnewversion', 'local_nextpool'));
    }

    /**
     * File selected by the current user, never another user's draft.
     *
     * @param int $draftid Draft item id.
     * @return \stored_file|null
     */
    public static function get_file(int $draftid): ?\stored_file {
        global $USER;
        if ($draftid <= 0) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftid,
            'id DESC',
            false
        );
        return $files ? reset($files) : null;
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (
            $this->_customdata['item']->type === 'h5p'
                && !has_capability('moodle/h5p:deploy', $this->_customdata['context'])
        ) {
            $errors['package'] = get_string('uploadh5pforbidden', 'local_nextpool');
            return $errors;
        }
        $file = self::get_file((int) ($data['package'] ?? 0));
        if ($file === null) {
            $errors['package'] = get_string('required');
            return $errors;
        }
        $item = $this->_customdata['item'];
        $path = make_request_directory() . '/upload';
        $file->copy_content_to($path);
        try {
            $handler = item_types::get_handler($item->type);
            $handler->validate_package($path);
            $metadata = $handler->read_upload_metadata($path, $file->get_filename());
            if (
                !empty($metadata['missinglibraries'])
                    && !has_capability('moodle/h5p:updatelibraries', \context_system::instance())
            ) {
                $errors['package'] = get_string(
                    'cannotplace_h5plibraries',
                    'local_nextpool',
                    implode(', ', $metadata['missinglibraries'])
                );
            }
            $latest = version_manager::get_latest((int) $item->id);
            if ($latest && $latest->contenthash === $file->get_contenthash() && $latest->filename === $file->get_filename()) {
                $errors['package'] = get_string('error_unchanged', 'local_nextpool');
            }
            \local_nextpool\local\source\manager::get((int) $item->sourceid)->test_connection();
        } catch (\moodle_exception $e) {
            $errors['package'] = $e->getMessage();
        }
        return $errors;
    }
}
