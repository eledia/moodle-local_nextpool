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

use local_nextpool\fixtures\counting_content_type;
use local_nextpool\fixtures\failing_book_content_type;
use local_nextpool\fixtures\failing_update_content_type;
use local_nextpool\hook\collect_content_types;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\task\apply_update;
use local_nextpool\task\distribute_version;
use local_nextpool\task\send_update_notice;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/counting_content_type.php');
require_once(__DIR__ . '/fixtures/failing_book_content_type.php');
require_once(__DIR__ . '/fixtures/failing_update_content_type.php');

/**
 * Tests for offers, withdrawals, automatic updates and atomic placing (bug53, bug54, bug55, bug59, bug63).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(placement_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nextpool\local\content_type\base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(send_update_notice::class)]
final class update_engine_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        item_types::reset_cache();
        counting_content_type::$checks = 0;
        counting_content_type::$conflict = false;
    }

    #[\Override]
    protected function tearDown(): void {
        item_types::reset_cache();
        parent::tearDown();
    }

    /**
     * Use a handler for the type xapi in this test.
     *
     * @param string $class Handler class.
     */
    private function register(string $class): void {
        $this->redirectHook(collect_content_types::class, function (collect_content_types $hook) use ($class): void {
            $hook->add('xapi', $class);
        });
        item_types::reset_cache();
    }

    /**
     * Number of queued adhoc tasks of a class.
     *
     * @param string $class Task class.
     * @return int
     */
    private function count_tasks(string $class): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => '\\' . ltrim($class, '\\')]);
    }

    /**
     * Log actions of a placement, oldest first.
     *
     * @param \stdClass $placement Placement.
     * @return string[]
     */
    private function actions(\stdClass $placement): array {
        return array_reverse(array_column(placement_manager::get_log((int) $placement->id), 'action'));
    }

    /**
     * A withdrawn version is no longer offered; the newest remaining published version takes its place (bug53).
     */
    public function test_withdrawn_offer_is_taken_back(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $v2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $v3 = $this->generator->publish_new_version((int) $item->id, 'version 3');
        $this->runAdhocTasks(distribute_version::class);
        $this->assertEquals($v3->id, placement_manager::get((int) $placement->id)->pendingversionid);

        version_manager::set_status((int) $v3->id, status::WITHDRAWN);
        $placement = placement_manager::get((int) $placement->id);
        $this->assertEquals($v2->id, $placement->pendingversionid, 'Version 2 is still newer than the one in the course.');
        $this->assertContains(placement_manager::ACTION_WITHDRAW, $this->actions($placement));

        version_manager::set_status((int) $v2->id, status::WITHDRAWN);
        $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid);
        $this->assertSame(0, placement_manager::count_pending((int) $course->id), 'No banner for a withdrawn version.');

        // An automatic update queued before the withdrawal ends quietly instead of offering the withdrawn version.
        $auto = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $v4 = $this->generator->publish_new_version((int) $item->id, 'version 4');
        $this->generator->publish_new_version((int) $item->id, 'version 5');
        version_manager::set_status((int) $v4->id, status::WITHDRAWN);
        $this->assertTrue(placement_manager::apply_automatically((int) $auto->id, (int) $v4->id));
        $this->assertNull(placement_manager::get((int) $auto->id)->pendingversionid);
    }

    /**
     * A version is offered once: distributing it again, or publishing it again after the teachers skipped it,
     * neither repeats the offer nor the notice (bug54).
     */
    public function test_no_repeated_offers(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $v2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);
        $this->assertSame(1, $this->count_tasks(send_update_notice::class));

        $this->assertSame(0, placement_manager::distribute((int) $v2->id));
        $this->assertSame(1, $this->count_tasks(send_update_notice::class));
        $this->assertSame([placement_manager::ACTION_PLACE, placement_manager::ACTION_OFFER], $this->actions($placement));

        placement_manager::skip((int) $placement->id);
        version_manager::set_status((int) $v2->id, status::WITHDRAWN);
        version_manager::set_status((int) $v2->id, status::PUBLISHED);
        $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid, 'Skipped before.');
        $this->assertSame(1, $this->count_tasks(send_update_notice::class));

        // Policy auto: an applied version is not queued again when it is published again.
        $auto = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $this->assertSame(0, placement_manager::distribute((int) $v2->id));
        $this->assertSame(0, $this->count_tasks(apply_update::class));
        $this->assertNotEmpty($auto->id);
    }

    /**
     * An automatic update checks the version once, inside the lock (bug59).
     */
    public function test_automatic_update_checks_once(): void {
        global $DB;
        $this->register(counting_content_type::class);
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'xapi'], "https://example.com/1\n", 'link.txt');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $v2 = $this->generator->publish_new_version((int) $item->id, "https://example.com/2\n", 'link.txt');

        $this->assertTrue(placement_manager::apply_automatically((int) $placement->id, (int) $v2->id));
        $this->assertSame(1, counting_content_type::$checks);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('https://example.com/2', $DB->get_field('url', 'externalurl', ['id' => $cm->instance]));
    }

    /**
     * A failure after the course module exists removes it again, in the handler and in the catalogue (bug55).
     */
    public function test_place_is_all_or_nothing(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();

        $this->register(failing_book_content_type::class);
        $zip = make_request_directory() . '/book.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addFromString('01_intro.html', '<html><head><title>Intro</title></head><body><p>Hi</p></body></html>');
        $archive->close();
        $book = $this->generator->create_published_item(['type' => 'xapi'], (string) file_get_contents($zip), 'book.zip');
        try {
            placement_manager::place((int) $book->id, (int) $course->id, 0, policy::NOTIFY);
            $this->fail('The import fails.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_bookempty', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]), 'No empty book is left.');

        $this->register(counting_content_type::class);
        counting_content_type::$conflict = true;
        $link = $this->generator->create_published_item(['type' => 'xapi'], "https://example.com/1\n", 'link.txt');
        try {
            placement_manager::place((int) $link->id, (int) $course->id, 0, policy::NOTIFY);
            $this->fail('The placement row cannot be written.');
        } catch (\dml_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]), 'No orphaned activity.');
        $this->assertSame(0, $DB->count_records(placement_manager::TABLE, ['courseid' => $course->id]));
    }

    /**
     * Switching to "ask me first" logs the offer (Q-20); an update or notice for a course module that is being
     * deleted ends quietly instead of failing for ever (Q-24).
     */
    public function test_policy_offer_and_deleted_module(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $v2 = $this->generator->publish_new_version((int) $item->id, 'version 2');

        placement_manager::set_policy((int) $placement->id, policy::NOTIFY);
        $this->assertEquals($v2->id, placement_manager::get((int) $placement->id)->pendingversionid);
        $actions = $this->actions($placement);
        $this->assertSame(placement_manager::ACTION_OFFER, end($actions));

        $auto = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $v3 = $this->generator->publish_new_version((int) $item->id, 'version 3');
        $this->runAdhocTasks(distribute_version::class);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $auto->cmid]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $placement->cmid]);
        rebuild_course_cache((int) $course->id, true);
        $this->assertNull(placement_manager::get_cm($auto));
        $this->assertTrue(placement_manager::apply_automatically((int) $auto->id, (int) $v3->id));

        $task = new send_update_notice();
        $task->set_custom_data(['placementid' => (int) $placement->id, 'versionid' => (int) $v3->id]);
        $DB->set_field(placement_manager::TABLE, 'pendingversionid', $v3->id, ['id' => $placement->id]);
        ob_start();
        $task->execute();
        $this->assertStringContainsString('no longer exists', (string) ob_get_clean());
    }

    /**
     * A queued update obeys the rule selected after distribution (bug240, N-03).
     *
     * @param string $policy Rule selected before the queued update runs.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('queued_policy_provider')]
    public function test_queued_update_obeys_current_policy(string $policy): void {
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item([], 'version 1');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $version = $this->generator->publish_new_version((int) $item->id, 'version 2');
        $this->runAdhocTasks(distribute_version::class);
        $this->assertSame(1, $this->count_tasks(apply_update::class));

        placement_manager::set_policy((int) $placement->id, $policy);
        $this->expectOutputRegex('/Placement [0-9]+ (updated to version 2|kept its current version)\./');
        $this->runAdhocTasks(apply_update::class);
        $current = placement_manager::get((int) $placement->id);
        $automatic = $policy === policy::AUTO;
        $this->assertEquals($automatic ? $version->id : $placement->appliedversionid, $current->appliedversionid);
        $this->assertEquals($policy === policy::NOTIFY ? $version->id : null, $current->pendingversionid);
        $files = get_file_storage()->get_area_files(
            \context_module::instance((int) $placement->cmid)->id,
            'mod_resource',
            'content',
            0,
            'id',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame($automatic ? 'version 2' : 'version 1', reset($files)->get_content());
        $this->assertSame(0, $this->count_tasks(apply_update::class));

        // A teacher may still explicitly apply the offered version or override a fixed version.
        placement_manager::apply((int) $placement->id, (int) $version->id, (int) get_admin()->id);
        $this->assertEquals($version->id, placement_manager::get((int) $placement->id)->appliedversionid);
    }

    /**
     * Rules a teacher can select while an automatic update is queued.
     *
     * @return array
     */
    public static function queued_policy_provider(): array {
        return ['fixed' => [policy::PINNED], 'confirmation' => [policy::NOTIFY], 'automatic' => [policy::AUTO]];
    }

    /**
     * An in-place update that fails inside the handler's transaction leaves no transaction open: the half-written
     * activity is rolled back, and the failure, the offer and the notice are kept (bug160).
     */
    public function test_failed_update_rolls_back_and_offers(): void {
        global $DB;
        // The transactions under test must be the outermost ones.
        $this->preventResetByRollback();
        $this->register(failing_update_content_type::class);
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'xapi'], "https://example.com/1\n", 'link.txt');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $v2 = $this->generator->publish_new_version((int) $item->id, "https://example.com/2\n", 'link.txt');
        $notices = $this->count_tasks(send_update_notice::class);

        $this->assertFalse(placement_manager::apply_automatically((int) $placement->id, (int) $v2->id));
        $this->assertFalse($DB->is_transaction_started(), 'No transaction is left open.');
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('https://example.com/1', $DB->get_field('url', 'externalurl', ['id' => $cm->instance]));
        $placement = placement_manager::get((int) $placement->id);
        $this->assertEquals($v2->id, $placement->pendingversionid);
        $this->assertEquals($item->currentversionid, $placement->appliedversionid);
        $actions = $this->actions($placement);
        $this->assertSame([placement_manager::ACTION_UPDATE, placement_manager::ACTION_DOWNGRADE], array_slice($actions, -2));
        $this->assertSame($notices + 1, $this->count_tasks(send_update_notice::class));
    }

    /**
     * Switching the policy does not offer a version older than the one in the course: after the applied version
     * was withdrawn, the current version cannot be applied and would leave a banner for ever (bug166).
     */
    public function test_policy_change_offers_no_older_version(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::PINNED);
        $v2 = $this->generator->publish_new_version((int) $item->id, 'version 2');
        placement_manager::apply((int) $placement->id, (int) $v2->id);
        version_manager::set_status((int) $v2->id, status::WITHDRAWN);

        $placement = placement_manager::set_policy((int) $placement->id, policy::NOTIFY);
        $this->assertNull($placement->pendingversionid, 'Version 1 is not offered.');
        $this->assertSame(0, placement_manager::count_pending((int) $course->id));
        $tasks = $this->count_tasks(apply_update::class);
        placement_manager::set_policy((int) $placement->id, policy::AUTO);
        $this->assertSame($tasks, $this->count_tasks(apply_update::class), 'No update to version 1 is queued.');
    }

    /**
     * A publishing transaction only queues distribution after commit; no placement is visited in the request (bug164).
     */
    public function test_distribution_runs_after_commit(): void {
        global $DB;
        // The transactions under test must be the outermost ones.
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $source = \local_nextpool\local\source\manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'handout.pdf', 'version 2');
        $v2 = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        version_manager::set_status((int) $v2->id, status::REVIEW);

        $transaction = $DB->start_delegated_transaction();
        version_manager::set_status((int) $v2->id, status::PUBLISHED);
        $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid, 'Not inside the transaction.');
        $this->assertSame(1, $this->count_tasks(distribute_version::class), 'Only the earlier version is queued.');
        $transaction->allow_commit();

        $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid, 'Commit only queues distribution.');
        $this->assertSame(2, $this->count_tasks(distribute_version::class));
        $this->runAdhocTasks(distribute_version::class);
        $this->assertEquals($v2->id, placement_manager::get((int) $placement->id)->pendingversionid);
    }
}
