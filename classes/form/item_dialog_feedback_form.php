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

use core_form\dynamic_form;
use local_nextpool\local\access;
use local_nextpool\local\comment_manager;
use local_nextpool\local\feedback_service;
use local_nextpool\local\item_manager;
use local_nextpool\output\feedback_section;

/**
 * New comment in the area "Feedback" of the item dialog in the catalogue browser (task173).
 *
 * The same form as "New comment" on feedback.php (text and what it is about, the same choice of versions from
 * {@see feedback_section::get_version_options()}, the same check of the text) and the same way to save
 * ({@see feedback_service::add()}). Access as on feedback.php: login in the course and {@see access::require_comment()}.
 *
 * Form data: 'itemid' and 'courseid' (int, 0 for the editorial view).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_dialog_feedback_form extends dynamic_form {
    /** @var \stdClass|null Item, loaded once. */
    protected ?\stdClass $item = null;

    /**
     * The item of the form.
     *
     * @return \stdClass
     */
    protected function get_item(): \stdClass {
        if ($this->item === null) {
            $this->item = item_manager::get($this->optional_param('itemid', 0, PARAM_INT));
        }
        return $this->item;
    }

    /**
     * Course the person comes from, 0 for the editorial view.
     *
     * @return int
     */
    protected function get_courseid(): int {
        return max(0, (int) $this->optional_param('courseid', 0, PARAM_INT));
    }

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'itemid');
        $mform->setType('itemid', PARAM_INT);
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'startarea');
        $mform->setType('startarea', PARAM_ALPHA);

        // Who can read comments, until the first own comment (q63), as on feedback.php.
        $describedby = [];
        if (!get_user_preferences(comment_manager::PREF_NOTICE, 0)) {
            $mform->addElement('html', \html_writer::tag(
                'p',
                get_string('commentnotice', 'local_nextpool'),
                ['class' => 'alert alert-info small py-2', 'id' => 'local-nextpool-itemdialog-notice']
            ));
            $describedby = ['aria-describedby' => 'local-nextpool-itemdialog-notice'];
        }
        $mform->addElement(
            'textarea',
            'content',
            get_string('commentnew', 'local_nextpool'),
            ['rows' => 3] + $describedby
        );
        $mform->setType('content', PARAM_RAW);
        $mform->addRule('content', get_string('error_commentempty', 'local_nextpool'), 'required', null, 'client');

        $options = [];
        $default = 0;
        $section = new feedback_section($this->get_item(), $this->get_courseid());
        foreach ($section->get_version_options() as $option) {
            $options[$option['value']] = $option['label'];
            if ($option['selected']) {
                $default = $option['value'];
            }
        }
        $mform->addElement('select', 'versionid', get_string('commentabout', 'local_nextpool'), $options);
        $mform->setType('versionid', PARAM_INT);
        if ($this->optional_param('startarea', '', PARAM_ALPHA) === 'preview') {
            $default = (int) $this->get_item()->currentversionid;
        }
        $mform->setDefault('versionid', $default);

        $this->add_action_buttons(false, get_string('commentpost', 'local_nextpool'));
    }

    #[\Override]
    public function validation($data, $files) {
        return parent::validation($data, $files) + comment_form::validate_content($data);
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): \context {
        $courseid = $this->get_courseid();
        return $courseid > 0 ? \context_course::instance($courseid) : \context_system::instance();
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        // As on feedback.php: the web service has checked the login in the context of the course; then the feedback rule.
        access::require_comment($this->get_item(), $this->get_courseid());
    }

    /**
     * Save the comment.
     *
     * @return array Comment id, item id, the new number of open comments and the message for the toast.
     */
    #[\Override]
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $itemid = (int) $this->get_item()->id;
        $comment = feedback_service::add($itemid, $this->get_courseid(), (string) $data->content, (int) $data->versionid);
        return [
            'commentid' => (int) $comment->id,
            'itemid' => $itemid,
            'opencount' => comment_manager::count_open([$itemid])[$itemid] ?? 0,
            'message' => get_string('commentposted', 'local_nextpool'),
        ];
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $this->set_data([
            'itemid' => (int) $this->get_item()->id,
            'courseid' => $this->get_courseid(),
            'startarea' => $this->optional_param('startarea', '', PARAM_ALPHA),
        ]);
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/local/nextpool/feedback.php', [
            'itemid' => $this->optional_param('itemid', 0, PARAM_INT),
            'courseid' => $this->get_courseid(),
        ]);
    }
}
