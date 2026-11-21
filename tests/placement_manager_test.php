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

use local_nextpool\event\placement_created;
use local_nextpool\event\placement_updated;
use local_nextpool\local\catalogue;
use local_nextpool\local\category_manager;
use local_nextpool\local\content_type\file;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\status;
use local_nextpool\task\apply_update;
use local_nextpool\task\distribute_version;
use local_nextpool\task\send_update_notice;

/**
 * Tests for placing catalogue items in courses and updating them (F05, F06).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(placement_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalogue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(apply_update::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(send_update_notice::class)]
final class placement_manager_test extends \advanced_testcase {
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
     * Content of the main file of a resource.
     *
     * @param int $cmid Course module id.
     * @return \stored_file
     */
    private function get_resource_file(int $cmid): \stored_file {
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cmid)->id,
            'mod_resource',
            'content',
            0,
            'sortorder DESC, id',
            false
        );
        $this->assertCount(1, $files);
        return reset($files);
    }

    /**
     * Placing creates a File resource with the snapshot, licence and authors; an auto update keeps the course module.
     */
    public function test_place_and_auto_update_keeps_course_module_and_completion(): void {
        global $DB;
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 2]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $item = $this->generator->create_published_item(['title' => 'Fire safety', 'license' => 'cc-4.0'], 'version 1');

        $sink = $this->redirectEvents();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 1, policy::AUTO);
        $this->assertCount(1, array_filter($sink->get_events(), fn($e) => $e instanceof placement_created));
        $sink->close();

        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('resource', $cm->modname);
        $this->assertSame('Fire safety', $cm->name);
        $this->assertEquals(1, $cm->sectionnum);
        $file = $this->get_resource_file($cm->id);
        $this->assertSame('version 1', $file->get_content());
        $this->assertSame('cc-4.0', $file->get_license());
        $this->assertStringContainsString('Author', (string) $file->get_author());
        $this->assertEquals($item->currentversionid, $placement->appliedversionid);

        // A learner completes the activity.
        $DB->update_record('course_modules', ['id' => $cm->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => COMPLETION_VIEW_REQUIRED]);
        rebuild_course_cache($course->id, true);
        $cm = get_fast_modinfo($course)->get_cm($cm->id);
        (new \completion_info($course))->set_module_viewed($cm, $student->id);
        $revision = (int) $DB->get_field('resource', 'revision', ['id' => $cm->instance]);

        // A new version is published: the update is queued and applied in place.
        $version2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);
        $this->assertEquals($placement->appliedversionid, placement_manager::get((int) $placement->id)->appliedversionid);
        $sink = $this->redirectEvents();
        $this->expectOutputRegex('/updated to version 2/');
        $this->runAdhocTasks(apply_update::class);
        $this->assertCount(1, array_filter($sink->get_events(), fn($e) => $e instanceof placement_updated));
        $sink->close();

        $placement = placement_manager::get((int) $placement->id);
        $this->assertEquals($version2->id, $placement->appliedversionid);
        $this->assertEquals($cm->id, $placement->cmid, 'The course module stays.');
        $this->assertSame('version 2', $this->get_resource_file($cm->id)->get_content());
        $this->assertGreaterThan($revision, (int) $DB->get_field('resource', 'revision', ['id' => $cm->instance]));
        $this->assertEquals(COMPLETION_COMPLETE, $DB->get_field('course_modules_completion', 'completionstate', [
            'coursemoduleid' => $cm->id,
            'userid' => $student->id,
        ]), 'Learner completion survives the update.');

        $log = placement_manager::get_log((int) $placement->id);
        $this->assertSame([placement_manager::ACTION_UPDATE, placement_manager::ACTION_PLACE], array_column($log, 'action'));
        $this->assertSame('ok', $log[0]->result);
        $this->assertEquals(0, $log[0]->userid, 'Automatic updates have no acting user.');
    }

    /**
     * Notify waits for the teacher, pinned keeps the version; apply and skip resolve the offer.
     */
    public function test_notify_and_pinned(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $notify = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $pinned = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $version1 = (int) $notify->appliedversionid;

        $version2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(apply_update::class));
        $this->assertEquals($version2->id, placement_manager::get((int) $notify->id)->pendingversionid);
        $this->assertNull(placement_manager::get((int) $pinned->id)->pendingversionid);
        $this->assertEquals(1, placement_manager::count_pending((int) $course->id));
        $this->assertEquals($version1, placement_manager::get((int) $pinned->id)->appliedversionid);

        placement_manager::skip((int) $notify->id);
        $this->assertNull(placement_manager::get((int) $notify->id)->pendingversionid);
        $this->assertEquals($version1, placement_manager::get((int) $notify->id)->appliedversionid);

        $applied = placement_manager::apply((int) $pinned->id, (int) $version2->id, 2);
        $this->assertEquals($version2->id, $applied->appliedversionid);
        $this->assertSame('version 2', $this->get_resource_file((int) $pinned->cmid)->get_content());
        $this->assertEquals(2, placement_manager::get_log((int) $pinned->id)[0]->userid);
    }

    /**
     * A placement never goes back to an older version, neither manually nor by a late automatic update.
     */
    public function test_apply_never_downgrades(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $version2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);
        $version3 = $this->generator->publish_new_version((int) $item->id, 'version 3');

        placement_manager::apply((int) $placement->id, (int) $version3->id);
        $logcount = count(placement_manager::get_log((int) $placement->id));
        $this->assertTrue(placement_manager::is_up_to_date(placement_manager::get((int) $placement->id), (int) $version2->id));

        $applied = placement_manager::apply((int) $placement->id, (int) $version2->id);
        $this->assertEquals($version3->id, $applied->appliedversionid);
        $this->assertSame('version 3', $this->get_resource_file((int) $placement->cmid)->get_content());

        // A late automatic update with the older version neither applies nor offers it.
        $this->assertTrue(placement_manager::apply_automatically((int) $placement->id, (int) $version2->id));
        $task = new apply_update();
        $task->set_custom_data(['placementid' => (int) $placement->id, 'versionid' => (int) $version2->id]);
        $this->expectOutputRegex('/already up to date/');
        $task->execute();

        $placement = placement_manager::get((int) $placement->id);
        $this->assertEquals($version3->id, $placement->appliedversionid);
        $this->assertNull($placement->pendingversionid);
        $this->assertCount($logcount, placement_manager::get_log((int) $placement->id));
        $this->assertSame('version 3', $this->get_resource_file((int) $placement->cmid)->get_content());
    }

    /**
     * An offered version notifies the teachers of the course, not the students.
     */
    public function test_offer_notifies_teachers(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $item = $this->generator->create_published_item();
        $notify = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $version2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(send_update_notice::class));

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/1 teacher\(s\) notified/');
        $this->runAdhocTasks(send_update_notice::class);
        $messages = $sink->get_messages_by_component_and_type('local_nextpool', 'updateavailable');
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, $messages[0]->useridto);
        $this->assertStringContainsString('placements.php?courseid=' . $course->id, $messages[0]->contexturl);

        // A version that is no longer pending is not announced.
        placement_manager::skip((int) $notify->id);
        $sink->clear();
        $task = new send_update_notice();
        $task->set_custom_data(['placementid' => (int) $notify->id, 'versionid' => (int) $version2->id]);
        \core\task\manager::queue_adhoc_task($task);
        $this->runAdhocTasks(send_update_notice::class);
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * Switching to auto queues the current version; switching to notify offers it.
     */
    public function test_set_policy_catches_up(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $first = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $second = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $version2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);

        placement_manager::set_policy((int) $first->id, policy::NOTIFY);
        $this->assertEquals($version2->id, placement_manager::get((int) $first->id)->pendingversionid);

        placement_manager::set_policy((int) $second->id, policy::AUTO);
        $this->expectOutputRegex('/updated to version 2/');
        $this->runAdhocTasks(apply_update::class);
        $this->assertEquals($version2->id, placement_manager::get((int) $second->id)->appliedversionid);
    }

    /**
     * Only published, available, placeable items can be placed.
     */
    public function test_place_rejects(): void {
        $category = $this->getDataGenerator()->create_category();
        $othercategory = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);

        $draft = $this->generator->create_item();
        $elsewhere = $this->generator->create_published_item(['contextid' => \context_coursecat::instance($othercategory->id)->id]);
        $incategory = $this->generator->create_published_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $assign = $this->generator->create_published_item(['type' => 'assign']);

        $cases = [[$draft, 'error_notpublished'], [$elsewhere, 'error_notavailableincourse'], [$assign, 'error_notplaceable']];
        foreach ($cases as [$item, $error]) {
            try {
                placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
                $this->fail('Placing must fail with ' . $error);
            } catch (\moodle_exception $e) {
                $this->assertSame($error, $e->errorcode);
            }
        }
        $this->assertNotEmpty(placement_manager::place((int) $incategory->id, (int) $course->id, 0, policy::AUTO)->id);

        $ids = array_keys(catalogue::get_items((int) $course->id));
        sort($ids);
        $expected = [(int) $incategory->id, (int) $assign->id];
        sort($expected);
        $this->assertEquals($expected, $ids, 'The course sees published items of its category and site-wide items.');
    }

    /**
     * The course catalogue filters by tag and lists the tags of the items available in the course (F10).
     */
    public function test_catalogue_tag_filter(): void {
        $course = $this->getDataGenerator()->create_course();
        $safety = $this->generator->create_published_item(['title' => 'Fire safety']);
        $welcome = $this->generator->create_published_item(['title' => 'Welcome']);
        $draft = $this->generator->create_item(['title' => 'Draft']);
        \local_nextpool\local\item_manager::set_tags((int) $safety->id, ['Safety', 'Onboarding']);
        \local_nextpool\local\item_manager::set_tags((int) $welcome->id, ['Onboarding']);
        \local_nextpool\local\item_manager::set_tags((int) $draft->id, ['Hidden']);

        $this->assertSame(['onboarding' => 'Onboarding', 'safety' => 'Safety'], catalogue::get_tags((int) $course->id));
        $ids = fn(string $tag) => array_map('intval', array_keys(catalogue::get_items((int) $course->id, '', 0, '', $tag)));
        $this->assertSame([(int) $safety->id], $ids('Safety'));
        $this->assertEqualsCanonicalizing([(int) $safety->id, (int) $welcome->id], $ids('onboarding'));
        $this->assertSame([], $ids('Hidden'));
        $this->assertCount(2, catalogue::get_items((int) $course->id));
    }

    /**
     * The catalogue category filter includes subcategories; an unknown category id is ignored instead of failing.
     */
    public function test_catalogue_category_filter(): void {
        $course = $this->getDataGenerator()->create_course();
        $safety = category_manager::create('Safety');
        $fire = category_manager::create('Fire', (int) $safety->id);
        $infire = $this->generator->create_published_item(['categoryid' => $fire->id]);
        $uncategorised = $this->generator->create_published_item();

        $this->assertSame([(int) $infire->id], array_map('intval', array_keys(
            catalogue::get_items((int) $course->id, '', (int) $safety->id)
        )));
        $all = array_map('intval', array_keys(catalogue::get_items((int) $course->id, '', (int) $fire->id + 1000)));
        $this->assertEqualsCanonicalizing([(int) $infire->id, (int) $uncategorised->id], $all);
    }

    /**
     * Withdrawn items leave the catalogue, but their placements stay.
     */
    public function test_withdrawn_item_keeps_placements(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);

        item_manager::set_status((int) $item->id, status::WITHDRAWN);
        $this->assertSame([], catalogue::get_items((int) $course->id));
        $this->assertEquals(1, placement_manager::count_for_item((int) $item->id));
        $this->assertNotEmpty(placement_manager::get((int) $placement->id));
    }

    /**
     * Deleting the course module or the course removes the placement and its log.
     */
    public function test_deletion_removes_placements(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $first = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $second = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);

        // Moodle 5.2 moved module deletion into the format actions; older versions use the global function.
        $cmactions = \core_courseformat\formatactions::cm((int) $course->id);
        if (method_exists($cmactions, 'delete')) {
            $cmactions->delete((int) $first->cmid);
        } else {
            course_delete_module((int) $first->cmid);
        }
        $this->assertFalse($DB->record_exists(placement_manager::TABLE, ['id' => $first->id]));
        $this->assertFalse($DB->record_exists(placement_manager::LOG_TABLE, ['placementid' => $first->id]));

        delete_course($course, false);
        $this->assertFalse($DB->record_exists(placement_manager::TABLE, ['id' => $second->id]));
    }
}
