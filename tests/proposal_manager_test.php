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
use local_nextpool\local\catalogue;
use local_nextpool\local\comment_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\subscription_manager;
use local_nextpool\local\version_manager;

/**
 * Proposals from a course: new version, new item, change, cancel, notifications (F07, task82; test65).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(review_manager::class)]
final class proposal_manager_test extends \advanced_testcase {
    /** @var \local_nextpool_generator Generator. */
    private \local_nextpool_generator $generator;
    /** @var \stdClass Published item, available site-wide. */
    private \stdClass $item;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Editing teacher of the course. */
    private \stdClass $teacher;
    /** @var \stdClass Editor with the edit capability in the system context. */
    private \stdClass $editor;
    /** @var \stdClass Placement of the item in the course. */
    private \stdClass $placement;

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
        $this->placement = placement_manager::place((int) $this->item->id, (int) $this->course->id, 0, 'notify');
    }

    /**
     * A local file with the given content.
     *
     * @param string $content Content.
     * @return string Path.
     */
    private static function file(string $content): string {
        $file = make_request_directory() . '/proposal';
        file_put_contents($file, $content);
        return $file;
    }

    /**
     * F07.AC01, F08.AC04: a valid proposal is a version in review without number, changes no placement, is listed
     * under "My proposals", takes up comments, subscribes the teacher and notifies editorial subscribers only.
     */
    public function test_propose_version(): void {
        $this->setUser($this->teacher);
        $comment = comment_manager::add((int) $this->item->id, 'Please add the emergency numbers.');
        $this->setUser($this->editor);
        subscription_manager::subscribe((int) $this->item->id, (int) $this->editor->id);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        subscription_manager::subscribe((int) $this->item->id, (int) $student->id, (int) $this->course->id);

        $this->setUser($this->teacher);
        $cm = get_fast_modinfo($this->course)->get_cm((int) $this->placement->cmid);
        $this->assertTrue(api::can_propose((int) $cm->id));
        $url = api::get_propose_url((int) $cm->id);
        $this->assertSame('/local/nextpool/propose.php?cmid=' . $cm->id, $url->out_as_local_url(false));

        $sink = $this->redirectMessages();
        $version = proposal_manager::propose_version(
            (int) $this->item->id,
            (int) $this->course->id,
            self::file('version 2'),
            'handout.pdf',
            '<p>Emergency numbers added.</p>',
            FORMAT_HTML,
            [(int) $comment->id, 999999],
            (int) $cm->id
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertSame(status::REVIEW, $version->status);
        $this->assertNull($version->versionnr);
        $this->assertSame(version_manager::ORIGIN_PROPOSAL, $version->origin);
        $this->assertEquals($this->course->id, $version->courseid);
        $this->assertEquals($this->placement->appliedversionid, $version->basedonversionid);
        $this->assertGreaterThan(0, (int) $version->timesubmitted);
        $placement = placement_manager::get((int) $this->placement->id);
        $this->assertEquals($this->placement->appliedversionid, $placement->appliedversionid);
        $this->assertEmpty($placement->pendingversionid);
        $this->assertSame(1, (int) version_manager::get_latest((int) $this->item->id)->versionnr);
        $this->assertSame([(int) $comment->id], array_keys(proposal_manager::get_linked_comments((int) $version->id)));
        $this->assertArrayHasKey($version->id, proposal_manager::get_for_user((int) $this->teacher->id));
        $this->assertTrue(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));

        $recipients = array_map(static fn($m) => (int) $m->useridto, $messages);
        $this->assertContains((int) $this->editor->id, $recipients, 'Editorial subscribers learn about it.');
        $this->assertNotContains((int) $student->id, $recipients, 'Proposals are not visible in courses.');
        $this->assertNotContains((int) $this->teacher->id, $recipients, 'Nobody is told about their own action.');
        $message = reset($messages);
        $this->assertSame('proposalsubmitted', $message->eventtype);
    }

    /**
     * F07.AC02: a missing description, an unchanged file or a file the type refuses creates no version.
     */
    public function test_invalid_proposal_creates_nothing(): void {
        $this->setUser($this->teacher);
        $before = count(version_manager::get_unnumbered((int) $this->item->id));
        foreach (
            [
                ['new content', ' <p> </p> ', 'error_required'],
                ['version 1', 'Same file', 'error_unchanged'],
            ] as [$content, $description, $error]
        ) {
            try {
                proposal_manager::propose_version(
                    (int) $this->item->id,
                    (int) $this->course->id,
                    self::file($content),
                    'handout.pdf',
                    $description
                );
                $this->fail('Expected ' . $error);
            } catch (\moodle_exception $e) {
                $this->assertSame($error, $e->errorcode);
            }
        }
        $this->assertCount($before, version_manager::get_unnumbered((int) $this->item->id));

        $this->expectException(\moodle_exception::class);
        proposal_manager::check_file('quiz', self::file('not a quiz package'), 'quiz.zip');
    }

    /**
     * F07.AC18: no offer without the capability or for an item the course cannot see.
     */
    public function test_offer_needs_capability_and_availability(): void {
        $cmid = (int) $this->placement->cmid;
        $this->setUser($this->teacher);
        $this->assertTrue(api::can_propose($cmid));

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertFalse(api::can_propose($cmid, (int) $student->id));
        $this->assertTrue(api::can_propose($cmid, (int) $this->teacher->id));

        $roleid = (int) \core\di::get(\moodle_database::class)->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(access::PROPOSE, CAP_PROHIBIT, $roleid, \context_course::instance($this->course->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(api::can_propose($cmid));
        $this->assertNull(api::get_propose_url($cmid));
        unassign_capability(access::PROPOSE, $roleid, \context_course::instance($this->course->id));
        accesslib_clear_all_caches_for_unit_testing();

        // An item of another course category is not visible in this course.
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_category();
        item_manager::update((int) $this->item->id, (object) ['contextid' => \context_coursecat::instance($other->id)->id]);
        $this->setUser($this->teacher);
        $this->assertFalse(api::can_propose($cmid));
        $this->assertFalse(api::can_propose(0));
    }

    /**
     * M06.AC08, t82-d: an item uploaded directly and available only in its course gets no proposal (bug105); once the
     * editorial team widens it to a course category, proposals are possible.
     */
    public function test_no_proposal_for_course_bound_item(): void {
        global $DB;
        $cmid = (int) $this->placement->cmid;
        $coursecontext = \context_course::instance($this->course->id);
        $DB->update_record(item_manager::TABLE, (object) [
            'id' => $this->item->id,
            'contextid' => $coursecontext->id,
            'origincourseid' => $this->course->id,
        ]);
        $item = item_manager::get((int) $this->item->id);
        $this->assertTrue(catalogue::is_available_in_course($item, (int) $this->course->id));

        $this->setUser($this->teacher);
        $this->assertFalse(access::can_propose($item, (int) $this->course->id));
        $this->assertFalse(api::can_propose($cmid));
        $this->assertNull(api::get_propose_url($cmid));

        $DB->set_field(item_manager::TABLE, 'contextid', \context_coursecat::instance($this->course->category)->id, [
            'id' => $this->item->id,
        ]);
        $this->assertTrue(api::can_propose($cmid));
    }

    /**
     * F07.AC10: an activity proposed as new item is an item in review in the course category, not in the course
     * catalogue until a reviewer publishes it; a returned proposal is changed and submitted again (F07.AC05).
     */
    public function test_propose_new_item(): void {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Evacuation']);
        $cm = get_fast_modinfo($this->course)->get_cm((int) $page->cmid);
        $placed = get_fast_modinfo($this->course)->get_cm((int) $this->placement->cmid);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);

        $this->setUser($this->teacher);
        $this->assertTrue(proposal_manager::can_propose_item($cm));
        $this->assertFalse(proposal_manager::can_propose_item($placed), 'Placed activities get a new version instead.');
        $this->assertFalse(proposal_manager::can_propose_item(get_fast_modinfo($this->course)->get_cm((int) $forum->cmid)));

        $version = proposal_manager::propose_item($cm, self::file('<p>Leave the building.</p>'), 'evacuation.html', (object) [
            'title' => 'Evacuation',
            'description' => '<p>What to do in an emergency.</p>',
            'descriptionformat' => FORMAT_HTML,
            'license' => 'cc-4.0',
            'authors' => [['userid' => (int) $this->teacher->id]],
            'changelog' => 'First proposal.',
        ]);
        $item = item_manager::get((int) $version->itemid);
        $this->assertSame('page', $item->type);
        $this->assertSame(status::REVIEW, $item->status);
        $this->assertEquals(\context_coursecat::instance((int) $this->course->category)->id, $item->contextid);
        $this->assertSame(status::REVIEW, $version->status);
        $this->assertFalse(catalogue::is_available_in_course($item, (int) $this->course->id));
        $this->assertNotContains((int) $item->id, array_map(
            static fn($i) => (int) $i->id,
            catalogue::get_items((int) $this->course->id)
        ));

        // Returned with a reason, changed and submitted again: the history keeps the reason.
        $this->setAdminUser();
        review_manager::return_for_revision((int) $version->id, 'Please add the assembly point.');
        $this->setUser($this->teacher);
        $this->assertTrue(proposal_manager::can_resubmit(version_manager::get((int) $version->id)));
        $version = proposal_manager::resubmit(
            (int) $version->id,
            'Assembly point added.',
            FORMAT_PLAIN,
            self::file('<p>Leave the building. Meet at the car park.</p>'),
            'evacuation.html'
        );
        $this->assertSame(status::REVIEW, $version->status);
        $this->assertSame('Assembly point added.', $version->changelog);
        $content = version_manager::get_file((int) $version->id)->get_content();
        $this->assertSame('<p>Leave the building. Meet at the car park.</p>', $content);
        $this->assertSame(
            [version_manager::LOG_SUBMITTED, version_manager::LOG_RETURNED, version_manager::LOG_SUBMITTED],
            array_map(static fn($log) => $log->action, version_manager::get_log((int) $version->id))
        );
        $this->assertSame('Please add the assembly point.', proposal_manager::get_last_reason((int) $version->id)->reason);

        // Published by a reviewer, it is in the catalogue of the course.
        $this->setAdminUser();
        review_manager::publish((int) $version->id);
        $item = item_manager::get((int) $item->id);
        $this->assertTrue(catalogue::is_available_in_course($item, (int) $this->course->id));
        $this->assertTrue($DB->record_exists(version_manager::TABLE, ['itemid' => $item->id, 'versionnr' => 1]));
    }

    /**
     * F07.AC11: the person who proposed it cancels a proposal until the decision; a cancelled new item goes back to
     * draft; others cannot cancel.
     */
    public function test_cancel(): void {
        $this->setUser($this->teacher);
        $version = proposal_manager::propose_version(
            (int) $this->item->id,
            (int) $this->course->id,
            self::file('version 2'),
            'handout.pdf',
            'Emergency numbers added.'
        );
        $this->setUser($this->editor);
        try {
            proposal_manager::cancel((int) $version->id);
            $this->fail('Only the person who proposed it may cancel.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_notownproposal', $e->errorcode);
        }
        $this->setUser($this->teacher);
        $version = proposal_manager::cancel((int) $version->id);
        $this->assertSame(status::CANCELLED, $version->status);
        $this->assertEmpty(version_manager::get_unnumbered((int) $this->item->id, status::REVIEW));
        $placement = placement_manager::get((int) $this->placement->id);
        $this->assertEquals($this->placement->appliedversionid, $placement->appliedversionid);
        $this->assertSame(status::PUBLISHED, item_manager::get((int) $this->item->id)->status);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $cm = get_fast_modinfo($this->course)->get_cm((int) $page->cmid);
        $proposal = proposal_manager::propose_item($cm, self::file('<p>Text</p>'), 'page.html', (object) [
            'title' => 'New page',
            'description' => 'Description',
            'license' => 'cc-4.0',
            'changelog' => 'New.',
        ]);
        proposal_manager::cancel((int) $proposal->id);
        $this->assertSame(status::DRAFT, item_manager::get((int) $proposal->itemid)->status);
    }

    /**
     * Task80 rest: publishing marks the comments the reviewer kept ticked as done and notifies subscribers through
     * the subscription rules; returning tells only editorial subscribers.
     */
    public function test_publish_resolves_comments_and_notifies(): void {
        $this->setUser($this->teacher);
        $first = comment_manager::add((int) $this->item->id, 'Numbers missing.');
        $second = comment_manager::add((int) $this->item->id, 'Address missing.');
        $version = proposal_manager::propose_version(
            (int) $this->item->id,
            (int) $this->course->id,
            self::file('version 2'),
            'handout.pdf',
            'Numbers and address added.',
            FORMAT_PLAIN,
            [(int) $first->id, (int) $second->id]
        );
        $teacher2 = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        subscription_manager::subscribe((int) $this->item->id, (int) $teacher2->id, (int) $this->course->id);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        review_manager::return_for_revision((int) $version->id, 'Typo in the address.');
        $recipients = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $sink->clear();
        $this->assertContains((int) $this->teacher->id, $recipients);
        $this->assertNotContains((int) $teacher2->id, $recipients, 'Teachers do not see returned proposals of others.');

        $this->setUser($this->teacher);
        proposal_manager::resubmit((int) $version->id, 'Typo fixed.');
        $this->setAdminUser();
        $sink->clear();
        review_manager::publish((int) $version->id, ['resolvecomments' => [(int) $first->id]]);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertNotEmpty(comment_manager::get((int) $first->id)->timeresolved);
        $this->assertEmpty(comment_manager::get((int) $second->id)->timeresolved);
        $published = array_filter($messages, static fn($m) => $m->eventtype === 'versionpublished');
        $published = array_column($published, null, 'useridto');
        $this->assertArrayHasKey($teacher2->id, $published);
        $this->assertStringContainsString('/local/nextpool/feedback.php', $published[$teacher2->id]->contexturl);
    }
    /**
     * Resubmission follows current proposal rights and availability rather than ownership alone (bug135).
     */
    public function test_resubmit_requires_current_course_permission(): void {
        global $DB;
        $this->setUser($this->teacher);
        $version = proposal_manager::propose_version(
            (int) $this->item->id,
            (int) $this->course->id,
            self::file('updated'),
            'handout.pdf',
            'Update.'
        );
        $this->setAdminUser();
        review_manager::return_for_revision((int) $version->id, 'Revise.');
        $version = version_manager::get((int) $version->id);
        $this->setUser($this->teacher);
        $this->assertTrue(proposal_manager::can_resubmit($version));
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(access::PROPOSE, CAP_PROHIBIT, $roleid, \context_course::instance($this->course->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(proposal_manager::can_resubmit($version));
        unassign_capability(access::PROPOSE, $roleid, \context_course::instance($this->course->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(proposal_manager::can_resubmit($version));
        $this->setAdminUser();
        item_manager::set_status((int) $this->item->id, status::WITHDRAWN);
        $this->setUser($this->teacher);
        $this->assertFalse(proposal_manager::can_resubmit($version));
    }
}
