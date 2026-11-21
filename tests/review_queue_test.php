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

use core_reportbuilder\system_report_factory;
use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\review_queue;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\reportbuilder\local\systemreports\review_queue as review_queue_report;
use local_nextpool\reportbuilder\local\systemreports\reviews_done;

/**
 * "Waiting for review" with assignment (F07, task85, test68).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(review_queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(review_queue_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(reviews_done::class)]
final class review_queue_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;
    /** @var \stdClass Reviewer in category A only. */
    private \stdClass $reviewera;
    /** @var \stdClass Second reviewer in category A only. */
    private \stdClass $reviewera2;
    /** @var \stdClass Reviewer in category B only. */
    private \stdClass $reviewerb;
    /** @var \stdClass Reviewer on the site level. */
    private \stdClass $sitereviewer;
    /** @var \stdClass Editor on the site level, without the review capability. */
    private \stdClass $editor;
    /** @var \stdClass Published item available site-wide. */
    private \stdClass $siteitem;
    /** @var \stdClass Published item in category A. */
    private \stdClass $itema;
    /** @var \stdClass Published item in category B. */
    private \stdClass $itemb;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $cata = \context_coursecat::instance($this->getDataGenerator()->create_category()->id);
        $catb = \context_coursecat::instance($this->getDataGenerator()->create_category()->id);
        $this->reviewera = $this->create_user_with([access::REVIEW], $cata);
        $this->reviewera2 = $this->create_user_with([access::REVIEW], $cata);
        $this->reviewerb = $this->create_user_with([access::REVIEW], $catb);
        $this->sitereviewer = $this->create_user_with([access::REVIEW], \context_system::instance());
        $this->editor = $this->create_user_with([access::MANAGE], \context_system::instance());
        $this->siteitem = $this->generator->create_published_item(['title' => 'Site item']);
        $this->itema = $this->generator->create_published_item(['title' => 'Item A', 'contextid' => $cata->id]);
        $this->itemb = $this->generator->create_published_item(['title' => 'Item B', 'contextid' => $catb->id]);
    }

    /**
     * Create a user with a role holding the given capabilities in a context.
     *
     * @param string[] $capabilities Capabilities to allow.
     * @param \context $context Context of the role assignment.
     * @return \stdClass User.
     */
    private function create_user_with(array $capabilities, \context $context): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Import new content as a draft and submit it for review as the editor.
     *
     * @param \stdClass $item Item record.
     * @param string $content Content.
     * @param int $timesubmitted Submission time to record, 0 to keep it.
     * @return \stdClass The version in review.
     */
    private function submit(\stdClass $item, string $content, int $timesubmitted = 0): \stdClass {
        global $DB;
        $this->generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', $content);
        $this->setUser($this->editor);
        $version = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $version = version_manager::set_status((int) $version->id, status::REVIEW);
        if ($timesubmitted) {
            $DB->set_field(version_manager::TABLE, 'timesubmitted', $timesubmitted, ['id' => $version->id]);
            $version->timesubmitted = $timesubmitted;
        }
        return $version;
    }

    /**
     * Rendered review overview for the current user.
     *
     * @param string $class System report class.
     * @return string HTML
     */
    private function render(string $class = review_queue_report::class): string {
        global $PAGE;
        $PAGE->set_url('/local/nextpool/reviews.php');
        return system_report_factory::create($class, \context_system::instance(), 'local_nextpool', 'test')->output();
    }

    /**
     * F07.AC12, q77: reviewers of category A see A and site-wide items, not B, oldest first, with kind and
     * assignment as text; drafts from the storage location are listed as well (q73).
     */
    public function test_overview_lists_reviewable_versions_oldest_first(): void {
        $this->submit($this->itema, 'a2', time() - 3 * DAYSECS);
        $this->submit($this->siteitem, 'site2', time() - 5 * DAYSECS);
        $this->submit($this->itemb, 'b2', time() - 7 * DAYSECS);
        // A draft from the storage location check (q73).
        $this->setAdminUser();
        $item = $this->generator->create_published_item(['title' => 'Polled item', 'contextid' => $this->itema->contextid]);
        $this->generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', 'polled change');
        $polled = version_manager::create_from_source((int) $item->id, 'handout.pdf', ['createdby' => 0]);
        $this->assertSame(version_manager::ORIGIN_SOURCE, $polled->origin);
        $this->assertGreaterThan(0, (int) $polled->timesubmitted);

        $this->setUser($this->reviewera);
        $html = $this->render();
        $this->assertStringContainsString('Item A', $html);
        $this->assertStringContainsString('Site item', $html);
        $this->assertStringContainsString('Polled item', $html);
        $this->assertStringNotContainsString('Item B', $html);
        $this->assertLessThan(strpos($html, 'Item A'), strpos($html, 'Site item'));
        $this->assertStringContainsString('>Draft of the editorial team</td>', $html);
        $this->assertStringContainsString('>From the storage location</td>', $html);
        $this->assertStringContainsString('>Not assigned</td>', $html);
        $this->assertStringContainsString('>Storage location check</td>', $html);
        $this->assertSame(3, review_queue::count_for_user());

        $this->setUser($this->reviewerb);
        $html = $this->render();
        $this->assertStringContainsString('Item B', $html);
        $this->assertStringContainsString('Site item', $html);
        $this->assertStringNotContainsString('Item A', $html);

        // Editors without the review capability do not see the overview.
        $this->setUser($this->editor);
        $this->assertFalse(review_queue::can_view());
        $this->expectException(\core_reportbuilder\exception\report_access_exception::class);
        $this->render();
    }

    /**
     * F07.AC12, q77: reviewers of a category may publish a site-wide item, but not withdraw it.
     */
    public function test_category_reviewer_publishes_site_item(): void {
        $version = $this->submit($this->siteitem, 'site2');
        $this->setUser($this->reviewera);
        $this->assertTrue(access::can_change_status($this->siteitem, status::REVIEW, status::PUBLISHED, null, $version));
        $published = version_manager::set_status((int) $version->id, status::PUBLISHED);
        $this->assertSame(status::PUBLISHED, $published->status);
        $this->assertFalse(access::can_change_status($this->siteitem, status::PUBLISHED, status::WITHDRAWN, null, $published));
    }

    /**
     * F07.AC13: only people who may review the item can be assigned; the assigned person gets a message and sees the
     * review under "assigned to me".
     */
    public function test_assign(): void {
        $sitev = $this->submit($this->siteitem, 'site2');
        $av = $this->submit($this->itema, 'a2');

        $this->setUser($this->reviewera);
        // Site-wide item: reviewers of any category may be assigned (q77).
        $sink = $this->redirectMessages();
        $assigned = review_queue::assign((int) $sitev->id, (int) $this->reviewerb->id);
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertSame((int) $this->reviewerb->id, (int) $assigned->assignedto);
        $this->assertCount(1, $messages);
        $this->assertSame('reviewassigned', $messages[0]->eventtype);
        $this->assertSame((int) $this->reviewerb->id, (int) $messages[0]->useridto);
        $log = version_manager::get_log((int) $sitev->id);
        $last = end($log);
        $this->assertSame(version_manager::LOG_ASSIGNED, $last->action);
        $this->assertSame((int) $this->reviewerb->id, (int) $last->relateduserid);

        // Item of category A: a reviewer of category B cannot be assigned, nor can an editor.
        foreach ([$this->reviewerb, $this->editor] as $user) {
            try {
                review_queue::assign((int) $av->id, (int) $user->id);
                $this->fail('Assigned a person who may not review the item.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_reviewassignee', $e->errorcode);
            }
        }

        // A reviewer of category B cannot assign reviews of category A at all.
        $this->setUser($this->reviewerb);
        $this->expectException(\required_capability_exception::class);
        review_queue::assign((int) $av->id, (int) $this->reviewerb->id);
    }

    /**
     * "I review", clearing the assignment and the counter of the user menu.
     */
    public function test_take_and_counter(): void {
        $version = $this->submit($this->itema, 'a2');
        $this->setUser($this->reviewera);
        $this->assertSame(1, review_queue::count_for_user());

        $sink = $this->redirectMessages();
        review_queue::assign((int) $version->id, (int) $this->reviewera->id);
        $this->assertSame(0, $sink->count(), 'No message when taking a review oneself.');
        $sink->close();
        $this->assertSame(1, review_queue::count_for_user());
        $this->assertStringContainsString(fullname($this->reviewera), $this->render());

        $this->setUser($this->reviewera2);
        $this->assertSame(0, review_queue::count_for_user(), 'Assigned to someone else.');
        review_queue::assign((int) $version->id, 0);
        $this->assertNull(version_manager::get((int) $version->id)->assignedto);
        $this->assertSame(1, review_queue::count_for_user());

        // A decision leaves the overview.
        version_manager::set_status((int) $version->id, status::DRAFT, 0, false, 'Please revise.');
        $this->assertSame(0, review_queue::count_for_user());
        try {
            review_queue::assign((int) $version->id, (int) $this->reviewera2->id);
            $this->fail('Assigned a version that no longer waits.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_reviewnotwaiting', $e->errorcode);
        }
    }

    /**
     * F07.AC14, q72: a submission without assignment reaches everyone who may review the item, not the submitter;
     * after the assignment only the assigned person.
     */
    public function test_submission_messages(): void {
        $sink = $this->redirectMessages();
        $version = $this->submit($this->siteitem, 'site2');
        $recipients = array_map(static fn($m) => (int) $m->useridto, array_filter(
            $sink->get_messages(),
            static fn($m) => $m->eventtype === 'reviewsubmitted'
        ));
        sort($recipients);
        $expected = array_map('intval', [$this->reviewera->id, $this->reviewera2->id, $this->reviewerb->id,
            $this->sitereviewer->id]);
        sort($expected);
        $this->assertSame($expected, $recipients);
        $sink->clear();

        // Category item: only reviewers of the category and site-level reviewers.
        $this->submit($this->itema, 'a2');
        $recipients = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        sort($recipients);
        $expected = array_map('intval', [$this->reviewera->id, $this->reviewera2->id, $this->sitereviewer->id]);
        sort($expected);
        $this->assertSame($expected, $recipients);

        // Assigned, returned and submitted again: only the assigned person hears about it.
        $this->setUser($this->reviewera);
        review_queue::assign((int) $version->id, (int) $this->reviewerb->id);
        version_manager::set_status((int) $version->id, status::DRAFT, 0, false, 'Please revise.');
        $sink->clear();
        $this->setUser($this->editor);
        version_manager::set_status((int) $version->id, status::REVIEW);
        $messages = array_values(array_filter($sink->get_messages(), static fn($m) => $m->eventtype === 'reviewsubmitted'));
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame((int) $this->reviewerb->id, (int) $messages[0]->useridto);
    }

    /**
     * "Completed reviews": decisions of the last 90 days with "self-approved" (F07.AC17).
     */
    public function test_completed_reviews(): void {
        global $DB;
        $own = $this->submit($this->itema, 'a2');
        $this->setUser($this->editor);
        $this->generator->put_file(source_manager::get((int) $this->itemb->sourceid), 'handout.pdf', 'b2');
        $other = version_manager::create_from_source((int) $this->itemb->id, 'handout.pdf');

        // A reviewer who submitted the version publishes it: self-approved.
        $this->setUser($this->reviewera);
        $draft = version_manager::set_status((int) $own->id, status::DRAFT, 0, false, 'Revise.');
        $this->assertSame(status::DRAFT, $draft->status);
        version_manager::set_status((int) $own->id, status::REVIEW);
        $published = version_manager::set_status((int) $own->id, status::PUBLISHED);
        $this->assertSame('1', (string) $published->selfapproved);

        $html = $this->render(reviews_done::class);
        $ownlink = 'review.php?versionid=' . $own->id . '"';
        $this->assertSame(2, substr_count($html, $ownlink), 'Returned and published.');
        $this->assertStringContainsString('>Self-approved</td>', $html);
        $this->assertStringContainsString('>Returned for revision</td>', $html);
        $this->assertStringNotContainsString('Item B', $html);
        $this->assertStringNotContainsString('review.php?versionid=' . $other->id . '"', $html);

        // Older decisions drop out.
        $DB->set_field(version_manager::LOG_TABLE, 'timecreated', time() - 100 * DAYSECS, ['versionid' => $own->id]);
        $this->assertStringNotContainsString($ownlink, $this->render(reviews_done::class));
    }

    /**
     * Submitting an item for review submits its newest draft version: only that version waits, the item is not
     * counted twice. Proposals are not submitted on the proposer's behalf.
     */
    public function test_item_submission_submits_newest_draft(): void {
        $item = $this->generator->create_item(['title' => 'Draft item', 'contextid' => $this->itema->contextid]);
        $this->generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', 'first draft');
        $this->setUser($this->editor);
        $draft = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $this->assertSame(status::DRAFT, $draft->status);

        $sink = $this->redirectMessages();
        item_manager::set_status((int) $item->id, status::REVIEW);
        $this->assertGreaterThan(0, $sink->count(), 'Reviewers are notified about the submitted version.');
        $sink->close();
        $this->assertSame(status::REVIEW, item_manager::get((int) $item->id)->status);
        $this->assertSame(status::REVIEW, version_manager::get((int) $draft->id)->status);

        $this->setUser($this->reviewera);
        $this->assertSame([], review_queue::get_waiting_items());
        $this->assertSame(1, review_queue::count_for_user());
    }

    /**
     * Without a draft to submit, the submitted item itself waits in the queue until a version waits or the item
     * leaves the review status.
     */
    public function test_item_without_draft_waits_itself(): void {
        $item = $this->generator->create_item(['title' => 'Rejected item', 'contextid' => $this->itema->contextid]);
        $this->generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', 'only version');
        $this->setUser($this->editor);
        $version = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        version_manager::set_status((int) $version->id, status::REVIEW);
        version_manager::set_status((int) $version->id, status::REJECTED, reason: 'Not suitable.');
        $this->assertNull(item_manager::get_submittable_draft((int) $item->id));

        item_manager::set_status((int) $item->id, status::REVIEW);
        $this->assertSame(status::REJECTED, version_manager::get((int) $version->id)->status);

        $this->setUser($this->reviewera);
        $this->assertSame([(int) $item->id], array_map('intval', array_keys(review_queue::get_waiting_items())));
        $this->assertSame(1, review_queue::count_for_user());
        $this->setUser($this->reviewerb);
        $this->assertSame([], review_queue::get_waiting_items(), 'Not reviewable in category B.');
        $this->assertSame(0, review_queue::count_for_user());

        // Back to draft: nothing waits any more.
        $this->setUser($this->editor);
        item_manager::set_status((int) $item->id, status::DRAFT);
        $this->setUser($this->reviewera);
        $this->assertSame([], review_queue::get_waiting_items());
        $this->assertSame(0, review_queue::count_for_user());
    }
}
