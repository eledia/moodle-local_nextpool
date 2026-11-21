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

use local_nextpool\local\license_info;
use local_nextpool\local\proposal_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Proposal from a course: a new version, a new item, or a returned proposal changed and submitted again (F07, task82).
 *
 * The file is checked with the rules of the import and the description is required; both messages stand at their
 * field (F07.AC02). When the type can take an activity over (task83), the content comes either from the activity in
 * the course or from an uploaded file. One labelled button submits.
 *
 * Custom data: 'mode' (MODE_VERSION, MODE_ITEM or MODE_RESUBMIT), 'type' (item type, for the file check), 'itemid'
 * (0 for a new item), 'comments' (array id => label of open comments the proposal can take up), 'params' (hidden
 * page parameters, name => int), 'cm' (\cm_info to take over, null if none).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposal_form extends \moodleform {
    /** @var string A new version of an item. */
    public const MODE_VERSION = 'version';
    /** @var string A new item. */
    public const MODE_ITEM = 'item';
    /** @var string A returned proposal, changed and submitted again. */
    public const MODE_RESUBMIT = 'resubmit';
    /** @var string Content taken over from the activity (task83). */
    public const SOURCE_ACTIVITY = 'activity';
    /** @var string Content from an uploaded file. */
    public const SOURCE_UPLOAD = 'upload';

    #[\Override]
    protected function definition() {
        global $CFG;
        require_once($CFG->libdir . '/licenselib.php');
        $mform = $this->_form;
        $mode = (string) $this->_customdata['mode'];
        $comments = (array) ($this->_customdata['comments'] ?? []);

        foreach ((array) ($this->_customdata['params'] ?? []) as $name => $value) {
            $mform->addElement('hidden', $name, (int) $value);
            $mform->setType($name, PARAM_INT);
        }

        if ($mode === self::MODE_ITEM) {
            $mform->addElement('text', 'title', get_string('title', 'local_nextpool'), ['size' => 60]);
            $mform->setType('title', PARAM_TEXT);
            $mform->addRule('title', null, 'required', null, 'client');
            $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

            $mform->addElement('editor', 'description_editor', get_string('description'), ['rows' => 6], ['maxfiles' => 0]);
            $mform->setType('description_editor', PARAM_RAW);
            $mform->addRule('description_editor', null, 'required', null, 'server');

            $mform->addElement('select', 'license', get_string('license'), \license_manager::get_active_licenses_as_array());
            $mform->setType('license', PARAM_TEXT);
            // New items get "All rights reserved" unless the site sets another default (task169).
            $mform->setDefault('license', license_info::default_for_new());

            $mform->addElement('text', 'author', get_string('proposalauthor', 'local_nextpool'), ['size' => 60]);
            $mform->setType('author', PARAM_TEXT);
            $mform->addRule('author', null, 'required', null, 'client');
        }

        $canextract = $this->can_extract();
        if ($canextract) {
            $sources = [
                $mform->createElement(
                    'radio',
                    'contentsource',
                    '',
                    get_string('proposalsource_activity', 'local_nextpool'),
                    self::SOURCE_ACTIVITY
                ),
                $mform->createElement(
                    'radio',
                    'contentsource',
                    '',
                    get_string('proposalsource_upload', 'local_nextpool'),
                    self::SOURCE_UPLOAD
                ),
            ];
            $mform->addGroup($sources, 'contentsourcegroup', get_string('proposalsource', 'local_nextpool'), '<br>', false);
            $mform->addHelpButton('contentsourcegroup', 'proposalsource', 'local_nextpool');
            $mform->setType('contentsource', PARAM_ALPHA);
            // A returned proposal keeps its file unless something new is given (t83-f).
            $mform->setDefault('contentsource', $mode === self::MODE_RESUBMIT ? self::SOURCE_UPLOAD : self::SOURCE_ACTIVITY);
        }

        $mform->addElement('filepicker', 'proposalfile', get_string('proposalfile', 'local_nextpool'), null, [
            'accepted_types' => '*',
        ]);
        $mform->addHelpButton('proposalfile', 'proposalfile', 'local_nextpool');
        if ($canextract) {
            // Required only for an upload; checked on the server, so taking over needs no file.
            $mform->hideIf('proposalfile', 'contentsource', 'eq', self::SOURCE_ACTIVITY);
        } else if ($mode !== self::MODE_RESUBMIT) {
            $mform->addRule('proposalfile', null, 'required', null, 'client');
        }

        $mform->addElement('editor', 'changelog_editor', get_string('proposaldescription', 'local_nextpool'), ['rows' => 4], [
            'maxfiles' => 0,
        ]);
        $mform->setType('changelog_editor', PARAM_RAW);
        // Checked on the server, so the message names what is missing (F07.AC02).
        $required = get_string('proposaldescriptionrequired', 'local_nextpool');
        $mform->addRule('changelog_editor', $required, 'required', null, 'server');
        $mform->addHelpButton('changelog_editor', 'proposaldescription', 'local_nextpool');

        if ($comments) {
            // The labels are plain text with the commenter's words; core prints option texts as HTML (bug120).
            $options = array_map('s', $comments);
            $mform->addElement('autocomplete', 'commentids', get_string('proposalcomments', 'local_nextpool'), $options, [
                'multiple' => true,
                'noselectionstring' => get_string('proposalnocomments', 'local_nextpool'),
            ]);
            $mform->setType('commentids', PARAM_INT);
            $mform->addHelpButton('commentids', 'proposalcomments', 'local_nextpool');
        }

        $submit = $mode === self::MODE_RESUBMIT ? 'proposalresubmit' : 'proposalsubmit';
        $this->add_action_buttons(true, get_string($submit, 'local_nextpool'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $mode = (string) $this->_customdata['mode'];
        if ($mode === self::MODE_ITEM) {
            if (trim((string) ($data['title'] ?? '')) === '') {
                $errors['title'] = get_string('required');
            }
            if (trim(html_to_text((string) ($data['description_editor']['text'] ?? ''), 0, false)) === '') {
                $errors['description_editor'] = get_string('required');
            }
            if (trim((string) ($data['author'] ?? '')) === '') {
                $errors['author'] = get_string('required');
            }
        }
        if (trim(html_to_text((string) ($data['changelog_editor']['text'] ?? ''), 0, false)) === '') {
            $errors['changelog_editor'] = get_string('proposaldescriptionrequired', 'local_nextpool');
        }
        if (self::get_source($data, $this->can_extract()) === self::SOURCE_ACTIVITY) {
            try {
                proposal_manager::extract(
                    $this->_customdata['cm'],
                    (string) $this->_customdata['type'],
                    (int) ($this->_customdata['itemid'] ?? 0)
                );
            } catch (\moodle_exception $e) {
                $errors['contentsourcegroup'] = $e->getMessage();
            }
            return $errors;
        }
        $file = self::get_draft_file((int) ($data['proposalfile'] ?? 0));
        if ($file === null) {
            if ($mode !== self::MODE_RESUBMIT) {
                $errors['proposalfile'] = get_string('required');
            }
            return $errors;
        }
        $localfile = make_request_directory() . '/proposal';
        $file->copy_content_to($localfile);
        try {
            proposal_manager::check_file(
                (string) $this->_customdata['type'],
                $localfile,
                $file->get_filename(),
                (int) ($this->_customdata['itemid'] ?? 0)
            );
        } catch (\moodle_exception $e) {
            $errors['proposalfile'] = $e->getMessage();
        }
        return $errors;
    }

    /**
     * Whether the form offers to take the activity over (task83).
     *
     * @return bool
     */
    private function can_extract(): bool {
        $cm = $this->_customdata['cm'] ?? null;
        return $cm instanceof \cm_info && proposal_manager::can_extract($cm, (string) $this->_customdata['type']);
    }

    /**
     * Where the content of the submitted proposal comes from.
     *
     * @param array|\stdClass $data Submitted data.
     * @param bool $canextract Whether the form offers to take the activity over.
     * @return string SOURCE_ACTIVITY or SOURCE_UPLOAD.
     */
    public static function get_source($data, bool $canextract): string {
        $source = ((array) $data)['contentsource'] ?? self::SOURCE_UPLOAD;
        return $canextract && $source === self::SOURCE_ACTIVITY ? self::SOURCE_ACTIVITY : self::SOURCE_UPLOAD;
    }

    /**
     * The file chosen in the draft area of the current user, null if none.
     *
     * @param int $draftitemid Draft item id of the file picker.
     * @return \stored_file|null
     */
    public static function get_draft_file(int $draftitemid): ?\stored_file {
        global $USER;
        if ($draftitemid <= 0) {
            return null;
        }
        $context = \context_user::instance((int) $USER->id);
        $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id DESC', false);
        return $files ? reset($files) : null;
    }
}
