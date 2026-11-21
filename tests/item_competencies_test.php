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

use local_nextpool\form\item_form;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;

/**
 * Tests for the competencies of catalogue items (F14).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_competencies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_form::class)]
final class item_competencies_test extends \advanced_testcase {
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
     * Entries in nugget format are stored in order and come back with decoded objectives.
     */
    public function test_set_and_get(): void {
        global $DB;
        $item = $this->generator->create_item();
        $stored = item_competencies::set((int) $item->id, [
            [
                'competency' => ' SAFE-01 ',
                'level' => 2,
                'function' => 'explain',
                'objectives' => [
                    ['id' => 'SAFE-01.1', 'bloom' => 'understand', 'text' => 'Name the sources of ignition'],
                    ['text' => 'Without Bloom level'],
                    ['id' => '', 'text' => ''],
                ],
            ],
            ['competency' => '', 'level' => 3, 'function' => 'check'],
            (object) ['competency' => 'SAFE-02', 'level' => 3, 'function' => 'apply'],
        ]);

        $this->assertCount(2, $stored);
        $this->assertSame('SAFE-01', $stored[0]->competency);
        $this->assertEquals(2, $stored[0]->skilllevel);
        $this->assertSame('explain', $stored[0]->didacticfunction);
        $this->assertSame([
            ['id' => 'SAFE-01.1', 'bloom' => 'understand', 'text' => 'Name the sources of ignition'],
            ['id' => null, 'bloom' => null, 'text' => 'Without Bloom level'],
        ], $stored[0]->objectives);
        $this->assertSame([], $stored[1]->objectives);
        $this->assertNull($DB->get_field(item_competencies::TABLE, 'objectives', ['id' => $stored[1]->id]));

        // Replacing keeps the creation time of entries that stay and drops the others.
        $DB->set_field(item_competencies::TABLE, 'timecreated', 1000, ['id' => $stored[1]->id]);
        $stored = item_competencies::set((int) $item->id, [['competency' => 'SAFE-02', 'level' => 4, 'function' => 'practice']]);
        $this->assertCount(1, $stored);
        $this->assertEquals(1000, $stored[0]->timecreated);
        $this->assertEquals(4, $stored[0]->skilllevel);
    }

    /**
     * Invalid entries are rejected with the competency named in the message.
     *
     * @param array $entries Entries.
     * @param string $errorcode Expected error.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_validate_rejects(array $entries, string $errorcode): void {
        $item = $this->generator->create_item();
        try {
            item_competencies::set((int) $item->id, $entries);
            $this->fail('Expected ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertSame([], item_competencies::get((int) $item->id));
    }

    /**
     * Invalid competency lists.
     *
     * @return array[]
     */
    public static function invalid_provider(): array {
        return [
            'level too low' => [[['competency' => 'A', 'level' => 0, 'function' => 'explain']], 'error_competencylevel'],
            'level too high' => [[['competency' => 'A', 'level' => 6, 'function' => 'explain']], 'error_competencylevel'],
            'old role case' => [[['competency' => 'A', 'level' => 1, 'function' => 'case']], 'error_competencyfunction'],
            'unknown bloom' => [
                [['competency' => 'A', 'level' => 1, 'function' => 'check', 'objectives' => [['bloom' => 'know', 'text' => 'x']]]],
                'error_competencybloom',
            ],
            'duplicate' => [
                [
                    ['competency' => 'A', 'level' => 1, 'function' => 'explain'],
                    ['competency' => 'A', 'level' => 2, 'function' => 'check'],
                ],
                'error_competencyduplicate',
            ],
            'duplicate competency in other case (bug168)' => [
                [
                    ['competency' => 'safety-1', 'level' => 1, 'function' => 'explain'],
                    ['competency' => 'SAFETY-1', 'level' => 2, 'function' => 'check'],
                ],
                'error_competencyduplicate',
            ],
            'idnumber too long' => [
                [['competency' => str_repeat('x', 101), 'level' => 1, 'function' => 'explain']],
                'error_competencyidnumber',
            ],
        ];
    }

    /**
     * An idnumber resolves only if exactly one framework has it.
     */
    public function test_resolve_and_unresolved(): void {
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework1 = $lp->create_framework();
        $framework2 = $lp->create_framework();
        $single = $lp->create_competency(['competencyframeworkid' => $framework1->get('id'), 'idnumber' => 'ONE']);
        $lp->create_competency(['competencyframeworkid' => $framework1->get('id'), 'idnumber' => 'TWICE']);
        $lp->create_competency(['competencyframeworkid' => $framework2->get('id'), 'idnumber' => 'TWICE']);

        [$resolution, $competency] = item_competencies::resolve('ONE');
        $this->assertSame(item_competencies::RESOLVED, $resolution);
        $this->assertEquals($single->get('id'), $competency->id);
        $this->assertSame([item_competencies::AMBIGUOUS, null], item_competencies::resolve('TWICE'));
        $this->assertSame([item_competencies::UNKNOWN, null], item_competencies::resolve('NONE'));

        $item = $this->generator->create_item();
        $this->generator->create_item_competencies((int) $item->id, [
            ['competency' => 'ONE', 'level' => 1, 'function' => 'explain'],
            ['competency' => 'TWICE', 'level' => 1, 'function' => 'example'],
            ['competency' => 'NONE', 'level' => 1, 'function' => 'practice'],
        ]);
        $this->assertSame(['TWICE', 'NONE'], item_competencies::get_unresolved((int) $item->id));
    }

    /**
     * Deleting an item removes its competencies.
     */
    public function test_deleted_with_item(): void {
        global $DB;
        $item = $this->generator->create_item();
        $this->generator->create_item_competencies((int) $item->id, [['competency' => 'A', 'level' => 1, 'function' => 'explain']]);
        item_manager::delete((int) $item->id);
        $this->assertFalse($DB->record_exists(item_competencies::TABLE, ['itemid' => $item->id]));
    }

    /**
     * Competencies survive a round trip through the item form, objectives as text lines.
     */
    public function test_form_round_trip(): void {
        $item = $this->generator->create_item();
        $entries = [
            [
                'competency' => 'SAFE-01',
                'level' => 2,
                'function' => 'explain',
                'objectives' => [
                    ['id' => 'O1', 'bloom' => 'understand', 'text' => 'First'],
                    ['id' => '', 'bloom' => 'apply', 'text' => 'Second'],
                    ['id' => '', 'bloom' => '', 'text' => 'Third'],
                ],
            ],
            ['competency' => 'UNKNOWN-9', 'level' => 5, 'function' => 'apply', 'objectives' => []],
        ];
        $this->generator->create_item_competencies((int) $item->id, $entries);

        $data = item_form::prepare_data($item, item_manager::get_authors((int) $item->id));
        $this->assertSame(['SAFE-01', 'UNKNOWN-9'], $data->competency_idnumber);
        $this->assertSame("O1 | understand | First\napply | Second\nThird", $data->competency_objectives[0]);

        $this->assertSame($entries, item_form::extract_competencies($data));
    }

    /**
     * The form reports invalid rows on the field that is wrong and ignores empty rows.
     */
    public function test_form_validation(): void {
        $item = $this->generator->create_item();
        $form = new item_form(null, ['id' => $item->id, 'competencycount' => 3]);
        $data = (array) item_form::prepare_data($item, item_manager::get_authors((int) $item->id));
        $data += [
            'competency_idnumber' => ['A', 'A', '', 'B'],
            'competency_level' => [1, 2, 1, 1],
            'competency_function' => ['explain', 'check', 'explain', 'apply'],
            'competency_objectives' => ['', '', '', 'know | Something'],
        ];
        $errors = $form->validation($data, []);
        $this->assertArrayHasKey('competency_idnumber[1]', $errors);
        $this->assertArrayHasKey('competency_objectives[3]', $errors);
        $this->assertArrayNotHasKey('competency_idnumber[0]', $errors);
        $this->assertArrayNotHasKey('competency_idnumber[2]', $errors);
    }

    /**
     * Event data keeps every linked competency of an idnumber that two frameworks share (bug57).
     */
    public function test_event_data_with_shared_idnumber(): void {
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $first = $lp->create_competency(['competencyframeworkid' => $lp->create_framework()->get('id'), 'idnumber' => 'TWICE']);
        $second = $lp->create_competency(['competencyframeworkid' => $lp->create_framework()->get('id'), 'idnumber' => 'TWICE']);
        $unique = $lp->create_competency(['competencyframeworkid' => $lp->create_framework()->get('id'), 'idnumber' => 'ONCE']);
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        foreach ([$first, $second, $unique] as $competency) {
            \core_competency\api::add_competency_to_course((int) $course->id, (int) $competency->get('id'));
            \core_competency\api::add_competency_to_course_module($cm, (int) $competency->get('id'));
        }
        $item = $this->generator->create_item();
        item_competencies::set((int) $item->id, [
            ['competency' => 'TWICE', 'level' => 2, 'function' => 'explain'],
            ['competency' => 'ONCE', 'level' => 3, 'function' => 'check'],
            ['competency' => 'NONE', 'level' => 1, 'function' => 'example'],
        ]);

        $data = item_competencies::get_event_data((int) $item->id, (int) $page->cmid);
        $this->assertSame(0, $data[0]['competencyid'], 'Two linked competencies share the idnumber.');
        $this->assertSame([(int) $first->get('id'), (int) $second->get('id')], $data[0]['competencyids']);
        $this->assertSame((int) $unique->get('id'), $data[1]['competencyid']);
        $this->assertSame([(int) $unique->get('id')], $data[1]['competencyids']);
        $this->assertSame(0, $data[2]['competencyid']);
        $this->assertSame([], $data[2]['competencyids']);
    }

    /**
     * The competencies of an item are resolved with one query, however many there are (bug56).
     */
    public function test_resolve_without_query_per_competency(): void {
        global $DB;
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $lp->create_framework();
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'C' . $i]);
            $entries[] = ['competency' => 'C' . $i, 'level' => 1, 'function' => 'explain'];
        }
        $small = $this->generator->create_item();
        item_competencies::set((int) $small->id, array_slice($entries, 0, 1));
        $large = $this->generator->create_item();
        item_competencies::set((int) $large->id, array_merge($entries, [['competency' => 'MISSING', 'level' => 1,
            'function' => 'explain']]));

        $reads = $DB->perf_get_reads();
        $this->assertSame([], item_competencies::get_unresolved((int) $small->id));
        $smallreads = $DB->perf_get_reads() - $reads;
        $reads = $DB->perf_get_reads();
        $this->assertSame(['MISSING'], item_competencies::get_unresolved((int) $large->id));
        $this->assertSame($smallreads, $DB->perf_get_reads() - $reads);

        $resolved = item_competencies::resolve_many(['C1', 'MISSING']);
        $this->assertSame(item_competencies::RESOLVED, $resolved['C1'][0]);
        $this->assertSame('C1', $resolved['C1'][1]->idnumber);
        $this->assertSame([item_competencies::UNKNOWN, null], $resolved['MISSING']);
    }

    /**
     * The item form no longer lists the competencies of the site in each row; chosen ones keep their label (bug52).
     */
    public function test_form_loads_competencies_by_search(): void {
        global $PAGE;
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $lp->create_framework(['shortname' => 'Safety']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'OPEN-01',
            'shortname' => 'Open flames']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'OTHER-01']);
        $hidden = $lp->create_framework(['visible' => 0, 'shortname' => 'Archive']);
        $lp->create_competency(['competencyframeworkid' => $hidden->get('id'), 'idnumber' => 'HIDDEN-01',
            'shortname' => 'Old rule']);
        $item = $this->generator->create_item();
        $this->generator->create_item_competencies((int) $item->id, [
            ['competency' => 'OPEN-01', 'level' => 1, 'function' => 'explain', 'objectives' => []],
            ['competency' => 'HIDDEN-01', 'level' => 1, 'function' => 'explain', 'objectives' => []],
            ['competency' => 'NEW-01', 'level' => 1, 'function' => 'explain', 'objectives' => []],
        ]);

        $PAGE->set_url('/local/nextpool/edit.php');
        $form = new item_form(null, ['id' => $item->id, 'locked' => false, 'sourceid' => 0, 'authorcount' => 1,
            'authorusers' => [], 'competencycount' => 3]);
        $form->set_data(item_form::prepare_data($item, item_manager::get_authors((int) $item->id)));
        $html = $form->render();

        // Competencies the item does not have are not part of the page.
        $this->assertStringNotContainsString('OTHER-01', $html);
        // The chosen ones keep their label, also from a hidden framework, and an unknown idnumber stays as typed.
        $this->assertStringContainsString(s('Open flames (OPEN-01) – Safety'), $html);
        $this->assertStringContainsString(s('Old rule (HIDDEN-01) – Archive'), $html);
        $this->assertStringContainsString('value="NEW-01"', $html);
        $this->assertMatchesRegularExpression('~local_nextpool\\\\?/competency_selector~', $PAGE->requires->get_end_code());
    }

    /**
     * The search finds competencies of visible frameworks by idnumber or name, exact idnumber first (bug52).
     */
    public function test_search(): void {
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $lp->create_framework(['shortname' => 'Safety']);
        $other = $lp->create_framework(['shortname' => 'Another']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'FIRE-10',
            'shortname' => 'Fire drill']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'FIRE-1',
            'shortname' => 'Fire basics']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'EXIT-01',
            'shortname' => 'Escape after a fire']);
        // The same idnumber in a second framework is offered once.
        $lp->create_competency(['competencyframeworkid' => $other->get('id'), 'idnumber' => 'FIRE-10']);
        $hidden = $lp->create_framework(['visible' => 0]);
        $lp->create_competency(['competencyframeworkid' => $hidden->get('id'), 'idnumber' => 'FIRE-99']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => '',
            'shortname' => 'Fire without idnumber']);

        $idnumbers = array_column(item_competencies::search('fire-1'), 'idnumber');
        $this->assertSame(['FIRE-1', 'FIRE-10'], $idnumbers);
        $this->assertEqualsCanonicalizing(
            ['FIRE-1', 'FIRE-10', 'EXIT-01'],
            array_column(item_competencies::search('Fire'), 'idnumber')
        );
        $this->assertSame([], item_competencies::search('FIRE-99'));
        $this->assertCount(2, item_competencies::search('', 2));
        $this->assertSame(s('Fire basics (FIRE-1) – Safety'), item_competencies::search('FIRE-1')[0]['label']);
        $this->assertSame(s('Escape after a fire (EXIT-01) – Safety'), item_competencies::get_label('EXIT-01'));
        $this->assertNull(item_competencies::get_label('FIRE-10'));
        $this->assertNull(item_competencies::get_label('MISSING'));
    }
}
