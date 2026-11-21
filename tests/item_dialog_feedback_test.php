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

namespace local_nextpool;

use core_external\external_api;
use core_form\external\dynamic_form;
use local_nextpool\form\item_dialog_feedback_form;
use local_nextpool\local\comment_manager;
use local_nextpool\local\feedback_service;
use local_nextpool\output\item_dialog_feedback;

/**
 * Feedback from the item dialog of the catalogue browser: form, access, open comments (task173).
 *
 * The form goes through the core web service core_form_dynamic_form, as in the browser.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_dialog_feedback_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_dialog_feedback::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(comment_manager::class, 'count_open')]
final class item_dialog_feedback_test extends \advanced_testcase {
    /** @var \local_nextpool_generator Generator. */
    private \local_nextpool_generator $generator;
    /** @var \stdClass Published item, available site-wide. */
    private \stdClass $item;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Teacher of the course. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dg = $this->getDataGenerator();
        $this->generator = $dg->get_plugin_generator('local_nextpool');
        $this->setAdminUser();
        $this->item = $this->generator->create_published_item(['title' => 'Fire safety basics']);
        $this->course = $dg->create_course();
        $this->teacher = $dg->create_and_enrol($this->course, 'editingteacher');
        // Subscribers are notified of each comment.
        $this->redirectMessages();
    }

    /**
     * Call the form like core_form/dynamicform does: load it (no fields) or send it.
     *
     * @param array|null $fields Form fields to submit, null to load the form.
     * @return array Result of the web service.
     */
    private function submit(?array $fields): array {
        $formdata = ['itemid' => $this->item->id, 'courseid' => $this->course->id];
        if ($fields !== null) {
            $formdata = item_dialog_feedback_form::mock_ajax_submit($fields + $formdata);
        }
        $result = dynamic_form::execute(item_dialog_feedback_form::class, http_build_query($formdata, '', '&'));
        return external_api::clean_returnvalue(dynamic_form::execute_returns(), $result);
    }

    /**
     * A teacher posts a comment from the catalogue of their course; the result carries the new open count.
     */
    public function test_teacher_posts_comment(): void {
        $this->setUser($this->teacher);
        $current = (int) local\item_manager::get((int) $this->item->id)->currentversionid;

        $result = $this->submit(['content' => 'Please add the emergency numbers.', 'versionid' => $current]);

        $this->assertTrue($result['submitted']);
        $data = json_decode($result['data'], true);
        $this->assertSame((int) $this->item->id, $data['itemid']);
        $this->assertSame(1, $data['opencount']);
        $this->assertSame(get_string('commentposted', 'local_nextpool'), $data['message']);
        $comment = comment_manager::get($data['commentid']);
        $this->assertSame('Please add the emergency numbers.', $comment->content);
        $this->assertSame($current, (int) $comment->versionid);
        $this->assertSame((int) $this->teacher->id, (int) $comment->userid);
    }

    /**
     * Without submit data the service returns the form with the fields of feedback.php and the notice.
     */
    public function test_form_is_rendered_with_versions(): void {
        $this->setUser($this->teacher);
        $result = $this->submit(null);
        $this->assertFalse($result['submitted']);
        $this->assertStringContainsString(get_string('commentnew', 'local_nextpool'), $result['html']);
        $this->assertStringContainsString(get_string('commentversion_general', 'local_nextpool'), $result['html']);
        $this->assertStringContainsString(get_string('versionnr', 'local_nextpool', 1), $result['html']);
        $this->assertStringContainsString(get_string('commentnotice', 'local_nextpool'), $result['html']);
    }

    /**
     * An empty text is rejected and nothing is saved.
     */
    public function test_empty_comment_is_rejected(): void {
        $this->setUser($this->teacher);
        $result = $this->submit(['content' => '   ', 'versionid' => 0]);
        $this->assertFalse($result['submitted']);
        $this->assertStringContainsString(get_string('error_commentempty', 'local_nextpool'), $result['html']);
        $this->assertSame(0, comment_manager::count_open([(int) $this->item->id])[(int) $this->item->id] ?? 0);
    }

    /**
     * Students of the course lack the catalogue capability: access is denied as on feedback.php.
     */
    public function test_student_is_denied(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $this->submit(['content' => 'Hello', 'versionid' => 0]);
    }

    /**
     * Teachers of another course cannot post in the name of this course (login in the course, as on feedback.php).
     */
    public function test_user_outside_the_course_is_denied(): void {
        $other = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_and_enrol($other, 'editingteacher');
        $this->setUser($outsider);
        $this->expectException(\require_login_exception::class);
        $this->submit(['content' => 'Hello', 'versionid' => 0]);
    }

    /**
     * Open means top-level, not done and not removed (task81); one query for all items.
     */
    public function test_count_open(): void {
        global $DB;
        $other = $this->generator->create_published_item(['title' => 'Other']);
        $empty = $this->generator->create_published_item(['title' => 'Empty']);
        $courseid = (int) $this->course->id;
        $itemid = (int) $this->item->id;
        $this->setUser($this->teacher);
        $first = feedback_service::add($itemid, $courseid, 'First');
        feedback_service::add($itemid, $courseid, 'Reply', 0, (int) $first->id);
        $done = feedback_service::add($itemid, $courseid, 'Done');
        feedback_service::set_resolved((int) $done->id, $courseid, true);
        $removed = feedback_service::add($itemid, $courseid, 'Removed with reply');
        feedback_service::add($itemid, $courseid, 'Reply', 0, (int) $removed->id);
        feedback_service::delete((int) $removed->id, $courseid);
        feedback_service::add((int) $other->id, $courseid, 'Other one');
        feedback_service::add((int) $other->id, $courseid, 'Other two');

        $reads = $DB->perf_get_reads();
        $counts = comment_manager::count_open([$itemid, (int) $other->id, (int) $empty->id]);
        $this->assertSame(1, $DB->perf_get_reads() - $reads);
        $this->assertSame([$itemid => 1, (int) $other->id => 2], $counts);
        $this->assertSame([], comment_manager::count_open([]));
    }

    /**
     * The area lists the latest top-level comments, newest first and limited, and links to all comments.
     */
    public function test_area_lists_latest_comments(): void {
        global $DB, $PAGE;
        $this->setUser($this->teacher);
        $courseid = (int) $this->course->id;
        for ($i = 1; $i <= item_dialog_feedback::LIMIT + 2; $i++) {
            $comment = feedback_service::add((int) $this->item->id, $courseid, 'Comment ' . $i);
            // Distinct times for the order.
            $DB->set_field(comment_manager::TABLE, 'timecreated', 1000 + $i, ['id' => $comment->id]);
        }
        feedback_service::add((int) $this->item->id, $courseid, 'A reply', 0, (int) $comment->id);

        $item = local\item_manager::get((int) $this->item->id);
        $data = (new item_dialog_feedback($item, $courseid))->export_for_template($PAGE->get_renderer('core'));

        $this->assertCount(item_dialog_feedback::LIMIT, $data['comments']);
        $this->assertSame('Comment ' . (item_dialog_feedback::LIMIT + 2), $data['comments'][0]['text']);
        $this->assertNotContains('A reply', array_column($data['comments'], 'text'));
        $this->assertStringContainsString('/local/nextpool/feedback.php', $data['allurl']);
        $this->assertStringContainsString('courseid=' . $courseid, $data['allurl']);
    }
}
