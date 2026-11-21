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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_nextpool\local\access;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\review_queue;
use local_nextpool\local\status;
use local_nextpool\local\subscription_manager;
use local_nextpool\local\version_manager;
use local_nextpool\output\review_page;
use local_nextpool\privacy\provider;

/**
 * Privacy of proposals, review decisions and assignments (F07, q70, task86; test64).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(review_page::class)]
final class review_privacy_test extends provider_testcase {
    /** @var \stdClass Item in a course category. */
    private \stdClass $item;
    /** @var \context Item context. */
    private \context $context;
    /** @var \stdClass Teacher who proposes. */
    private \stdClass $teacher;
    /** @var \stdClass Reviewer who assigns. */
    private \stdClass $assigner;
    /** @var \stdClass Reviewer who is assigned and rejects. */
    private \stdClass $reviewer;
    /** @var \stdClass The rejected proposal. */
    private \stdClass $proposal;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $dg = $this->getDataGenerator();
        $category = $dg->create_category();
        $this->context = \context_coursecat::instance($category->id);
        $this->setAdminUser();
        $this->item = $dg->get_plugin_generator('local_nextpool')->create_published_item(['contextid' => $this->context->id]);
        $course = $dg->create_course(['category' => $category->id]);
        $this->teacher = $dg->create_and_enrol($course, 'editingteacher', ['firstname' => 'Terry', 'lastname' => 'Teacher']);
        $this->assigner = $dg->create_user(['firstname' => 'Ada', 'lastname' => 'Assigner']);
        $this->reviewer = $dg->create_user(['firstname' => 'Rita', 'lastname' => 'Reviewer']);
        $roleid = $dg->create_role();
        assign_capability(access::REVIEW, CAP_ALLOW, $roleid, \context_system::instance());
        $dg->role_assign($roleid, $this->assigner->id, $this->context->id);
        $dg->role_assign($roleid, $this->reviewer->id, $this->context->id);

        $file = make_request_directory() . '/proposal';
        file_put_contents($file, 'proposed content');
        $this->setUser($this->teacher);
        $proposal = proposal_manager::propose_version(
            (int) $this->item->id,
            (int) $course->id,
            $file,
            'handout.pdf',
            'Emergency numbers added.',
            FORMAT_PLAIN
        );
        $this->setUser($this->assigner);
        review_queue::assign((int) $proposal->id, (int) $this->reviewer->id);
        $this->setUser($this->reviewer);
        review_manager::reject((int) $proposal->id, 'Too long.');
        $this->setAdminUser();
        $this->proposal = version_manager::get((int) $proposal->id);
    }

    /**
     * Metadata names the new fields and links the messaging system, which stores the notifications.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('local_nextpool'));
        $links = [];
        $fields = [];
        foreach ($collection->get_collection() as $type) {
            if ($type instanceof \core_privacy\local\metadata\types\subsystem_link) {
                $links[] = $type->get_name();
            }
            if ($type instanceof \core_privacy\local\metadata\types\database_table) {
                $fields[$type->get_name()] = array_keys($type->get_privacy_fields());
            }
        }
        $this->assertContains('core_message', $links);
        $this->assertContains('core_tag', $links);
        foreach (['origin', 'courseid', 'changelog', 'timesubmitted', 'timedecided', 'assignedto'] as $field) {
            $this->assertContains($field, $fields['local_nextpool_version']);
        }
        $this->assertContains('relateduserid', $fields['local_nextpool_version_log']);
        $this->assertContains('auto', $fields['local_nextpool_subscription']);
        $this->assertArrayHasKey('local_nextpool_item_editor', $fields);
        $this->assertArrayHasKey('local_nextpool_comment', $fields);
    }

    /**
     * Proposing, assigning and deciding put people into the item's context.
     */
    public function test_contexts_and_users(): void {
        foreach ([$this->teacher, $this->assigner, $this->reviewer] as $user) {
            $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($user->id)->get_contextids());
        }
        $userlist = new userlist($this->context, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $userids = array_map('intval', $userlist->get_userids());
        foreach ([$this->teacher, $this->assigner, $this->reviewer] as $user) {
            $this->assertContains((int) $user->id, $userids);
        }
    }

    /**
     * The export of the proposing person holds the proposal with description and file; the reviewer's export
     * holds the decision with the reason and the assignment.
     */
    public function test_export(): void {
        $path = [get_string('pluginname', 'local_nextpool'), $this->item->uuid];
        $this->export_context_data_for_user((int) $this->teacher->id, $this->context, 'local_nextpool');
        $data = writer::with_context($this->context)->get_data($path);
        $versions = array_values(array_filter($data->versions, static fn($v) => $v['origin'] === 'proposal'));
        $this->assertCount(1, $versions);
        $this->assertSame(status::REJECTED, $versions[0]['status']);
        $this->assertSame(get_string('yes'), $versions[0]['createdbyyou']);
        $this->assertStringContainsString('Emergency numbers added.', $versions[0]['changelog']);
        $files = writer::with_context($this->context)->get_files(array_merge($path, ['proposal-' . $this->proposal->id]));
        $this->assertArrayHasKey('handout.pdf', $files);
        $this->assertNotNull($data->subscription);

        writer::reset();
        $this->export_context_data_for_user((int) $this->reviewer->id, $this->context, 'local_nextpool');
        $data = writer::with_context($this->context)->get_data($path);
        $this->assertCount(1, $data->versions);
        $version = $data->versions[0];
        $this->assertSame('', $version['changelog'], 'The description belongs to the proposing person.');
        $actions = array_column($version['history'], 'action');
        $this->assertSame([version_manager::LOG_ASSIGNED, version_manager::LOG_REJECTED], $actions);
        $this->assertSame(get_string('yes'), $version['history'][0]['assignedtoyou']);
        $this->assertSame('Too long.', $version['history'][1]['reason']);
        $this->assertEmpty(writer::with_context($this->context)->get_files(
            array_merge($path, ['proposal-' . $this->proposal->id])
        ));
    }

    /**
     * Deleting the reviewer removes their reason and their assignment; the history shows "Reason removed" and the
     * assignment without the person. Deleting the proposing person keeps the proposal without the person and
     * removes their subscription (q70).
     */
    public function test_delete_for_user(): void {
        global $DB, $PAGE;
        provider::delete_data_for_user(new approved_contextlist($this->reviewer, 'local_nextpool', [$this->context->id]));
        $logs = version_manager::get_log((int) $this->proposal->id);
        $this->assertSame(version_manager::LOG_SUBMITTED, $logs[0]->action);
        $this->assertSame(0, (int) $logs[1]->relateduserid, 'Assignment kept without the person (not "removed").');
        $this->assertEquals($this->assigner->id, $logs[1]->userid);
        $this->assertSame(0, (int) $logs[2]->userid);
        $this->assertNull($logs[2]->reason);
        $this->assertNull(version_manager::get((int) $this->proposal->id)->assignedto);

        $page = new review_page($this->item, version_manager::get((int) $this->proposal->id));
        $data = $page->export_for_template($PAGE->get_renderer('core'));
        $this->assertStringContainsString('Assigned to - by Ada Assigner', $data['history'][1]['text']);
        $this->assertSame(get_string('reasonremoved', 'local_nextpool'), $data['history'][2]['reason']);

        provider::delete_data_for_users(new approved_userlist($this->context, 'local_nextpool', [$this->teacher->id]));
        $version = version_manager::get((int) $this->proposal->id);
        $this->assertSame(0, (int) $version->createdby);
        $this->assertSame(status::REJECTED, $version->status);
        $this->assertSame(version_manager::ORIGIN_PROPOSAL, $version->origin);
        $this->assertNotNull(version_manager::get_file((int) $version->id), 'The proposal stays without the person.');
        $this->assertFalse(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));
        $this->assertFalse($DB->record_exists('local_nextpool_subscription', ['userid' => $this->teacher->id]));
        $data = (new review_page($this->item, $version))->export_for_template($PAGE->get_renderer('core'));
        $label = get_string('reviewcreated', 'local_nextpool');
        $created = array_values(array_filter($data['facts'], static fn($f) => $f['label'] === $label));
        $this->assertStringStartsWith('Created by - on', $created[0]['value'], 'Not "imported automatically".');

        provider::delete_data_for_users(new approved_userlist($this->context, 'local_nextpool', [$this->assigner->id]));
        $this->assertSame(0, (int) version_manager::get_log((int) $this->proposal->id)[1]->userid);
    }

    /**
     * The history shows whom a review was assigned to and when an assignment was removed (task85 open point).
     */
    public function test_history_names_the_assignee(): void {
        global $DB, $PAGE;
        $DB->set_field(version_manager::TABLE, 'status', status::REVIEW, ['id' => $this->proposal->id]);
        $this->setUser($this->assigner);
        review_queue::assign((int) $this->proposal->id, 0);
        $this->setAdminUser();
        $data = (new review_page($this->item, version_manager::get((int) $this->proposal->id)))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertStringContainsString('Assigned to Rita Reviewer by Ada Assigner', $data['history'][1]['text']);
        $this->assertSame('Too long.', trim(strip_tags($data['history'][2]['reason'])));
        $this->assertStringContainsString('Assignment removed by Ada Assigner', end($data['history'])['text']);
    }
}
