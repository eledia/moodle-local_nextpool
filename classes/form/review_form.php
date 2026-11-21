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
 * Review decision on a version in review: publish, return for revision or reject (F07, task80).
 *
 * One labelled button per decision, publishing first. The reason is required for returning and rejecting
 * (F07.AC04) and checked on the server; the change log can be adjusted before publishing.
 *
 * Custom data: 'versionid' (int), 'publishlabel' (string, plain), 'sources' (array id => name of writable storage
 * locations, only for a new item, q75), 'librarychanges' (string[]|null, only for H5P, q39), 'comments' (array
 * id => label of open comments the version takes up; marked as done on publishing if left ticked, task82).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $sources = (array) ($this->_customdata['sources'] ?? []);
        $librarychanges = $this->_customdata['librarychanges'] ?? null;

        $mform->addElement('hidden', 'versionid', (int) $this->_customdata['versionid']);
        $mform->setType('versionid', PARAM_INT);

        $mform->addElement('editor', 'changelog_editor', get_string('changelog', 'local_nextpool'), null, ['maxfiles' => 0]);
        $mform->setType('changelog_editor', PARAM_RAW);
        $mform->addHelpButton('changelog_editor', 'reviewchangelog', 'local_nextpool');

        $comments = (array) ($this->_customdata['comments'] ?? []);
        $first = true;
        foreach ($comments as $commentid => $label) {
            // Comments the proposal takes up are marked as done with publishing, unless the reviewer clears them.
            $name = 'resolvecomments[' . (int) $commentid . ']';
            $mform->addElement(
                'advcheckbox',
                $name,
                $first ? get_string('reviewresolvecomments', 'local_nextpool') : '',
                s($label)
            );
            $mform->setType($name, PARAM_BOOL);
            $mform->setDefault($name, 1);
            $first = false;
        }

        if ($sources) {
            // A proposed new item gets its storage location when it is published (q75).
            $mform->addElement('select', 'sourceid', get_string('reviewsource', 'local_nextpool'), array_map(
                static fn($name) => format_string($name),
                $sources
            ));
            $mform->setType('sourceid', PARAM_INT);
            $mform->addHelpButton('sourceid', 'reviewsource', 'local_nextpool');
            $mform->addElement('text', 'folder', get_string('reviewfolder', 'local_nextpool'), ['size' => 40]);
            $mform->setType('folder', PARAM_PATH);
            $mform->addHelpButton('folder', 'reviewfolder', 'local_nextpool');
        }

        if (is_array($librarychanges)) {
            if ($librarychanges) {
                $mform->addElement('html', \html_writer::div(
                    get_string('h5plibrarychanges', 'local_nextpool') . \html_writer::alist(array_map('s', $librarychanges)),
                    'alert alert-warning'
                ));
            }
            $mform->addElement('advcheckbox', 'h5plibraries', get_string('h5plibraries', 'local_nextpool'));
            $mform->addHelpButton('h5plibraries', 'h5plibraries', 'local_nextpool');
            $mform->setDefault('h5plibraries', 0);
        }

        $mform->addElement('textarea', 'reason', get_string('reviewreason', 'local_nextpool'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addHelpButton('reason', 'reviewreason', 'local_nextpool');

        $buttons = [
            $mform->createElement('submit', 'publish', (string) $this->_customdata['publishlabel']),
            $mform->createElement('submit', 'returnforrevision', get_string('reviewreturn', 'local_nextpool'), null, false),
            $mform->createElement('submit', 'reject', get_string('reviewreject', 'local_nextpool'), null, false),
            $mform->createElement('cancel'),
        ];
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $needsreason = !empty($data['returnforrevision']) || !empty($data['reject']);
        if ($needsreason && trim((string) ($data['reason'] ?? '')) === '') {
            $errors['reason'] = get_string('error_reasonrequired', 'local_nextpool');
        }
        if (array_key_exists('folder', $data)) {
            try {
                \local_nextpool\local\source\path::normalise((string) $data['folder']);
            } catch (\moodle_exception $e) {
                $errors['folder'] = $e->getMessage();
            }
        }
        return $errors;
    }

    /**
     * The decision the reviewer chose.
     *
     * @param \stdClass $data Submitted data.
     * @return string status::PUBLISHED, status::DRAFT or status::REJECTED.
     */
    public static function get_decision(\stdClass $data): string {
        return match (true) {
            !empty($data->returnforrevision) => \local_nextpool\local\status::DRAFT,
            !empty($data->reject) => \local_nextpool\local\status::REJECTED,
            default => \local_nextpool\local\status::PUBLISHED,
        };
    }
}
