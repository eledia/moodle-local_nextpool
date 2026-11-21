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
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\privacy\provider;

/**
 * Privacy of placements: the teacher who placed or updated content in a course.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class placement_privacy_test extends provider_testcase {
    /**
     * Teachers are found in the course context, exported and anonymised there; the placement stays.
     */
    public function test_placements(): void {
        global $DB;
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $coursecontext = \context_course::instance($course->id);

        $this->setUser($teacher);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $this->setAdminUser();
        $generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);
        $this->setUser($other);
        placement_manager::skip((int) $placement->id);

        $this->assertContainsEquals($coursecontext->id, provider::get_contexts_for_userid((int) $teacher->id)->get_contextids());
        $this->assertContainsEquals($coursecontext->id, provider::get_contexts_for_userid((int) $other->id)->get_contextids());

        $userlist = new userlist($coursecontext, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([(int) $teacher->id, (int) $other->id], $userlist->get_userids());

        provider::export_user_data(new approved_contextlist($teacher, 'local_nextpool', [$coursecontext->id]));
        $data = writer::with_context($coursecontext)->get_data([
            get_string('pluginname', 'local_nextpool'),
            get_string('placementsheading', 'local_nextpool'),
        ]);
        $this->assertCount(1, $data->placements);

        provider::delete_data_for_users(new approved_userlist($coursecontext, 'local_nextpool', [(int) $other->id]));
        $this->assertFalse($DB->record_exists(placement_manager::LOG_TABLE, ['userid' => $other->id]));
        $this->assertEquals($teacher->id, placement_manager::get((int) $placement->id)->placedby);

        provider::delete_data_for_all_users_in_context($coursecontext);
        $this->assertEquals(0, placement_manager::get((int) $placement->id)->placedby);
        $this->assertFalse($DB->record_exists(placement_manager::LOG_TABLE, ['userid' => $teacher->id]));
    }
}
