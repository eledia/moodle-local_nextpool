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

use local_nextpool\local\access;
use local_nextpool\local\comment_manager;
use local_nextpool\local\feedback_service;
use local_nextpool\local\placement_manager;
use local_nextpool\local\subscription_manager;
use local_nextpool\output\feedback_section;

/**
 * Feedback on catalogue items: comments, replies, done, subscriptions, access (F08, task81; test63, test64).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(comment_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(subscription_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_section::class)]
final class feedback_test extends \advanced_testcase {
    /** @var \local_nextpool_generator Generator. */
    private \local_nextpool_generator $generator;
    /** @var \stdClass Published item, available site-wide. */
    private \stdClass $item;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Teacher of the course. */
    private \stdClass $teacher;
    /** @var \stdClass Second teacher of the course. */
    private \stdClass $teacher2;
    /** @var \stdClass Student of the course. */
    private \stdClass $student;
    /** @var \stdClass Editor with the edit capability in the system context. */
    private \stdClass $editor;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dg = $this->getDataGenerator();
        $this->generator = $dg->get_plugin_generator('local_nextpool');
        $this->editor = $dg->create_user(['firstname' => 'Edda', 'lastname' => 'Editor']);
        $roleid = $dg->create_role();
        assign_capability(access::MANAGE, CAP_ALLOW, $roleid, \context_system::instance());
        $dg->role_assign($roleid, $this->editor->id, \context_system::instance()->id);

        $this->setUser($this->editor);
        $this->item = $this->generator->create_published_item(['title' => 'Fire safety basics']);
        $this->setAdminUser();

        $this->course = $dg->create_course();
        $this->teacher = $dg->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Terry']);
        $this->teacher2 = $dg->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Tina']);
        $this->student = $dg->create_and_enrol($this->course, 'student');
    }

    /**
     * A teacher comments on the version applied in their course; the comment has name, time and version
     * (F08.AC01); the person who created the item is notified, the teacher is now subscribed (F08.AC05).
     */
    public function test_comment_on_applied_version_notifies_subscribers(): void {
        $placement = placement_manager::place((int) $this->item->id, (int) $this->course->id, 0, 'notify');
        $applied = (int) $placement->appliedversionid;
        $this->generator->publish_new_version((int) $this->item->id, 'version 2');
        $item = local\item_manager::get((int) $this->item->id);
        $this->assertNotSame($applied, (int) $item->currentversionid);

        // The course view proposes the applied version, the editorial view the current one.
        $this->assertSame($applied, comment_manager::get_default_versionid($item, (int) $this->course->id));
        $this->assertSame((int) $item->currentversionid, comment_manager::get_default_versionid($item));

        $this->setUser($this->teacher);
        $sink = $this->redirectMessages();
        $comment = feedback_service::add((int) $item->id, (int) $this->course->id, 'Please add the emergency numbers.', $applied);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertSame($applied, (int) $comment->versionid);
        $this->assertCount(1, $messages);
        $this->assertSame((int) $this->editor->id, (int) $messages[0]->useridto);
        $this->assertSame('commentposted', $messages[0]->eventtype);
        $this->assertStringContainsString('Fire safety basics', $messages[0]->subject);
        $this->assertStringContainsString('/local/nextpool/item.php', $messages[0]->contexturl);

        $this->assertTrue(subscription_manager::is_subscribed((int) $item->id, (int) $this->teacher->id));
        $this->assertTrue((bool) get_user_preferences(comment_manager::PREF_NOTICE, 0, $this->teacher->id));

        // The second teacher replies: the first teacher gets a link to the course view, the author of the reply nothing.
        $this->setUser($this->teacher2);
        $sink = $this->redirectMessages();
        feedback_service::add((int) $item->id, (int) $this->course->id, 'Agreed.', 0, (int) $comment->id);
        $recipients = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $urls = array_column($sink->get_messages(), 'contexturl', 'useridto');
        $sink->close();
        sort($recipients);
        $this->assertSame([(int) $this->editor->id, (int) $this->teacher->id], $recipients);
        $this->assertStringContainsString('/local/nextpool/feedback.php', $urls[$this->teacher->id]);
        $this->assertStringContainsString('courseid=' . $this->course->id, $urls[$this->teacher->id]);

        // The section shows name, short date and version.
        $data = $this->export($item, (int) $this->course->id);
        $this->assertCount(1, $data['open']);
        $this->assertStringContainsString('Terry', $data['open'][0]['author']);
        $this->assertSame('Version 1', $data['open'][0]['version']);
        $this->assertCount(1, $data['open'][0]['replies']);

        // The thread renders with its reply (a reply must not inherit the replies of its parent).
        global $PAGE;
        $html = $PAGE->get_renderer('core')->render_from_template('local_nextpool/feedback_section', $data);
        $this->assertSame(1, substr_count($html, 'Agreed.'));
    }

    /**
     * A reply to a reply is attached to the same top-level comment (F08.AC02).
     */
    public function test_reply_to_reply_is_flattened(): void {
        $this->setUser($this->teacher);
        $comment = comment_manager::add((int) $this->item->id, 'Typo on page 2');
        $reply = comment_manager::add((int) $this->item->id, 'Which line?', FORMAT_MOODLE, null, (int) $comment->id);
        $replyreply = comment_manager::add((int) $this->item->id, 'Line 4', FORMAT_MOODLE, null, (int) $reply->id);
        $this->assertSame((int) $comment->id, (int) $replyreply->parentid);
        $threads = comment_manager::get_threads((int) $this->item->id);
        $this->assertCount(1, $threads);
        $this->assertCount(2, $threads[$comment->id]->replies);
    }

    /**
     * Item editors mark a comment as done and open it again; done threads are listed apart (F08.AC03).
     */
    public function test_resolve_and_reopen(): void {
        global $DB;
        $this->setUser($this->teacher);
        $comment = feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Outdated phone number');

        // Another teacher may not mark it as done.
        $this->setUser($this->teacher2);
        try {
            feedback_service::set_resolved((int) $comment->id, (int) $this->course->id, true);
            $this->fail('Only the author, item editors and the editorial team mark comments as done.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        // An item editor without editorial rights may.
        $DB->insert_record('local_nextpool_item_editor', (object) ['itemid' => $this->item->id,
            'userid' => $this->teacher2->id, 'addedby' => $this->editor->id, 'timecreated' => time()]);
        $resolved = feedback_service::set_resolved((int) $comment->id, 0, true);
        $this->assertSame((int) $this->teacher2->id, (int) $resolved->resolvedby);
        $this->assertGreaterThan(0, (int) $resolved->timeresolved);

        $data = $this->export($this->item);
        $this->assertCount(0, $data['open']);
        $this->assertCount(1, $data['done']);
        $this->assertStringContainsString('Tina', $data['done'][0]['resolvedtext']);

        $reopened = feedback_service::set_resolved((int) $comment->id, 0, false);
        $this->assertNull($reopened->resolvedby);
        $this->assertSame(0, (int) $reopened->timeresolved);
    }

    /**
     * Unsubscribing stops notifications and survives commenting again (F08.AC05, q76); teachers who only placed
     * the item are not subscribed (F08.AC11).
     */
    public function test_unsubscribe_is_remembered_and_placing_does_not_subscribe(): void {
        $this->setUser($this->teacher);
        placement_manager::place((int) $this->item->id, (int) $this->course->id, 0, 'notify');
        $this->assertFalse(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));

        feedback_service::add((int) $this->item->id, (int) $this->course->id, 'First');
        $this->assertTrue(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));
        feedback_service::set_subscription((int) $this->item->id, (int) $this->course->id, false);
        feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Second');
        $this->assertFalse(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));

        $this->setUser($this->teacher2);
        $sink = $this->redirectMessages();
        feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Third');
        $recipients = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $sink->close();
        $this->assertSame([(int) $this->editor->id], $recipients);

        // Subscribing again on request works.
        $this->setUser($this->teacher);
        feedback_service::set_subscription((int) $this->item->id, (int) $this->course->id, true);
        $this->assertTrue(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));
    }

    /**
     * Editing marks a comment as edited; only the author may edit.
     */
    public function test_update_own_comment_only(): void {
        $this->setUser($this->teacher);
        $comment = feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Typo');
        $updated = feedback_service::update((int) $comment->id, (int) $this->course->id, 'Typo on page 2');
        $this->assertSame('Typo on page 2', $updated->content);
        $this->assertGreaterThan((int) $updated->timecreated, (int) $updated->timemodified);

        $this->setUser($this->editor);
        $this->expectException(\moodle_exception::class);
        feedback_service::update((int) $comment->id, 0, 'Changed by someone else');
    }

    /**
     * The editorial team removes a comment with replies: a placeholder stays, the replies too (F08.AC06, q70);
     * removing the last reply removes the placeholder.
     */
    public function test_remove_comment_with_replies_leaves_placeholder(): void {
        global $DB;
        $this->setUser($this->teacher);
        $comment = feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Wrong number');
        $this->setUser($this->teacher2);
        $reply = feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Still wrong', 0, (int) $comment->id);
        $single = feedback_service::add((int) $this->item->id, (int) $this->course->id, 'Nice');

        // Teachers cannot remove other people's comments.
        try {
            feedback_service::delete((int) $comment->id, (int) $this->course->id);
            $this->fail('Teachers may only remove their own comments.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->setUser($this->editor);
        $sink = $this->redirectEvents();
        $this->assertTrue(feedback_service::delete((int) $comment->id, 0));
        $events = $sink->get_events();
        $sink->close();
        $this->assertInstanceOf(\local_nextpool\event\comment_deleted::class, $events[0]);
        $placeholder = $DB->get_record('local_nextpool_comment', ['id' => $comment->id]);
        $this->assertSame(1, (int) $placeholder->deleted);
        $this->assertSame('', (string) $placeholder->content);
        $this->assertTrue($DB->record_exists('local_nextpool_comment', ['id' => $reply->id]));

        $data = $this->export($this->item);
        $thread = array_values(array_filter($data['open'], static fn($c) => $c['id'] === (int) $comment->id))[0];
        $this->assertTrue($thread['deleted']);
        $this->assertSame('', $thread['content']);
        $this->assertCount(1, $thread['replies']);

        // A comment without replies goes; the last reply takes the placeholder with it.
        $this->assertFalse(feedback_service::delete((int) $single->id, 0));
        $this->assertFalse($DB->record_exists('local_nextpool_comment', ['id' => $single->id]));
        $this->setUser($this->teacher2);
        feedback_service::delete((int) $reply->id, (int) $this->course->id);
        $this->assertFalse($DB->record_exists('local_nextpool_comment', ['id' => $comment->id]));
    }

    /**
     * Teachers of a course where the item is not available, and students, get no access (F08.AC07, F08.AC01).
     */
    public function test_no_access_without_availability(): void {
        $dg = $this->getDataGenerator();
        $category = $dg->create_category();
        $this->setUser($this->editor);
        $hidden = $this->generator->create_published_item([
            'title' => 'Only in category',
            'contextid' => \context_coursecat::instance($category->id)->id,
        ]);

        $this->assertFalse(access::can_comment($hidden, (int) $this->course->id, (int) $this->teacher->id));
        $this->assertTrue(access::can_comment($this->item, (int) $this->course->id, (int) $this->teacher->id));
        $this->assertFalse(access::can_comment($this->item, (int) $this->course->id, (int) $this->student->id));
        $this->assertFalse(access::can_comment($this->item, 0, (int) $this->teacher->id));

        $this->setUser($this->teacher);
        $this->expectException(\required_capability_exception::class);
        feedback_service::add((int) $hidden->id, (int) $this->course->id, 'Should not work');
    }

    /**
     * A teacher cannot refer to an unpublished version from a course, and empty comments are refused.
     */
    public function test_refuses_draft_versions_and_empty_text(): void {
        $this->setUser($this->editor);
        $path = \local_nextpool\local\version_manager::get_latest((int) $this->item->id)->sourceref;
        $this->generator->put_file(\local_nextpool\local\source\manager::get((int) $this->item->sourceid), $path, 'draft');
        $draft = \local_nextpool\local\version_manager::create_from_source((int) $this->item->id, $path);
        $this->setUser($this->teacher);
        try {
            feedback_service::add((int) $this->item->id, (int) $this->course->id, 'About the draft', (int) $draft->id);
            $this->fail('Draft versions are not offered in a course.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }
        $this->expectExceptionMessage(get_string('error_commentempty', 'local_nextpool'));
        feedback_service::add((int) $this->item->id, (int) $this->course->id, '  <p> </p> ');
    }

    /**
     * Template data of the section.
     *
     * @param \stdClass $item Item.
     * @param int $courseid Course, 0 for the editorial view.
     * @return array
     */
    private function export(\stdClass $item, int $courseid = 0): array {
        global $PAGE;
        return (new feedback_section($item, $courseid))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * The person who creates an item is subscribed to it (q66).
     */
    public function test_creator_is_subscribed(): void {
        $this->assertTrue(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->editor->id));
        $subscription = subscription_manager::get_subscribers((int) $this->item->id)[$this->editor->id];
        $this->assertSame(1, (int) $subscription->auto);
    }
}
