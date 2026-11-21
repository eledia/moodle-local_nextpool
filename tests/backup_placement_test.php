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

use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\source\entry;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Placements travel with course backups, copies and imports.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class backup_placement_test extends \advanced_testcase {
    /**
     * Back up a course into a backup directory.
     *
     * @param int $courseid Course id.
     * @return string Backup id.
     */
    private function backup(int $courseid): string {
        global $CFG, $USER;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        return $backupid;
    }

    /**
     * Restore a backup into a new course.
     *
     * @param string $backupid Backup id.
     * @return int New course id.
     */
    private function restore_into_new_course(string $backupid): int {
        global $CFG, $USER;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $course = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $course->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return (int) $course->id;
    }

    /**
     * A restored course module is linked to the same item, version and policy again.
     */
    public function test_restore_recreates_placement(): void {
        global $DB;
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $version2 = $generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);

        $newcourseid = $this->restore_into_new_course($this->backup((int) $course->id));

        $restored = array_values(placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, $restored);
        $this->assertNotEquals($placement->cmid, $restored[0]->cmid);
        $this->assertEquals($item->id, $restored[0]->itemid);
        $this->assertEquals($placement->appliedversionid, $restored[0]->appliedversionid);
        $this->assertEquals($version2->id, $restored[0]->pendingversionid, 'The offered update is kept.');
        $this->assertSame(policy::NOTIFY, $restored[0]->policy);
        $this->assertSame('resource', get_fast_modinfo($newcourseid)->get_cm((int) $restored[0]->cmid)->modname);
        $this->assertSame(placement_manager::ACTION_RESTORE, placement_manager::get_log((int) $restored[0]->id)[0]->action);

        // The restored placement is updated like any other.
        placement_manager::apply((int) $restored[0]->id, (int) $version2->id);
        $this->assertEquals($version2->id, placement_manager::get((int) $restored[0]->id)->appliedversionid);
        $this->assertEquals(1, $DB->count_records(placement_manager::TABLE, ['courseid' => $course->id]));
    }

    /**
     * Without the item on this site the activity is restored without catalogue link.
     */
    public function test_restore_without_item(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);

        $backupid = $this->backup((int) $course->id);
        $DB->set_field('local_nextpool_item', 'uuid', \core\uuid::generate(), ['id' => $item->id]);
        $newcourseid = $this->restore_into_new_course($backupid);

        $this->assertSame([], placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, get_fast_modinfo($newcourseid)->get_instances_of('resource'));
    }

    /**
     * A withdrawn item is not linked again; the activity is restored without catalogue link.
     */
    public function test_restore_withdrawn_item(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $backupid = $this->backup((int) $course->id);
        item_manager::set_status((int) $item->id, status::WITHDRAWN);
        $newcourseid = $this->restore_into_new_course($backupid);

        $this->assertSame([], placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, get_fast_modinfo($newcourseid)->get_instances_of('resource'));
        $this->assertEquals(1, placement_manager::count_for_item((int) $item->id), 'The original placement stays.');
    }

    /**
     * An item of a course category is not linked in a course outside that category.
     */
    public function test_restore_item_not_available_in_target_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $category = $this->getDataGenerator()->create_category();
        $item = $generator->create_published_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        // The new course is created in the default category, outside the item's category.
        $newcourseid = $this->restore_into_new_course($this->backup((int) $course->id));

        $this->assertSame([], placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, get_fast_modinfo($newcourseid)->get_instances_of('resource'));
    }

    /**
     * Open proposals of the item, which have no version number yet, do not disturb the restore (bug167).
     */
    public function test_restore_with_open_proposals(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        foreach (['proposal A', 'proposal B'] as $content) {
            $file = make_request_directory() . '/proposal';
            file_put_contents($file, $content);
            $proposal = version_manager::create_from_file((int) $item->id, $file, 'handout.pdf', new entry(''), [
                'origin' => version_manager::ORIGIN_PROPOSAL,
                'courseid' => (int) $course->id,
            ]);
            $this->assertNull($proposal->versionnr);
        }

        $newcourseid = $this->restore_into_new_course($this->backup((int) $course->id));

        $this->assertDebuggingNotCalled();
        $restored = array_values(placement_manager::get_for_course($newcourseid));
        $this->assertCount(1, $restored);
        $this->assertEquals($placement->appliedversionid, $restored[0]->appliedversionid);
    }
    /**
     * A backup cannot select a withdrawn applied catalogue version (bug130).
     */
    public function test_restore_does_not_link_withdrawn_version(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place($item->id, $course->id, 0, policy::NOTIFY);
        $backupid = $this->backup($course->id);
        $DB->set_field(version_manager::TABLE, 'status', status::WITHDRAWN, ['id' => $placement->appliedversionid]);
        $target = $this->restore_into_new_course($backupid);
        $this->assertEmpty(placement_manager::get_for_course($target));
        $this->assertCount(1, get_fast_modinfo($target)->get_cms());
    }

    /**
     * Withdrawn offered versions are discarded while a published applied version stays linked (bug130).
     */
    public function test_restore_drops_unpublished_pending_version(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place($item->id, $course->id, 0, policy::NOTIFY);
        $pending = $generator->publish_new_version($item->id, 'Version two');
        placement_manager::offer($placement->id, $pending->id, '', false);
        $backupid = $this->backup($course->id);
        $DB->set_field(version_manager::TABLE, 'status', status::WITHDRAWN, ['id' => $pending->id]);
        $target = $this->restore_into_new_course($backupid);
        $restored = array_values(placement_manager::get_for_course($target));
        $this->assertCount(1, $restored);
        $this->assertEquals($placement->appliedversionid, $restored[0]->appliedversionid);
        $this->assertEmpty($restored[0]->pendingversionid);
    }

    /**
     * A forged item UUID cannot attach a resource to an xAPI catalogue item (bug130).
     */
    public function test_restore_rejects_wrong_module_for_item(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $course = $this->getDataGenerator()->create_course();
        placement_manager::place($item->id, $course->id, 0, policy::NOTIFY);
        $foreign = $this->getDataGenerator()->get_plugin_generator('mod_nextpool')->create_xapi_item();
        $backupid = $this->backup($course->id);
        foreach (glob($CFG->tempdir . '/backup/' . $backupid . '/activities/*/module.xml') as $path) {
            $xml = file_get_contents($path);
            file_put_contents($path, str_replace($item->uuid, $foreign->uuid, $xml));
        }
        $target = $this->restore_into_new_course($backupid);
        $this->assertEmpty(placement_manager::get_for_course($target));
        $this->assertCount(1, get_fast_modinfo($target)->get_cms());
    }
}
