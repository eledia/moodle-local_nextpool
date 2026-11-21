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
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;

/**
 * Placement policy AJAX permissions and persistence (task175, q135a).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(set_placement_policy::class)]
final class set_placement_policy_test extends \advanced_testcase {
    /**
     * @var \stdClass Placement.
     */
    private \stdClass $placement;
    /**
     * @var \stdClass Course.
     */
    private \stdClass $course;
    /**
     * @var \stdClass Teacher.
     */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('local_nextpool');
        $item = $plugin->create_published_item(['title' => 'Safety & evacuation']);
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->placement = placement_manager::place((int) $item->id, (int) $this->course->id, 0, policy::AUTO);
        $this->setUser($this->teacher);
    }

    /**
     * Invoke the registered AJAX function including authentication and output validation.
     *
     * @param string $value Policy.
     * @param int|null $placementid Optional target.
     * @return array External response.
     */
    private function call(string $value, ?int $placementid = null): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('local_nextpool_set_placement_policy', [
            'placementid' => $placementid ?? (int) $this->placement->id, 'policy' => $value,
        ], true);
    }

    /**
     * Every policy persists and returns the accessible current name.
     */
    public function test_teacher_changes_all_policies(): void {
        foreach ([policy::PINNED, policy::NOTIFY, policy::AUTO] as $value) {
            $result = $this->call($value);
            $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
            $this->assertSame($value, $result['data']['policy']);
            $this->assertSame(policy::get_name($value), $result['data']['label']);
            $this->assertSame(policy::get_name($value) . ': Safety & evacuation', $result['data']['name']);
            $this->assertSame(get_string('policychanged', 'local_nextpool'), $result['data']['message']);
            $this->assertSame($value, placement_manager::get((int) $this->placement->id)->policy);
        }
    }

    /**
     * Unknown policies are rejected without changing the placement.
     */
    public function test_invalid_policy_preserves_rule(): void {
        $result = $this->call('invalid');
        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidpolicy', $result['exception']->errorcode);
        $this->assertSame(policy::AUTO, placement_manager::get((int) $this->placement->id)->policy);
    }

    /**
     * A learner cannot update the rule.
     */
    public function test_student_cannot_change_policy(): void {
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        $result = $this->call(policy::PINNED);
        $this->assertTrue($result['error']);
        $this->assertSame(policy::AUTO, placement_manager::get((int) $this->placement->id)->policy);
    }

    /**
     * Teaching another course does not grant access to this placement.
     */
    public function test_other_course_teacher_cannot_change_policy(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $result = $this->call(policy::PINNED);
        $this->assertTrue($result['error']);
        $this->assertSame(policy::AUTO, placement_manager::get((int) $this->placement->id)->policy);
    }

    /**
     * A per-activity prohibition must also apply to AJAX.
     */
    public function test_module_permission_is_required(): void {
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_module::instance($this->placement->cmid);
        role_assign($roleid, $this->teacher->id, $context->id);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, $context->id);
        $result = $this->call(policy::PINNED);
        $this->assertTrue($result['error']);
        $this->assertSame(policy::AUTO, placement_manager::get((int) $this->placement->id)->policy);
    }

    /**
     * The status returned to AJAX reflects changes to a pending update immediately.
     */
    public function test_status_follows_changed_rule(): void {
        $this->setAdminUser();
        $plugin = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $plugin->publish_new_version((int) $this->placement->itemid, 'Second edition');
        $this->setUser($this->teacher);
        $result = $this->call(policy::NOTIFY);
        $this->assertFalse($result['error']);
        $this->assertStringContainsString(get_string('updateoffered', 'local_nextpool', 2), $result['data']['statushtml']);
        $this->assertNotEmpty(placement_manager::get((int) $this->placement->id)->pendingversionid);
        $result = $this->call(policy::PINNED);
        $this->assertFalse($result['error']);
        $this->assertStringNotContainsString(get_string('updateoffered', 'local_nextpool', 2), $result['data']['statushtml']);
        $this->assertStringContainsString(get_string('neweravailable', 'local_nextpool', 2), $result['data']['statushtml']);
        $this->assertEmpty(placement_manager::get((int) $this->placement->id)->pendingversionid);
    }

    /**
     * Catalogue access is required independently of the course management permission.
     */
    public function test_catalogue_permission_is_required(): void {
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($this->course->id);
        role_assign($roleid, $this->teacher->id, $context->id);
        assign_capability('local/nextpool:viewcatalogue', CAP_PROHIBIT, $roleid, $context->id);
        $result = $this->call(policy::PINNED);
        $this->assertTrue($result['error']);
        $this->assertSame(policy::AUTO, placement_manager::get((int) $this->placement->id)->policy);
    }

    /**
     * Missing targets fail without disclosing or changing another placement.
     */
    public function test_missing_placement_is_rejected(): void {
        $result = $this->call(policy::PINNED, PHP_INT_MAX);
        $this->assertTrue($result['error']);
        $this->assertSame(policy::AUTO, placement_manager::get((int) $this->placement->id)->policy);
    }
}
