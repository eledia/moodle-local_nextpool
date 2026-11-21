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

use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_generate_text;
use local_nextpool\form\competency_suggestion_form;
use local_nextpool\local\competency_suggester;
use local_nextpool\local\item_competencies;

/**
 * AI proposals for item competencies (F14, test29).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(competency_suggester::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(competency_suggestion_form::class)]
final class competency_suggester_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $lp->create_framework(['shortname' => 'Safety']);
        $other = $lp->create_framework();
        $hidden = $lp->create_framework(['visible' => 0]);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'SAFE-01',
            'shortname' => 'Recognise fire hazards']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'SAFE-02']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'TWICE']);
        $lp->create_competency(['competencyframeworkid' => $other->get('id'), 'idnumber' => 'TWICE']);
        $lp->create_competency(['competencyframeworkid' => $hidden->get('id'), 'idnumber' => 'SECRET']);
    }

    /**
     * An idnumber that a hidden framework shares is ambiguous too, and ambiguous ones do not count against the
     * limit; the full size is known, so the page can say that competencies were left out (bug51).
     */
    public function test_vocabulary_ambiguity_and_limit(): void {
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $hidden = $lp->create_framework(['visible' => 0]);
        $lp->create_competency(['competencyframeworkid' => $hidden->get('id'), 'idnumber' => 'SAFE-02']);
        $this->assertSame(['SAFE-01'], array_keys(competency_suggester::vocabulary()));
        $this->assertSame(1, competency_suggester::count_vocabulary());

        $aaa = $lp->create_framework(['shortname' => 'AAA']);
        $lp->create_competency(['competencyframeworkid' => $aaa->get('id'), 'idnumber' => 'SAFE-01']);
        $lp->create_competency(['competencyframeworkid' => $aaa->get('id'), 'idnumber' => 'A-1']);
        $lp->create_competency(['competencyframeworkid' => $aaa->get('id'), 'idnumber' => 'A-2']);
        $this->assertSame(['A-1'], array_keys(competency_suggester::vocabulary(1)));
        $this->assertSame(['A-1', 'A-2'], array_keys(competency_suggester::vocabulary(2)));
        $this->assertSame(2, competency_suggester::count_vocabulary());
    }

    /**
     * The AI may choose only competencies of visible frameworks whose idnumber is unique.
     */
    public function test_vocabulary(): void {
        $vocabulary = competency_suggester::vocabulary();
        $this->assertSame(['SAFE-01', 'SAFE-02'], array_keys($vocabulary));
        $this->assertSame('Recognise fire hazards', $vocabulary['SAFE-01']['name']);
        $this->assertSame('Safety', $vocabulary['SAFE-01']['framework']);
    }

    /**
     * Unknown ids, invalid levels or functions and repeated ids are dropped; unknown Bloom levels are cleared.
     */
    public function test_parse(): void {
        $vocabulary = competency_suggester::vocabulary();
        $answer = 'Here you are: ' . json_encode(['competencies' => [
            ['competency' => 'SAFE-01', 'level' => 2, 'function' => 'explain', 'objectives' => [
                ['bloom' => 'understand', 'text' => 'Name three fire hazards'],
                ['bloom' => 'guess', 'text' => '<b>Spot</b> exits'],
                ['bloom' => 'apply', 'text' => ''],
            ]],
            ['competency' => 'SAFE-01', 'level' => 3, 'function' => 'practice'],
            ['competency' => 'SECRET', 'level' => 2, 'function' => 'explain'],
            ['competency' => 'INVENTED', 'level' => 2, 'function' => 'explain'],
            ['competency' => 'SAFE-02', 'level' => 6, 'function' => 'explain'],
            ['competency' => 'SAFE-02', 'level' => 3, 'function' => 'case'],
            'nonsense',
        ]]) . ' Hope that helps.';

        $this->assertSame([[
            'competency' => 'SAFE-01',
            'level' => 2,
            'function' => 'explain',
            'objectives' => [
                ['id' => null, 'bloom' => 'understand', 'text' => 'Name three fire hazards'],
                ['id' => null, 'bloom' => null, 'text' => 'Spot exits'],
            ],
        ]], competency_suggester::parse($answer, $vocabulary));
        $this->assertSame([], competency_suggester::parse('I cannot help with that.', $vocabulary));
        $this->assertSame([], competency_suggester::parse('{"pairs":[]}', $vocabulary));
    }

    /**
     * Without an AI provider nothing is proposed.
     */
    public function test_suggest_without_provider(): void {
        $item = $this->generator->create_item(['title' => 'Fire safety']);
        $this->assertFalse(competency_suggester::is_available());
        $this->assertNull(competency_suggester::suggest((int) $item->id));
    }

    /**
     * The proposals come from the AI manager; the prompt carries the item and the vocabulary only.
     */
    public function test_suggest_with_provider(): void {
        $item = $this->generator->create_item(['title' => 'Fire safety', 'description' => 'Hazards at work']);
        $response = new response_generate_text(true);
        $response->set_response_data(['generatedcontent' => json_encode(['competencies' => [
            ['competency' => 'SAFE-02', 'level' => 3, 'function' => 'practice'],
            ['competency' => 'SECRET', 'level' => 3, 'function' => 'practice'],
        ]])]);
        $prompt = '';
        $manager = $this->createMock(\core_ai\manager::class);
        $manager->method('is_action_available')->willReturn(true);
        $manager->method('process_action')->willReturnCallback(function (generate_text $action) use ($response, &$prompt) {
            $prompt = $action->get_configuration('prompttext');
            return $response;
        });
        \core\di::set(\core_ai\manager::class, $manager);

        $entries = competency_suggester::suggest((int) $item->id);

        $this->assertSame([['competency' => 'SAFE-02', 'level' => 3, 'function' => 'practice', 'objectives' => []]], $entries);
        $this->assertStringContainsString('Fire safety', $prompt);
        $this->assertStringContainsString('SAFE-01', $prompt);
        $this->assertStringNotContainsString('SECRET', $prompt);
        // Nothing is stored until the editor picks proposals.
        $this->assertSame([], item_competencies::get((int) $item->id));
    }

    /**
     * A failed AI call yields null, not an exception.
     */
    public function test_suggest_failed(): void {
        $item = $this->generator->create_item();
        $manager = $this->createMock(\core_ai\manager::class);
        $manager->method('is_action_available')->willReturn(true);
        $manager->method('process_action')->willReturn(new response_generate_text(false, 500, 'error', 'Down'));
        \core\di::set(\core_ai\manager::class, $manager);

        $this->assertNull(competency_suggester::suggest((int) $item->id));
    }

    /**
     * Picked proposals are added; an entry the item already has stays unchanged.
     */
    public function test_apply_keeps_existing(): void {
        $item = $this->generator->create_item();
        $this->generator->create_item_competencies((int) $item->id, [
            ['competency' => 'SAFE-01', 'level' => 4, 'function' => 'apply', 'objectives' => [['text' => 'Keep me']]],
        ]);
        $entries = [
            ['competency' => 'SAFE-01', 'level' => 1, 'function' => 'explain', 'objectives' => []],
            ['competency' => 'SAFE-02', 'level' => 2, 'function' => 'check', 'objectives' => [
                ['id' => null, 'bloom' => 'remember', 'text' => 'List the exits'],
            ]],
        ];
        $data = (object) ['pick' => [0 => 1, 1 => 1]];

        $added = competency_suggester::apply((int) $item->id, competency_suggestion_form::picked($data, $entries));

        $this->assertSame(['SAFE-02'], $added);
        $records = item_competencies::get((int) $item->id);
        $this->assertSame(['SAFE-01', 'SAFE-02'], array_column($records, 'competency'));
        $this->assertEquals(4, $records[0]->skilllevel);
        $this->assertSame('Keep me', $records[0]->objectives[0]['text']);
        $this->assertSame('remember', $records[1]->objectives[0]['bloom']);

        // Nothing picked, nothing changes.
        $this->assertSame([], competency_suggester::apply((int) $item->id, competency_suggestion_form::picked(
            (object) ['pick' => [0 => 0, 1 => 0]],
            $entries
        )));
    }

    /**
     * The form renders every label from an existing string (bug30).
     */
    public function test_form_renders(): void {
        $item = $this->generator->create_item();
        $entries = [
            ['competency' => 'SAFE-01', 'level' => 1, 'function' => 'explain', 'objectives' => [
                ['id' => null, 'bloom' => 'remember', 'text' => 'List the exits'],
            ]],
            ['competency' => 'SAFE-02', 'level' => 2, 'function' => 'check', 'objectives' => []],
        ];
        $form = new competency_suggestion_form(new \moodle_url('/local/nextpool/suggest.php'), [
            'id' => (int) $item->id,
            'entries' => $entries,
            'vocabulary' => competency_suggester::vocabulary(),
            'existing' => ['SAFE-01'],
        ]);

        $html = $form->render();

        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringContainsString(get_string('competencysuggestapply', 'local_nextpool'), $html);
        $this->assertStringContainsString(s(get_string('competencysuggestexists', 'local_nextpool')), $html);
        // The Bloom level appears by its name, not by its key (bug63, Q-23).
        $this->assertStringContainsString(get_string('bloom_remember', 'local_nextpool') . ': List the exits', $html);
        $this->assertStringNotContainsString('remember: List the exits', $html);
    }
}
