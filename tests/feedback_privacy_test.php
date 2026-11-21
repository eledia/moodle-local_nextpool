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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_nextpool\local\comment_manager;
use local_nextpool\local\subscription_manager;
use local_nextpool\privacy\provider;

/**
 * Privacy of feedback: export and deletion of comments and subscriptions (F08, task81, q70; test64).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class feedback_privacy_test extends provider_testcase {
    /** @var \stdClass Item in a course category. */
    private \stdClass $item;
    /** @var \context Item context. */
    private \context $context;
    /** @var \stdClass Person who comments. */
    private \stdClass $alice;
    /** @var \stdClass Person who replies and marks as done. */
    private \stdClass $bob;
    /** @var \stdClass Alice's comment. */
    private \stdClass $comment;
    /** @var \stdClass Bob's reply. */
    private \stdClass $reply;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $dg = $this->getDataGenerator();
        $category = $dg->create_category();
        $this->context = \context_coursecat::instance($category->id);
        $this->setAdminUser();
        $this->item = $dg->get_plugin_generator('local_nextpool')->create_published_item(['contextid' => $this->context->id]);
        // The item is created by admin; only the feedback below involves Alice and Bob.
        $this->alice = $dg->create_user();
        $this->bob = $dg->create_user();

        $this->setUser($this->alice);
        $this->comment = comment_manager::add((int) $this->item->id, 'Alice says hello');
        $this->setUser($this->bob);
        $this->reply = comment_manager::add((int) $this->item->id, 'Bob answers', FORMAT_MOODLE, null, (int) $this->comment->id);
        comment_manager::set_resolved((int) $this->comment->id, true);
        $this->setAdminUser();
    }

    /**
     * Commenting, marking as done and subscribing put people into the item's context.
     */
    public function test_contexts_and_users(): void {
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->alice->id)->get_contextids());
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->bob->id)->get_contextids());
        $userlist = new userlist($this->context, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int) get_admin()->id, (int) $this->alice->id, (int) $this->bob->id],
            array_map('intval', $userlist->get_userids())
        );
    }

    /**
     * Export contains the own comments with text, the comments marked as done and the subscription.
     */
    public function test_export(): void {
        $this->export_context_data_for_user((int) $this->alice->id, $this->context, 'local_nextpool');
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertCount(1, $data->comments);
        $this->assertStringContainsString('Alice says hello', $data->comments[0]['content']);
        $this->assertSame(get_string('yes'), $data->subscription['subscribed']);

        writer::reset();
        $this->export_context_data_for_user((int) $this->bob->id, $this->context, 'local_nextpool');
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertCount(2, $data->comments);
        // Alice's text is not exported for Bob, only that he marked it as done.
        $this->assertSame('', $data->comments[0]['content']);
        $this->assertSame(get_string('yes'), $data->comments[0]['markeddonebyyou']);
    }

    /**
     * Deleting a person replaces their texts by the placeholder, keeps other people's replies, removes "done by"
     * and their subscriptions (q70).
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->assertTrue(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->alice->id));
        provider::delete_data_for_user(new approved_contextlist($this->alice, 'local_nextpool', [$this->context->id]));
        $comment = $DB->get_record('local_nextpool_comment', ['id' => $this->comment->id]);
        $this->assertSame(1, (int) $comment->deleted);
        $this->assertSame('', (string) $comment->content);
        $this->assertSame(0, (int) $comment->userid);
        $this->assertSame('Bob answers', $DB->get_field('local_nextpool_comment', 'content', ['id' => $this->reply->id]));
        $this->assertFalse($DB->record_exists('local_nextpool_subscription', ['userid' => $this->alice->id]));
        $this->assertTrue($DB->record_exists('local_nextpool_subscription', ['userid' => $this->bob->id]));

        provider::delete_data_for_users(new approved_userlist($this->context, 'local_nextpool', [$this->bob->id]));
        $this->assertNull($DB->get_field('local_nextpool_comment', 'resolvedby', ['id' => $this->comment->id]));
        $this->assertSame(0, (int) $DB->get_field('local_nextpool_comment', 'userid', ['id' => $this->reply->id]));
        $this->assertFalse($DB->record_exists('local_nextpool_subscription', ['userid' => $this->bob->id]));
    }

    /**
     * Deleting everyone in the context empties all texts and subscriptions.
     */
    public function test_delete_all_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertSame(0, $DB->count_records_select('local_nextpool_comment', "deleted = 0 OR userid <> 0"));
        $this->assertSame(0, $DB->count_records('local_nextpool_subscription', ['itemid' => $this->item->id]));
    }

    /**
     * The notice preference is exported.
     */
    public function test_export_preferences(): void {
        provider::export_user_preferences((int) $this->alice->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('local_nextpool');
        $this->assertSame(get_string('yes'), $prefs->{comment_manager::PREF_NOTICE}->value);
    }
}
