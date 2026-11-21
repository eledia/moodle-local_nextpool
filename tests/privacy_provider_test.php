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
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_statistics;
use local_nextpool\local\version_manager;
use local_nextpool\privacy\provider;

/**
 * Tests for the local_nextpool privacy provider.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_provider_test extends provider_testcase {
    /** @var \stdClass Item author and creator. */
    private \stdClass $author;
    /** @var \stdClass Unrelated user. */
    private \stdClass $other;
    /** @var \stdClass Item. */
    private \stdClass $item;
    /** @var \stdClass Version. */
    private \stdClass $version;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->author = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');

        $this->setUser($this->author);
        $source = $generator->create_source();
        $generator->put_file($source, 'a.txt', 'content');
        $this->item = $generator->create_item(
            ['sourceid' => $source->get_id()],
            [['userid' => $this->author->id, 'role' => 'Editor'], ['name' => 'External Person']]
        );
        $this->version = version_manager::create_from_source($this->item->id, 'a.txt');
    }

    /**
     * Metadata includes personal tables, file/tag/message subsystems and the comment notice preference.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_nextpool'))->get_collection();
        $this->assertCount(14, $items);
        $this->assertContains('core_files', array_map(static fn($item) => $item->get_name(), $items));
    }

    /**
     * The statistics per item and version are not personal data (F15, plan 21 §5.5, task126): the table has no field
     * that refers to a person, stays out of the metadata, and a stored row makes nobody a user of the plugin.
     */
    public function test_item_statistics_are_not_personal(): void {
        global $DB;
        $tables = array_map(
            fn($entry) => $entry->get_name(),
            provider::get_metadata(new collection('local_nextpool'))->get_collection()
        );
        $this->assertNotContains(item_statistics::TABLE, $tables);
        $columns = array_keys($DB->get_columns(item_statistics::TABLE));
        $this->assertEmpty(preg_grep('/(user|by)$/', $columns), 'No field refers to a person.');
        $DB->insert_record(item_statistics::TABLE, (object) [
            'itemid' => $this->item->id, 'versionnr' => 0, 'started' => 7, 'timecalculated' => time(),
        ]);
        $this->assertEmpty(provider::get_contexts_for_userid($this->other->id)->get_contextids());
        $userlist = new userlist(\context_system::instance(), 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->author->id], array_values($userlist->get_userids()));
    }

    /**
     * Contexts and users are found for involved users only.
     */
    public function test_contexts_and_users(): void {
        $system = \context_system::instance();
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($this->author->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($this->other->id)->get_contextids());

        $userlist = new userlist($system, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->author->id], array_values($userlist->get_userids()));
    }

    /**
     * Export contains the items the user is involved in.
     */
    public function test_export(): void {
        $system = \context_system::instance();
        provider::export_user_data(new approved_contextlist($this->author, 'local_nextpool', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertSame($this->item->title, $data->title);
        $this->assertSame('Editor', $data->authorrole);
        $this->assertCount(1, $data->versions);
    }

    /**
     * Deleting one user anonymises only that user's references; content stays.
     */
    public function test_delete_for_user(): void {
        $system = \context_system::instance();
        provider::delete_data_for_user(new approved_contextlist($this->author, 'local_nextpool', [$system->id]));

        $item = item_manager::get($this->item->id);
        $this->assertEquals(0, $item->createdby);
        $this->assertEquals(0, $item->usermodified);
        $authors = item_manager::get_authors($this->item->id);
        $this->assertNull($authors[0]->userid);
        $this->assertSame('External Person', $authors[1]->name);
        $this->assertEquals(0, version_manager::get($this->version->id)->createdby);
        $this->assertNotNull(version_manager::get_file($this->version->id));
        $this->assertEmpty(provider::get_contexts_for_userid($this->author->id)->get_contextids());
    }

    /**
     * Deleting a context anonymises everyone, including free-text authors.
     */
    public function test_delete_all_in_context(): void {
        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $authors = item_manager::get_authors($this->item->id);
        $this->assertNull($authors[0]->userid);
        $this->assertSame('', $authors[1]->name);
    }

    /**
     * Deleting a user list.
     */
    public function test_delete_for_users(): void {
        $system = \context_system::instance();
        provider::delete_data_for_users(new approved_userlist($system, 'local_nextpool', [$this->author->id]));
        $userlist = new userlist($system, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * Whoever last changed the competencies of an item is found, exported and anonymised (F14).
     */
    public function test_competency_editor(): void {
        global $DB;
        $system = \context_system::instance();
        $this->setUser($this->other);
        item_competencies::set((int) $this->item->id, [['competency' => 'SAFE-01', 'level' => 2, 'function' => 'explain']]);

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($this->other->id)->get_contextids());
        $userlist = new userlist($system, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $this->other->id, array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($this->other, 'local_nextpool', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertSame(['SAFE-01'], $data->competencieschangedbyyou);

        provider::delete_data_for_user(new approved_contextlist($this->other, 'local_nextpool', [$system->id]));
        $this->assertEquals(0, $DB->get_field(item_competencies::TABLE, 'usermodified', ['itemid' => $this->item->id]));
        $this->assertCount(1, item_competencies::get((int) $this->item->id), 'The competency itself stays.');
        $this->assertEmpty(provider::get_contexts_for_userid($this->other->id)->get_contextids());
    }
}
