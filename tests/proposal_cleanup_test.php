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
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\task\cleanup_proposals;

/**
 * Retention of proposals: rejected and cancelled ones go after 12 months, the editorial team deletes them earlier
 * (F07, q67, task86; test60 "Aufräumen").
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cleanup_proposals::class)]
final class proposal_cleanup_test extends \advanced_testcase {
    /** @var \local_nextpool_generator Generator. */
    private \local_nextpool_generator $generator;
    /** @var \stdClass Published item, available site-wide. */
    private \stdClass $item;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Editing teacher of the course. */
    private \stdClass $teacher;
    /** @var \cm_info Course module of the placed item. */
    private \cm_info $cm;

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
        $placement = placement_manager::place((int) $this->item->id, (int) $this->course->id, 0, 'notify');
        $this->cm = get_fast_modinfo($this->course)->get_cm((int) $placement->cmid);
    }

    /**
     * A proposal of the teacher with the given content, in review.
     *
     * @param string $content File content.
     * @param int[] $commentids Comments it takes up.
     * @return \stdClass Version.
     */
    private function propose(string $content, array $commentids = []): \stdClass {
        $this->setUser($this->teacher);
        $file = make_request_directory() . '/proposal';
        file_put_contents($file, $content);
        $version = proposal_manager::propose_version(
            (int) $this->item->id,
            (int) $this->course->id,
            $file,
            'handout.pdf',
            'Changed.',
            FORMAT_PLAIN,
            $commentids,
            (int) $this->cm->id
        );
        $this->setAdminUser();
        return $version;
    }

    /**
     * Set when a version was decided.
     *
     * @param \stdClass $version Version.
     * @param string $ago Relative time, e.g. "-13 months".
     */
    private function decided(\stdClass $version, string $ago): void {
        global $DB;
        $DB->set_field(version_manager::TABLE, 'timedecided', strtotime($ago), ['id' => $version->id]);
    }

    /**
     * Rejected and cancelled proposals decided more than 12 months ago go with snapshot, history and comment links;
     * comments about them stay as comments about the item. Younger ones, proposals in review and published versions
     * stay.
     */
    public function test_cleanup_after_twelve_months(): void {
        global $DB;
        $this->setUser($this->teacher);
        $comment = comment_manager::add((int) $this->item->id, 'Please add the emergency numbers.');

        $old = $this->propose('old rejected', [(int) $comment->id]);
        review_manager::reject((int) $old->id, 'Not needed.');
        $this->setUser($this->teacher);
        $about = comment_manager::add((int) $this->item->id, 'About the proposal.', FORMAT_PLAIN, (int) $old->id);
        $this->setAdminUser();
        $this->decided($old, '-13 months');

        $cancelled = $this->propose('old cancelled');
        proposal_manager::cancel((int) $cancelled->id, (int) $this->teacher->id);
        $this->decided($cancelled, '-13 months');

        $young = $this->propose('young rejected');
        review_manager::reject((int) $young->id, 'Not yet.');
        $this->decided($young, '-11 months');

        $waiting = $this->propose('waiting');
        $DB->set_field(version_manager::TABLE, 'timesubmitted', strtotime('-14 months'), ['id' => $waiting->id]);

        $this->assertNotNull(version_manager::get_file((int) $old->id));
        $task = new cleanup_proposals();
        $this->assertNotEmpty($task->get_name());
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('Deleted 2', $output);

        foreach ([$old, $cancelled] as $gone) {
            $this->assertFalse($DB->record_exists(version_manager::TABLE, ['id' => $gone->id]));
            $this->assertNull(version_manager::get_file((int) $gone->id));
            $this->assertFalse($DB->record_exists(version_manager::LOG_TABLE, ['versionid' => $gone->id]));
        }
        $this->assertFalse($DB->record_exists(proposal_manager::LINK_TABLE, ['versionid' => $old->id]));
        $this->assertNull(comment_manager::get((int) $about->id)->versionid, 'The comment stays, about the item.');
        $this->assertTrue($DB->record_exists('local_nextpool_comment', ['id' => $comment->id]));
        foreach ([$young, $waiting] as $kept) {
            $this->assertTrue($DB->record_exists(version_manager::TABLE, ['id' => $kept->id]));
            $this->assertNotNull(version_manager::get_file((int) $kept->id));
        }
        $this->assertNotNull(version_manager::get_latest((int) $this->item->id), 'Published versions stay.');
        $this->assertSame(0, proposal_manager::cleanup());
    }

    /**
     * A proposed new item whose only proposal is rejected goes with the proposal (t86-b); an item with a published
     * version stays.
     */
    public function test_cleanup_deletes_proposed_new_item(): void {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Evacuation']);
        $cm = get_fast_modinfo($this->course)->get_cm((int) $page->cmid);
        $file = make_request_directory() . '/page.html';
        file_put_contents($file, '<p>Leave the building.</p>');
        $this->setUser($this->teacher);
        $version = proposal_manager::propose_item($cm, $file, 'evacuation.html', (object) [
            'title' => 'Evacuation',
            'description' => '<p>What to do in an emergency.</p>',
            'descriptionformat' => FORMAT_HTML,
            'license' => 'cc-4.0',
            'authors' => [['userid' => (int) $this->teacher->id]],
            'changelog' => 'First proposal.',
        ]);
        $this->setAdminUser();
        review_manager::reject((int) $version->id, 'We have one.');
        $version = version_manager::get((int) $version->id);
        $this->assertTrue(proposal_manager::deletes_item($version));
        $this->assertSame(status::DRAFT, item_manager::get((int) $version->itemid)->status);
        $this->decided($version, '-13 months');

        $this->assertSame(1, proposal_manager::cleanup());
        $this->assertFalse($DB->record_exists(item_manager::TABLE, ['id' => $version->itemid]));
        $this->assertTrue($DB->record_exists(item_manager::TABLE, ['id' => $this->item->id]));
    }

    /**
     * Only the editorial team of the item deletes earlier, and only rejected or cancelled proposals (q67).
     */
    public function test_delete_early(): void {
        global $DB;
        $dg = $this->getDataGenerator();
        $editor = $dg->create_user();
        $reviewer = $dg->create_user();
        $roleid = $dg->create_role();
        assign_capability(access::MANAGE, CAP_ALLOW, $roleid, \context_system::instance());
        $dg->role_assign($roleid, $editor->id, \context_system::instance()->id);
        $reviewrole = $dg->create_role();
        assign_capability(access::REVIEW, CAP_ALLOW, $reviewrole, \context_system::instance());
        $dg->role_assign($reviewrole, $reviewer->id, \context_system::instance()->id);

        $proposal = $this->propose('to be rejected');
        $this->assertFalse(proposal_manager::can_delete($this->item, $proposal, (int) $editor->id), 'Still in review.');
        try {
            proposal_manager::delete((int) $proposal->id);
            $this->fail('A proposal in review cannot be deleted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_versionnotdeletable', $e->errorcode);
        }
        review_manager::reject((int) $proposal->id, 'No.');
        $proposal = version_manager::get((int) $proposal->id);
        $this->assertTrue(proposal_manager::can_delete($this->item, $proposal, (int) $editor->id));
        $this->assertFalse(proposal_manager::can_delete($this->item, $proposal, (int) $reviewer->id));
        $this->assertFalse(proposal_manager::can_delete($this->item, $proposal, (int) $this->teacher->id));
        $this->assertFalse(proposal_manager::can_delete(
            $this->item,
            version_manager::get_latest((int) $this->item->id),
            (int) $editor->id
        ), 'Published versions are no proposals to delete.');

        $this->assertFalse(proposal_manager::deletes_item($proposal));
        $this->assertFalse(proposal_manager::delete((int) $proposal->id));
        $this->assertFalse($DB->record_exists(version_manager::TABLE, ['id' => $proposal->id]));
        $this->assertTrue($DB->record_exists(item_manager::TABLE, ['id' => $this->item->id]));

        $this->expectException(\moodle_exception::class);
        version_manager::delete_unpublished((int) version_manager::get_latest((int) $this->item->id)->id);
    }
}
