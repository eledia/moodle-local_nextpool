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

namespace local_nextpool\external;

use core_external\external_api;
use local_nextpool\local\subscription_manager;

/**
 * Tests for the feedback web services (F08, task81; test63, test64).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(add_comment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(update_comment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(delete_comment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(set_comment_resolved::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(set_subscription::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_api::class)]
final class feedback_external_test extends \advanced_testcase {
    /** @var \stdClass Published item available site-wide. */
    private \stdClass $item;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Teacher of the course. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->item = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_published_item();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * Call a function as the module does, including the cleaning of the return value.
     *
     * @param string $function Function name.
     * @param array $args Arguments.
     * @return array Result with error, data and exception.
     */
    private function call(string $function, array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function($function, $args, true);
    }

    /**
     * A teacher posts, edits, marks as done, opens again, unsubscribes and removes through the services.
     */
    public function test_teacher_round_trip(): void {
        $this->setUser($this->teacher);
        $courseid = (int) $this->course->id;
        $result = $this->call('local_nextpool_add_comment', ['itemid' => $this->item->id, 'courseid' => $courseid,
            'content' => 'Please add the emergency numbers.']);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        $commentid = $result['data']['commentid'];
        $this->assertSame(get_string('commentposted', 'local_nextpool'), $result['data']['message']);

        $result = $this->call('local_nextpool_add_comment', ['itemid' => $this->item->id, 'courseid' => $courseid,
            'content' => 'And the address.', 'parentid' => $commentid]);
        $this->assertFalse($result['error']);
        $replyid = $result['data']['commentid'];

        $result = $this->call('local_nextpool_update_comment', ['commentid' => $commentid, 'courseid' => $courseid,
            'content' => 'Please add the emergency numbers on page 2.']);
        $this->assertFalse($result['error']);

        foreach ([true, false] as $resolved) {
            $result = $this->call('local_nextpool_set_comment_resolved', ['commentid' => $commentid,
                'courseid' => $courseid, 'resolved' => $resolved]);
            $this->assertFalse($result['error']);
        }

        $result = $this->call('local_nextpool_set_subscription', ['itemid' => $this->item->id, 'courseid' => $courseid,
            'subscribed' => false]);
        $this->assertFalse($result['error']);
        $this->assertFalse(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->teacher->id));

        // Removing the comment with a reply leaves the placeholder; the result names it.
        $result = $this->call('local_nextpool_delete_comment', ['commentid' => $commentid, 'courseid' => $courseid]);
        $this->assertFalse($result['error']);
        $this->assertSame($commentid, $result['data']['commentid']);
        $result = $this->call('local_nextpool_delete_comment', ['commentid' => $replyid, 'courseid' => $courseid]);
        $this->assertSame(0, $result['data']['commentid']);
    }

    /**
     * Teachers of a course where the item is not available cannot use the services (F08.AC07, test64).
     */
    public function test_no_access_without_availability(): void {
        $category = $this->getDataGenerator()->create_category();
        $hidden = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_published_item([
            'contextid' => \context_coursecat::instance($category->id)->id,
        ]);
        $this->setUser($this->teacher);
        $result = $this->call('local_nextpool_add_comment', ['itemid' => $hidden->id, 'courseid' => $this->course->id,
            'content' => 'Hello']);
        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);

        // Without a course the teacher has no editorial access either.
        $result = $this->call('local_nextpool_set_subscription', ['itemid' => $this->item->id, 'courseid' => 0,
            'subscribed' => true]);
        $this->assertTrue($result['error']);
    }

    /**
     * The fragment returns the section content for the course view and refuses another context.
     */
    public function test_fragment(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/nextpool/lib.php');
        $this->setUser($this->teacher);
        $context = \context_course::instance($this->course->id);
        $args = ['itemid' => $this->item->id, 'courseid' => $this->course->id, 'filter' => 'all'];
        $html = \local_nextpool_output_fragment_feedback(['context' => $context] + $args);
        $this->assertStringContainsString('local-nextpool-feedback-heading', $html);

        $this->expectException(\moodle_exception::class);
        \local_nextpool_output_fragment_feedback(['context' => \context_system::instance()] + $args);
    }
}
