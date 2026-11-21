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

use local_nextpool\local\access;
use local_nextpool\local\catalogue;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\version_manager;

/**
 * Items available only in the course they were uploaded from (M06 task78a, test90, adr30).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalogue::class)]
final class course_bound_item_test extends \advanced_testcase {
    /** @var \core_course_category Course category of both courses. */
    private \core_course_category $category;
    /** @var \core_course_category Another course category. */
    private \core_course_category $othercategory;
    /** @var \stdClass Course the item was uploaded from. */
    private \stdClass $course;
    /** @var \stdClass Another course in the same category. */
    private \stdClass $othercourse;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->category = $this->getDataGenerator()->create_category();
        $this->othercategory = $this->getDataGenerator()->create_category();
        $this->course = $this->getDataGenerator()->create_course(['category' => $this->category->id]);
        $this->othercourse = $this->getDataGenerator()->create_course(['category' => $this->category->id]);
    }

    /**
     * Published xAPI item bound to the course, with the fixture package as version 1.
     *
     * @return \stdClass
     */
    private function create_course_item(): \stdClass {
        /** @var \mod_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        return $generator->create_xapi_item([
            'contextid' => \context_course::instance($this->course->id)->id,
            'origincourseid' => $this->course->id,
        ]);
    }

    /**
     * User with the given editorial capabilities in a context.
     *
     * @param string[] $capabilities Capabilities.
     * @param \context $context Context of the role assignment.
     * @return \stdClass User.
     */
    private function create_editor(array $capabilities, \context $context): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Only supported upload types uploaded from that very course may live in a course context.
     */
    public function test_course_context_rules(): void {
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $coursecontextid = \context_course::instance($this->course->id)->id;

        $item = $generator->create_item(['type' => 'xapi', 'contextid' => $coursecontextid,
            'origincourseid' => $this->course->id]);
        $this->assertEquals($coursecontextid, $item->contextid);
        $this->assertEquals($this->course->id, $item->origincourseid);

        $invalid = [
            'other type' => ['type' => 'book', 'origincourseid' => $this->course->id],
            'no origin' => ['type' => 'xapi'],
            'other origin' => ['type' => 'xapi', 'origincourseid' => $this->othercourse->id],
        ];
        foreach ($invalid as $name => $record) {
            try {
                $generator->create_item($record + ['contextid' => $coursecontextid]);
                $this->fail("Accepted: $name");
            } catch (\moodle_exception $e) {
                $this->assertSame('error_invalidcontext', $e->errorcode, $name);
            }
        }
    }

    /**
     * The own course finds the item in the catalogue (q90), other courses do not (M06.AC04).
     */
    public function test_catalogue_visibility(): void {
        $item = $this->create_course_item();

        $this->assertTrue(catalogue::is_available_in_course($item, (int) $this->course->id));
        $this->assertArrayHasKey($item->id, catalogue::get_items((int) $this->course->id));
        $this->assertSame(1, catalogue::count_items((int) $this->course->id));

        $this->assertFalse(catalogue::is_available_in_course($item, (int) $this->othercourse->id));
        $this->assertArrayNotHasKey($item->id, catalogue::get_items((int) $this->othercourse->id));
        $this->assertSame(0, catalogue::count_items((int) $this->othercourse->id));
    }

    /**
     * Editors of the course's category and of the site see the item, editors of other categories do not (TD-402).
     */
    public function test_editorial_list(): void {
        $item = $this->create_course_item();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $siteitem = $generator->create_item();

        $editors = [
            'category' => [$this->create_editor([access::MANAGE], \context_coursecat::instance($this->category->id)), true],
            'site' => [$this->create_editor([access::REVIEW], \context_system::instance()), true],
            'other category' => [
                $this->create_editor([access::MANAGE], \context_coursecat::instance($this->othercategory->id)),
                false,
            ],
        ];
        foreach ($editors as $name => [$user, $expected]) {
            $this->setUser($user);
            $contextids = access::get_editorial_contextids();
            $found = item_manager::search($contextids);
            $this->assertSame($expected, isset($found[$item->id]), $name);
            $this->assertSame(count($found), item_manager::count_search($contextids), $name);
            $this->assertSame($expected, access::can_view($item), $name);
        }

        // The site editor filters by origin and by "without placement".
        $this->setUser($editors['site'][0]);
        $contextids = access::get_editorial_contextids();
        $this->assertSame([(int) $item->id], array_map('intval', array_keys(
            item_manager::search($contextids, ['origin' => item_manager::ORIGIN_COURSE])
        )));
        $unplaced = item_manager::search($contextids, ['unplaced' => true]);
        $this->assertArrayHasKey($item->id, $unplaced);
        $this->assertArrayHasKey($siteitem->id, $unplaced);
        $this->assertSame(0, (int) $unplaced[$item->id]->placementcount);

        $this->setAdminUser();
        /** @var \mod_nextpool_generator $modgenerator */
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        $modgenerator->place($item, $this->course);
        $this->setUser($editors['site'][0]);
        $this->assertArrayNotHasKey($item->id, item_manager::search($contextids, ['unplaced' => true]));
        $this->assertSame(1, (int) item_manager::search($contextids)[$item->id]->placementcount);
    }

    /**
     * Widening to the site needs the review capability there; the placement and the package stay (M06.AC05, TD-403).
     */
    public function test_widen_to_site(): void {
        $item = $this->create_course_item();
        /** @var \mod_nextpool_generator $modgenerator */
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_nextpool');
        $placement = $modgenerator->place($item, $this->course);
        $coursecontextid = (int) \context_course::instance($this->course->id)->id;
        $systemcontextid = (int) \context_system::instance()->id;
        $versionid = (int) $item->currentversionid;
        $package = static fn(int $contextid): int => count(get_file_storage()->get_area_files(
            $contextid,
            'local_nextpool',
            version_manager::FILEAREA_XAPIPACKAGE,
            $versionid,
            'id',
            false
        ));
        $this->assertGreaterThan(0, $package($coursecontextid));

        // Editing the item in the category is not enough to widen it.
        $editor = $this->create_editor([access::MANAGE], \context_coursecat::instance($this->category->id));
        $this->setUser($editor);
        try {
            item_manager::update((int) $item->id, (object) ['contextid' => $systemcontextid]);
            $this->fail('Widened without review capability');
        } catch (\required_capability_exception $e) {
            $this->assertEquals($coursecontextid, item_manager::get((int) $item->id)->contextid);
        }

        $reviewer = $this->create_editor([access::MANAGE, access::REVIEW], \context_system::instance());
        $this->setUser($reviewer);
        $updated = item_manager::update((int) $item->id, (object) [
            'contextid' => $systemcontextid,
            'origincourseid' => $this->othercourse->id,
        ]);
        $this->assertEquals($systemcontextid, $updated->contextid);
        // The origin course stays, whatever the input says.
        $this->assertEquals($this->course->id, $updated->origincourseid);
        $this->assertTrue(catalogue::is_available_in_course($updated, (int) $this->othercourse->id));
        $this->assertSame(0, $package($coursecontextid));
        $this->assertGreaterThan(0, $package($systemcontextid));

        $after = placement_manager::get((int) $placement->id);
        $this->assertEquals($item->id, $after->itemid);
        $this->assertEquals($placement->cmid, $after->cmid);

        // No way back into a course.
        $this->expectException(\moodle_exception::class);
        item_manager::update((int) $item->id, (object) ['contextid' => $coursecontextid]);
    }

    /**
     * A catalogue item never moves into a course context.
     */
    public function test_no_binding_to_course(): void {
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_item(['type' => 'xapi']);
        $this->expectException(\moodle_exception::class);
        item_manager::update((int) $item->id, (object) [
            'contextid' => \context_course::instance($this->course->id)->id,
            'origincourseid' => $this->course->id,
        ]);
    }
}
