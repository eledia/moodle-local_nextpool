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

use local_nextpool\local\item_competencies;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\skills_bridge;

/**
 * Optional hand-over of levels to LernHive Skills when placing (F14, Skills feat28, test30).
 *
 * Runs in separate processes: the stand-in Skills API must not leak into other tests.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(skills_bridge::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class skills_bridge_test extends \advanced_testcase {
    /** @var array[] Link results as item_competencies::link_to_cm() returns them. */
    private const RESULTS = [
        ['competency' => 'SAFE-01', 'level' => 2, 'function' => 'explain', 'competencyid' => 7, 'result' => 'linked'],
        ['competency' => 'MISSING', 'level' => 3, 'function' => 'practice', 'competencyid' => 0, 'result' => 'unknown'],
        ['competency' => 'SAFE-02', 'level' => 4, 'function' => 'apply', 'competencyid' => 8, 'result' => 'linked'],
    ];

    /**
     * Without the Skills API nothing is called, and placing works as before.
     */
    public function test_without_skills(): void {
        if (class_exists(skills_bridge::API)) {
            $this->markTestSkipped('LernHive Skills or its stand-in is loaded.');
        }
        $this->assertFalse(skills_bridge::is_available());
        $this->assertSame(-1, skills_bridge::propose_levels(5, 'uuid', self::RESULTS));
    }

    /**
     * With the Skills API every linked competency is proposed by argument name; a refusal is skipped.
     */
    public function test_with_skills(): void {
        $this->load_stand_in();
        \local_lernhive_skills\api::$calls = [];
        \local_lernhive_skills\api::$fail = 'SAFE-02';
        \local_lernhive_skills\api::$sources = ['manual', 'ai', 'contenthub', 'nextpool'];

        $this->assertTrue(skills_bridge::is_available());
        $this->assertSame(1, skills_bridge::propose_levels(5, 'abc-uuid', self::RESULTS));
        $this->assertDebuggingCalled();
        $this->assertSame([[
            'cmid' => 5,
            'idnumber' => 'SAFE-01',
            'level' => 2,
            'source' => 'nextpool',
            'itemuuid' => 'abc-uuid',
            'function' => 'explain',
        ]], \local_lernhive_skills\api::$calls);
    }

    /**
     * A Skills release that does not know the source nextpool yet (before q34) refuses every
     * proposal; the bridge reports each refusal and proposes nothing.
     */
    public function test_source_refused(): void {
        $this->load_stand_in();
        \local_lernhive_skills\api::$calls = [];
        \local_lernhive_skills\api::$fail = null;
        \local_lernhive_skills\api::$sources = ['manual', 'ai'];

        $this->assertSame(0, skills_bridge::propose_levels(5, 'abc-uuid', self::RESULTS));
        $this->assertCount(2, $this->getDebuggingMessages());
        $this->resetDebugging();
        $this->assertSame([], \local_lernhive_skills\api::$calls);
    }

    /**
     * Placing an item hands the linked levels to Skills and logs the count.
     */
    public function test_place_proposes_levels(): void {
        global $DB;
        $this->load_stand_in();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');
        \local_lernhive_skills\api::$calls = [];
        \local_lernhive_skills\api::$fail = null;
        \local_lernhive_skills\api::$sources = ['manual', 'ai', 'contenthub', 'nextpool'];
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $lp->create_framework();
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'SAFE-01']);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $generator->create_item_competencies((int) $item->id, [
            ['competency' => 'SAFE-01', 'level' => 3, 'function' => 'practice'],
            ['competency' => 'MISSING', 'level' => 1, 'function' => 'explain'],
        ]);
        $course = $this->getDataGenerator()->create_course();

        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $this->assertCount(1, \local_lernhive_skills\api::$calls);
        $this->assertSame((int) $placement->cmid, \local_lernhive_skills\api::$calls[0]['cmid']);
        $this->assertSame($item->uuid, \local_lernhive_skills\api::$calls[0]['itemuuid']);
        $this->assertSame(3, \local_lernhive_skills\api::$calls[0]['level']);
        $message = $DB->get_field(placement_manager::LOG_TABLE, 'message', [
            'placementid' => $placement->id,
            'action' => placement_manager::ACTION_COMPETENCY,
        ]);
        $this->assertSame('SAFE-01: ' . item_competencies::LINKED . '; MISSING: unknown; skills: 1', $message);
    }

    /**
     * Load the stand-in for the Skills API unless the real plugin is installed.
     */
    private function load_stand_in(): void {
        if (class_exists(skills_bridge::API) && !property_exists(skills_bridge::API, 'calls')) {
            $this->markTestSkipped('LernHive Skills is installed; its own tests cover the API.');
        }
        require_once(__DIR__ . '/fixtures/lernhive_skills_api.php');
    }
}
