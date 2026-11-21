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
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Review workflow of versions: origin, numbering, reasons and history (F07, task79, test60).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class review_workflow_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Import new content of an item's file as a version.
     *
     * @param \stdClass $item Item record.
     * @param string $content Content.
     * @param array $options Options for create_from_source().
     * @return \stdClass The version.
     */
    private function import(\stdClass $item, string $content, array $options = []): \stdClass {
        $this->generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', $content);
        return version_manager::create_from_source((int) $item->id, 'handout.pdf', $options);
    }

    /**
     * A proposal from a course, submitted for review.
     *
     * @param \stdClass $item Item record.
     * @param string $content Content.
     * @param int $userid Proposing user.
     * @return \stdClass The version.
     */
    private function propose(\stdClass $item, string $content, int $userid): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $version = $this->import($item, $content, [
            'origin' => version_manager::ORIGIN_PROPOSAL,
            'userid' => $userid,
            'courseid' => $course->id,
        ]);
        return version_manager::set_status((int) $version->id, status::REVIEW, $userid);
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
     * Versions record where they come from: editors, the storage location check, a course or a direct upload.
     */
    public function test_origin(): void {
        $item = $this->generator->create_item();
        $editorial = $this->import($item, 'one');
        $this->assertSame(version_manager::ORIGIN_EDITORIAL, $editorial->origin);
        $this->assertNull($editorial->courseid);

        $polled = $this->import($item, 'two', ['createdby' => 0]);
        $this->assertSame(version_manager::ORIGIN_SOURCE, $polled->origin);

        $course = $this->getDataGenerator()->create_course();
        $proposal = $this->import($item, 'three', ['origin' => version_manager::ORIGIN_PROPOSAL, 'courseid' => $course->id]);
        $this->assertSame(version_manager::ORIGIN_PROPOSAL, $proposal->origin);
        $this->assertSame((int) $course->id, (int) $proposal->courseid);

        $this->expectException(\coding_exception::class);
        $this->import($item, 'four', ['origin' => 'elsewhere']);
    }

    /**
     * F07.AC16: proposals carry no number until published; a rejected one leaves no gap.
     */
    public function test_numbering_at_publish(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $item = $this->generator->create_published_item();
        $this->generator->publish_new_version((int) $item->id, 'version 2');
        $v3 = $this->generator->publish_new_version((int) $item->id, 'version 3');

        $first = $this->propose($item, 'proposal A', (int) $teacher->id);
        $second = $this->propose($item, 'proposal B', (int) $teacher->id);
        $this->assertNull($first->versionnr);
        $this->assertNull($second->versionnr);
        // Lists and the latest version only see numbered versions.
        $this->assertSame((int) $v3->id, (int) version_manager::get_latest((int) $item->id)->id);
        $this->assertCount(3, version_manager::get_versions((int) $item->id));
        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            array_map(static fn($v) => (int) $v->id, version_manager::get_unnumbered((int) $item->id))
        );

        version_manager::set_status((int) $first->id, status::REJECTED, 0, false, 'Duplicates version 3.');
        $published = version_manager::set_status((int) $second->id, status::PUBLISHED);

        $this->assertSame(4, (int) $published->versionnr);
        $this->assertNull(version_manager::get((int) $first->id)->versionnr);
        $this->assertSame((int) $published->id, (int) item_manager::get((int) $item->id)->currentversionid);
        $this->assertSame((int) $published->id, (int) version_manager::get_latest((int) $item->id)->id);

        // Editors' versions keep getting their number when they are created.
        $this->assertSame(5, (int) $this->import($item, 'version 5')->versionnr);

        // A proposal compares as newer than every numbered version.
        $third = $this->propose($item, 'proposal C', (int) $teacher->id);
        $comparison = version_manager::compare((int) $third->id, (int) $v3->id);
        $this->assertSame((int) $v3->id, (int) $comparison['from']->id);
        $this->assertSame((int) $third->id, (int) $comparison['to']->id);
        $this->assertCount(2, $comparison['between']);
    }

    /**
     * F07.AC04: returning and rejecting need a reason, which the history keeps.
     */
    public function test_reason_required(): void {
        $item = $this->generator->create_published_item();
        $version = $this->import($item, 'two');
        version_manager::set_status((int) $version->id, status::REVIEW);

        foreach ([status::DRAFT, status::REJECTED] as $to) {
            try {
                version_manager::set_status((int) $version->id, $to, 0, false, '   ');
                $this->fail('A reason is required for ' . $to);
            } catch (\moodle_exception $e) {
                $this->assertSame('error_reasonrequired', $e->errorcode);
            }
        }
        $this->assertSame(status::REVIEW, version_manager::get((int) $version->id)->status);

        $returned = version_manager::set_status((int) $version->id, status::DRAFT, 0, false, 'Fix the typo.');
        $this->assertSame(status::DRAFT, $returned->status);
        $this->assertGreaterThan(0, (int) $returned->timedecided);
        $log = version_manager::get_log((int) $version->id);
        $this->assertSame([version_manager::LOG_SUBMITTED, version_manager::LOG_RETURNED], array_column($log, 'action'));
        $this->assertSame('Fix the typo.', $log[1]->reason);
        $this->assertSame((int) FORMAT_PLAIN, (int) $log[1]->reasonformat);
    }

    /**
     * F07.AC05 and AC06: a returned proposal can be resubmitted with the earlier reason in its history;
     * a rejected one is final.
     */
    public function test_resubmit_and_reject(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $item = $this->generator->create_published_item();
        $proposal = $this->propose($item, 'proposal', (int) $teacher->id);
        $this->assertGreaterThan(0, (int) $proposal->timesubmitted);

        version_manager::set_status((int) $proposal->id, status::DRAFT, 0, false, 'Please add a source.');
        $resubmitted = version_manager::set_status((int) $proposal->id, status::REVIEW, (int) $teacher->id);
        $this->assertSame(status::REVIEW, $resubmitted->status);
        $actions = array_column(version_manager::get_log((int) $proposal->id), 'action');
        $this->assertSame(
            [version_manager::LOG_SUBMITTED, version_manager::LOG_RETURNED, version_manager::LOG_SUBMITTED],
            $actions
        );
        $this->assertContains('Please add a source.', array_column(version_manager::get_log((int) $proposal->id), 'reason'));

        $rejected = version_manager::set_status((int) $proposal->id, status::REJECTED, 0, false, 'Out of scope.');
        $this->assertSame(status::REJECTED, $rejected->status);
        $this->assertTrue(status::is_final(status::REJECTED));
        $this->assertSame([], status::get_transitions(status::REJECTED));

        $this->expectException(\moodle_exception::class);
        version_manager::set_status((int) $proposal->id, status::REVIEW, (int) $teacher->id);
    }

    /**
     * Only proposals can be cancelled, and only by the person who proposed them (TD-302).
     */
    public function test_cancel(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $item = $this->generator->create_published_item();
        $proposal = $this->propose($item, 'proposal', (int) $teacher->id);

        $this->assertTrue(access::can_change_status($item, status::REVIEW, status::CANCELLED, (int) $teacher->id, $proposal));
        $this->assertFalse(access::can_change_status($item, status::REVIEW, status::CANCELLED, (int) $other->id, $proposal));
        $this->assertFalse(access::can_change_status($item, status::REVIEW, status::CANCELLED, get_admin()->id, $proposal));

        $cancelled = version_manager::set_status((int) $proposal->id, status::CANCELLED, (int) $teacher->id);
        $this->assertSame(status::CANCELLED, $cancelled->status);
        $this->assertNull($cancelled->versionnr);
        $this->assertSame(version_manager::LOG_CANCELLED, array_column(version_manager::get_log((int) $proposal->id), 'action')[1]);

        // An editorial draft is not a proposal.
        $draft = $this->import($item, 'draft');
        $this->assertFalse(access::can_change_status($item, status::DRAFT, status::CANCELLED, get_admin()->id, $draft));
        $this->expectException(\moodle_exception::class);
        version_manager::set_status((int) $draft->id, status::CANCELLED);
    }

    /**
     * Items never become rejected or cancelled.
     */
    public function test_item_statuses(): void {
        $this->assertNotContains(status::REJECTED, status::all(true));
        $this->assertNotContains(status::CANCELLED, status::get_transitions(status::REVIEW, true));
        $this->assertContains(status::REJECTED, status::get_transitions(status::REVIEW));

        $item = $this->generator->create_item();
        item_manager::set_status((int) $item->id, status::REVIEW);
        $this->expectException(\moodle_exception::class);
        item_manager::set_status((int) $item->id, status::REJECTED);
    }

    /**
     * F07.AC17: publishing one's own version is marked as self-approved; a direct upload is not (q54).
     */
    public function test_selfapproved(): void {
        $reviewer = $this->create_user_with([access::REVIEW], \context_system::instance());
        $teacher = $this->getDataGenerator()->create_user();
        $item = $this->generator->create_published_item();

        $own = $this->propose($item, 'own', (int) $reviewer->id);
        $published = version_manager::set_status((int) $own->id, status::PUBLISHED, (int) $reviewer->id);
        $this->assertSame(1, (int) $published->selfapproved);

        $theirs = $this->propose($item, 'theirs', (int) $teacher->id);
        $published = version_manager::set_status((int) $theirs->id, status::PUBLISHED, (int) $reviewer->id);
        $this->assertSame(0, (int) $published->selfapproved);

        // Submitted by the reviewer, created by the storage location check.
        $polled = $this->import($item, 'polled', ['createdby' => 0]);
        version_manager::set_status((int) $polled->id, status::REVIEW, (int) $reviewer->id);
        $published = version_manager::set_status((int) $polled->id, status::PUBLISHED, (int) $reviewer->id);
        $this->assertSame(1, (int) $published->selfapproved);
    }

    /**
     * Direct uploads pass review without a review: no submission in the history, no event (q54).
     */
    public function test_upload_without_review(): void {
        $item = $this->generator->create_item();
        $source = source_manager::get((int) $item->sourceid);
        $file = make_request_directory() . '/upload.pdf';
        file_put_contents($file, 'uploaded');
        $entry = $source->write('upload.pdf', $file);
        $version = version_manager::create_from_file((int) $item->id, $file, 'upload.pdf', $entry, [
            'origin' => version_manager::ORIGIN_UPLOAD,
        ]);

        $sink = $this->redirectEvents();
        version_manager::set_status((int) $version->id, status::REVIEW);
        $published = version_manager::set_status((int) $version->id, status::PUBLISHED);
        $events = array_map(static fn($e) => get_class($e), $sink->get_events());
        $sink->close();

        $this->assertSame(version_manager::ORIGIN_UPLOAD, $published->origin);
        $this->assertSame(0, (int) $published->selfapproved);
        $this->assertSame(0, (int) $published->timesubmitted);
        $this->assertSame([version_manager::LOG_PUBLISHED], array_column(version_manager::get_log((int) $version->id), 'action'));
        $this->assertNotContains(event\version_submitted::class, $events);
        $this->assertContains(event\version_published::class, $events);
    }

    /**
     * Submitting, returning and rejecting trigger their events.
     */
    public function test_events(): void {
        $item = $this->generator->create_published_item();
        $version = $this->import($item, 'two');

        $sink = $this->redirectEvents();
        version_manager::set_status((int) $version->id, status::REVIEW);
        version_manager::set_status((int) $version->id, status::DRAFT, 0, false, 'Not yet.');
        version_manager::set_status((int) $version->id, status::REVIEW);
        version_manager::set_status((int) $version->id, status::REJECTED, 0, false, 'No.');
        $events = $sink->get_events();
        $sink->close();

        $classes = array_map(static fn($e) => get_class($e), $events);
        $this->assertSame([
            event\version_submitted::class,
            event\version_returned::class,
            event\version_submitted::class,
            event\version_rejected::class,
        ], $classes);
        foreach ($events as $event) {
            $this->assertSame((int) $version->id, (int) $event->objectid);
            $this->assertSame((int) $item->id, (int) $event->other['itemid']);
            $this->assertNotEmpty($event->get_name());
            $this->assertNotEmpty($event->get_description());
        }
    }

    /**
     * TD-310 (q77): for site-wide items, the review right in any category is enough to decide and to be assigned;
     * withdrawing stays with the right in the item's context.
     */
    public function test_can_review(): void {
        $cata = $this->getDataGenerator()->create_category();
        $catb = $this->getDataGenerator()->create_category();
        $contexta = \context_coursecat::instance($cata->id);
        $contextb = \context_coursecat::instance($catb->id);
        $reviewera = $this->create_user_with([access::REVIEW], $contexta);
        $reviewerb = $this->create_user_with([access::REVIEW], $contextb);
        $sitereviewer = $this->create_user_with([access::REVIEW], \context_system::instance());
        $editor = $this->create_user_with([access::MANAGE], \context_system::instance());

        $siteitem = $this->generator->create_published_item();
        $itemb = $this->generator->create_published_item(['contextid' => $contextb->id]);
        $proposal = $this->propose($siteitem, 'proposal', (int) $editor->id);

        $this->assertTrue(access::can_review($siteitem, (int) $reviewera->id));
        $this->assertTrue(access::can_review($siteitem, (int) $sitereviewer->id));
        $this->assertFalse(access::can_review($siteitem, (int) $editor->id));
        $this->assertFalse(access::can_review($itemb, (int) $reviewera->id));
        $this->assertTrue(access::can_review($itemb, (int) $reviewerb->id));
        $this->assertTrue(access::can_review($itemb, (int) $sitereviewer->id));

        foreach ([status::PUBLISHED, status::DRAFT, status::REJECTED] as $to) {
            $this->assertTrue(access::can_change_status($siteitem, status::REVIEW, $to, (int) $reviewera->id, $proposal));
            $this->assertFalse(access::can_change_status($siteitem, status::REVIEW, $to, (int) $editor->id, $proposal));
        }
        $published = version_manager::get((int) $siteitem->currentversionid);
        $this->assertFalse(
            access::can_change_status($siteitem, status::PUBLISHED, status::WITHDRAWN, (int) $reviewera->id, $published)
        );
        $this->assertTrue(
            access::can_change_status($siteitem, status::PUBLISHED, status::WITHDRAWN, (int) $sitereviewer->id, $published)
        );

        $siteids = array_keys(access::get_reviewers($siteitem));
        sort($siteids);
        $expected = [(int) $reviewera->id, (int) $reviewerb->id, (int) $sitereviewer->id];
        sort($expected);
        $this->assertSame($expected, array_map('intval', $siteids));

        $bids = array_map('intval', array_keys(access::get_reviewers($itemb)));
        sort($bids);
        $expected = [(int) $reviewerb->id, (int) $sitereviewer->id];
        sort($expected);
        $this->assertSame($expected, $bids);
    }
}
