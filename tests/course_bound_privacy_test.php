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
use local_nextpool\local\item_manager;
use local_nextpool\local\version_manager;
use local_nextpool\privacy\provider;

/**
 * Privacy of items uploaded from a course, which live in the course context (M06 task78a, test96, TD-411).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class course_bound_privacy_test extends provider_testcase {
    /** @var \stdClass Teacher who uploaded the item and is its author. */
    private \stdClass $teacher;
    /** @var \stdClass Teacher who placed it. */
    private \stdClass $placer;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \context_course Course context. */
    private \context_course $context;
    /** @var \stdClass Item bound to the course. */
    private \stdClass $item;
    /** @var \stdClass Placement of the item in the course. */
    private \stdClass $placement;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->placer = $this->getDataGenerator()->create_user();
        $this->course = $this->getDataGenerator()->create_course();
        $this->context = \context_course::instance($this->course->id);
        $this->getDataGenerator()->enrol_user($this->placer->id, $this->course->id, 'editingteacher');

        /** @var \mod_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        $this->setUser($this->teacher);
        $item = $generator->create_xapi_item([
            'contextid' => $this->context->id,
            'origincourseid' => $this->course->id,
        ]);
        item_manager::update((int) $item->id, (object) [], [['userid' => $this->teacher->id, 'role' => 'Author']]);
        $this->item = item_manager::get((int) $item->id);

        $this->setUser($this->placer);
        $this->placement = $generator->place($this->item, $this->course);
        $this->setUser();
    }

    /**
     * Creator, author and publisher are found in the course context, next to the teacher who placed it.
     */
    public function test_contexts_and_users(): void {
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->teacher->id)->get_contextids());
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->placer->id)->get_contextids());

        $userlist = new userlist($this->context, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $userids = array_map('intval', $userlist->get_userids());
        sort($userids);
        $expected = [(int) $this->teacher->id, (int) $this->placer->id];
        sort($expected);
        $this->assertSame($expected, $userids);
    }

    /**
     * The export of the course context holds the item and the placement.
     */
    public function test_export(): void {
        provider::export_user_data(new approved_contextlist($this->teacher, 'local_nextpool', [$this->context->id]));
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertSame($this->item->title, $data->title);
        $this->assertSame('Author', $data->authorrole);
        $this->assertCount(1, $data->versions);

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->placer, 'local_nextpool', [$this->context->id]));
        $placements = writer::with_context($this->context)->get_data([
            get_string('pluginname', 'local_nextpool'),
            get_string('placementsheading', 'local_nextpool'),
        ]);
        $this->assertCount(1, $placements->placements);
        $this->assertEmpty(writer::with_context($this->context)->get_data([
            get_string('pluginname', 'local_nextpool'),
            $this->item->uuid,
        ]));
    }

    /**
     * Deleting one user anonymises their references to the item; content and placement stay.
     */
    public function test_delete_for_user(): void {
        provider::delete_data_for_user(new approved_contextlist($this->teacher, 'local_nextpool', [$this->context->id]));

        $item = item_manager::get((int) $this->item->id);
        $this->assertEquals(0, $item->createdby);
        $this->assertNull(item_manager::get_authors((int) $item->id)[0]->userid);
        $version = version_manager::get((int) $item->currentversionid);
        $this->assertEquals(0, $version->createdby);
        $this->assertEquals(0, $version->publishedby);
        $this->assertEmpty(provider::get_contexts_for_userid($this->teacher->id)->get_contextids());
        // The other user's placement is untouched.
        $this->assertEquals($this->placer->id, local\placement_manager::get((int) $this->placement->id)->placedby);
    }

    /**
     * Deleting the course context or a user list anonymises item and placement references.
     */
    public function test_delete_all_and_users(): void {
        provider::delete_data_for_users(new approved_userlist($this->context, 'local_nextpool', [(int) $this->placer->id]));
        $this->assertEquals(0, local\placement_manager::get((int) $this->placement->id)->placedby);
        $this->assertEquals($this->teacher->id, item_manager::get((int) $this->item->id)->createdby);

        provider::delete_data_for_all_users_in_context($this->context);
        $userlist = new userlist($this->context, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids());
        $this->assertNotEmpty(version_manager::get_file((int) $this->item->currentversionid));
    }
    /**
     * Course-bound files follow the same privacy lifecycle as xAPI uploads (M08 test207).
     */
    public function test_file_upload_privacy_lifecycle(): void {
        global $DB;
        $DB->set_field(item_manager::TABLE, 'type', 'file', ['id' => $this->item->id]);
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->teacher->id)->get_contextids());
        $userlist = new userlist($this->context, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $this->teacher->id, array_map('intval', $userlist->get_userids()));
        provider::export_user_data(new approved_contextlist($this->teacher, 'local_nextpool', [$this->context->id]));
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertSame($this->item->title, $data->title);
        provider::delete_data_for_user(new approved_contextlist($this->teacher, 'local_nextpool', [$this->context->id]));
        $this->assertEquals(0, item_manager::get((int) $this->item->id)->createdby);
        $this->assertEquals($this->placer->id, local\placement_manager::get((int) $this->placement->id)->placedby);
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(0, local\placement_manager::get((int) $this->placement->id)->placedby);
    }
}
