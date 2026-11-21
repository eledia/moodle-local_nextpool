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

use local_nextpool\local\content_type\quiz;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\quiz_package;
use local_nextpool\local\version_manager;

/**
 * Tests for the quiz content type and its exchange format (F04-quiz, q28-q31).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(quiz::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(quiz_package::class)]
final class content_type_quiz_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Questions of the fixture package.
     *
     * @return string
     */
    private static function fixture_xml(): string {
        return (string) file_get_contents(__DIR__ . '/fixtures/quiz/questions.xml');
    }

    /**
     * Description of the fixture package.
     *
     * @return array
     */
    private static function fixture_slots(): array {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/quiz/quiz.json'), true)['slots'];
    }

    /**
     * A quiz package as bytes.
     *
     * @param string|null $xml questions.xml, null for the fixture, '' to leave it out.
     * @param array|string|null $slots Slots of quiz.json, a raw quiz.json, null for the fixture.
     * @return string
     */
    private static function package(?string $xml = null, $slots = null): string {
        $path = make_request_directory() . '/quiz.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $xml = $xml ?? self::fixture_xml();
        if ($xml !== '') {
            $zip->addFromString(quiz_package::QUESTIONS_FILE, $xml);
        }
        $json = is_string($slots) ? $slots
            : json_encode(['format' => quiz_package::FORMAT, 'version' => 1, 'slots' => $slots ?? self::fixture_slots()]);
        $zip->addFromString(quiz_package::DESCRIPTION_FILE, $json);
        $zip->close();
        return (string) file_get_contents($path);
    }

    /**
     * The fixture questions plus one more short answer question.
     *
     * @param string $idnumber ID number of the new question.
     * @return string
     */
    private static function xml_with_extra_question(string $idnumber = 'sa2'): string {
        $extra = '<question type="shortanswer"><name><text>Capital of Italy</text></name>' .
            '<questiontext format="html"><text>What is the capital of Italy?</text></questiontext>' .
            '<defaultgrade>1</defaultgrade><idnumber>' . $idnumber . '</idnumber><usecase>0</usecase>' .
            '<answer fraction="100"><text>Rome</text></answer></question>';
        return str_replace('</quiz>', $extra . '</quiz>', self::fixture_xml());
    }

    /**
     * Read a package from bytes.
     *
     * @param string $bytes Package.
     * @return array
     */
    private static function read(string $bytes): array {
        $path = make_request_directory() . '/package.zip';
        file_put_contents($path, $bytes);
        return quiz_package::read($path);
    }

    /**
     * Place a quiz item in a new course.
     *
     * @param string $policy Update policy.
     * @return array [item, placement, course]
     */
    private function place_quiz(string $policy = policy::NOTIFY): array {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'quiz'], self::package(), 'quiz.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 1, $policy);
        return [$item, $placement, $course];
    }

    /**
     * Slots of a quiz: idnumber, page and mark per slot.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    private static function slots(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $records = $DB->get_records_sql(
            "SELECT s.slot, s.page, s.maxmark, qbe.idnumber
               FROM {quiz_slots} s
               JOIN {question_references} qr ON qr.itemid = s.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
              WHERE s.quizid = ?
           ORDER BY s.slot",
            [$cm->instance]
        );
        return array_values(array_map(static fn($r) => [$r->idnumber, (int) $r->page, (float) $r->maxmark], $records));
    }

    /**
     * A learner with an attempt at the quiz, finished or not.
     *
     * @param int $cmid Course module id.
     * @param bool $finish Finish the attempt.
     * @return \stdClass Attempt.
     */
    private function attempt(int $cmid, bool $finish): \stdClass {
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $user = $this->getDataGenerator()->create_and_enrol(get_course($cm->course), 'student');
        $this->setUser($user);
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $quizgenerator->create_attempt((int) $cm->instance, (int) $user->id);
        $quizgenerator->submit_responses($attempt->id, [1 => 'True', 2 => 'Paris'], false, $finish);
        $this->setAdminUser();
        return $attempt;
    }

    /**
     * The fixture package is read with order, pages and marks; a missing mark is the default mark (q28).
     */
    public function test_read_package(): void {
        $package = self::read(self::package());
        $this->assertSame([
            ['question' => 'tf1', 'page' => 1, 'maxmark' => 2.0],
            ['question' => 'sa1', 'page' => 1, 'maxmark' => 1.0],
            ['question' => 'mc1', 'page' => 2, 'maxmark' => 3.0],
        ], $package['slots']);
        $this->assertSame(['tf1', 'sa1', 'mc1'], array_keys($package['questions']));
        $this->assertSame('truefalse', $package['questions']['tf1']['qtype']);

        // The hash of a question depends on its content, not on the formatting of the file.
        $reformatted = self::read(self::package(str_replace("\n    <", "\n<", self::fixture_xml())));
        $this->assertSame($package['questions']['tf1']['hash'], $reformatted['questions']['tf1']['hash']);
        $changed = self::read(self::package(str_replace('The earth is round.', 'The earth is a sphere.', self::fixture_xml())));
        $this->assertNotSame($package['questions']['tf1']['hash'], $changed['questions']['tf1']['hash']);
        $this->assertSame($package['questions']['sa1']['hash'], $changed['questions']['sa1']['hash']);
    }

    /**
     * Invalid packages and their reasons.
     *
     * @return array
     */
    public static function invalid_package_provider(): array {
        $xml = self::fixture_xml();
        $slots = self::fixture_slots();
        $cloze = '<question type="multianswer"><name><text>Cloze one</text></name>' .
            '<questiontext format="html"><text>{1:SHORTANSWER:=a}</text></questiontext><idnumber>cl1</idnumber></question>';
        $random = '<question type="random"><name><text>Any</text></name><questiontext><text>0</text></questiontext>' .
            '<idnumber>r1</idnumber></question>';
        return [
            'cloze is not allowed (q30)' => [str_replace('</quiz>', $cloze . '</quiz>', $xml),
                array_merge($slots, [['question' => 'cl1', 'page' => 2]]), 'multianswer'],
            'random is not allowed (q30)' => [str_replace('</quiz>', $random . '</quiz>', $xml),
                array_merge($slots, [['question' => 'r1', 'page' => 2]]), 'random'],
            'no idnumber' => [str_replace('<idnumber>sa1</idnumber>', '', $xml), $slots, 'Capital of France'],
            'duplicate idnumber' => [str_replace('<idnumber>sa1</idnumber>', '<idnumber>tf1</idnumber>', $xml), $slots, 'tf1'],
            'unknown question' => [$xml, array_merge($slots, [['question' => 'nope', 'page' => 2]]), 'nope'],
            'unused question' => [$xml, array_slice($slots, 0, 2), 'mc1'],
            'question twice' => [$xml, array_merge($slots, [['question' => 'tf1', 'page' => 2]]), 'tf1'],
            'page jumps' => [$xml, [$slots[0], $slots[1], ['question' => 'mc1', 'page' => 3]], 'mc1'],
            'page goes back' => [$xml, [$slots[0], ['question' => 'sa1', 'page' => 2], ['question' => 'mc1', 'page' => 1]], 'mc1'],
            'first page not 1' => [$xml, [['question' => 'tf1', 'page' => 2], $slots[1], $slots[2]], 'tf1'],
            'negative mark' => [$xml, [$slots[0], ['question' => 'sa1', 'page' => 1, 'maxmark' => -1], $slots[2]], 'sa1'],
            'text mark' => [$xml, [$slots[0], ['question' => 'sa1', 'page' => 1, 'maxmark' => 'two'], $slots[2]], 'sa1'],
            'no questions.xml' => ['', $slots, 'questions.xml'],
            'not xml' => ['<quiz', $slots, 'questions.xml'],
            'wrong format' => [$xml, '{"format": "other", "version": 1, "slots": []}', 'quiz.json'],
            'no slots' => [$xml, [], 'quiz.json'],
        ];
    }

    /**
     * A package that breaks a rule is refused with a reason naming the cause (q28, q30).
     *
     * @param string $xml questions.xml.
     * @param array|string $slots Slots or raw quiz.json.
     * @param string $expected Part of the reason.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_package_provider')]
    public function test_invalid_package(string $xml, $slots, string $expected): void {
        try {
            self::read(self::package($xml, $slots));
            $this->fail('The package must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_quizpackage', $e->errorcode);
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    /**
     * Not a ZIP at all.
     */
    public function test_not_a_zip(): void {
        $this->expectExceptionMessage(get_string('quizpackage_notzip', 'local_nextpool'));
        self::read('plain text');
    }

    /**
     * A version with a question type outside q30 is refused when the editorial team imports it (F04Q.AC06).
     */
    public function test_import_refuses_disallowed_type(): void {
        $item = $this->generator->create_published_item(['type' => 'quiz'], self::package(), 'quiz.zip');
        $cloze = '<question type="multianswer"><name><text>Cloze one</text></name>' .
            '<questiontext format="html"><text>{1:SHORTANSWER:=a}</text></questiontext><idnumber>cl1</idnumber></question>';
        $bytes = self::package(
            str_replace('</quiz>', $cloze . '</quiz>', self::fixture_xml()),
            array_merge(self::fixture_slots(), [['question' => 'cl1', 'page' => 2]])
        );
        try {
            $this->generator->publish_new_version((int) $item->id, $bytes, 'quiz.zip');
            $this->fail('The import must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_quizpackage', $e->errorcode);
            $this->assertStringContainsString('multianswer', $e->getMessage());
        }
        $this->assertCount(1, version_manager::get_versions((int) $item->id));

        // Other types do not check packages: a file item takes any file.
        $file = $this->generator->create_published_item(['type' => 'file'], 'anything', 'notes.txt');
        $this->assertCount(1, version_manager::get_versions((int) $file->id));
    }

    /**
     * Placing creates a quiz with the questions in order, with marks and pages, in its own question bank (F04Q.AC01, q31).
     */
    public function test_place(): void {
        global $DB;
        set_config('attempts', 3, 'quiz');
        $this->assertTrue(item_types::is_placeable('quiz'));
        [, $placement] = $this->place_quiz();

        $cm = get_coursemodule_from_id('quiz', (int) $placement->cmid, 0, false, MUST_EXIST);
        $this->assertSame([['tf1', 1, 2.0], ['sa1', 1, 1.0], ['mc1', 2, 3.0]], self::slots((int) $cm->id));
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        $this->assertEquals(6, $quiz->sumgrades);
        // Settings are the site defaults, not from the catalogue (q31).
        $this->assertEquals(3, $quiz->attempts);

        // The questions live in the quiz's own bank and carry the catalogue hash.
        $context = \context_module::instance($cm->id);
        $stamps = $DB->get_fieldset_sql(
            'SELECT q.stamp FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = ?',
            [$context->id]
        );
        $this->assertCount(3, $stamps);
        foreach ($stamps as $stamp) {
            $this->assertNotNull(quiz::get_stamp_hash($stamp));
        }
    }

    /**
     * Without attempts a new version is applied completely in the same quiz (F04Q.AC02).
     */
    public function test_update_without_attempts(): void {
        global $DB;
        [$item, $placement] = $this->place_quiz();
        $cmid = (int) $placement->cmid;
        $tfbefore = $this->latest_question_id($cmid, 'tf1');

        $slots = [['question' => 'sa2', 'page' => 1], ['question' => 'tf1', 'page' => 1, 'maxmark' => 2],
            ['question' => 'sa1', 'page' => 2], ['question' => 'mc1', 'page' => 2, 'maxmark' => 3]];
        $version = $this->generator->publish_new_version(
            (int) $item->id,
            self::package(self::xml_with_extra_question(), $slots),
            'quiz.zip'
        );
        $placement = placement_manager::apply((int) $placement->id, (int) $version->id);

        $this->assertSame($cmid, (int) $placement->cmid);
        $this->assertSame([['sa2', 1, 1.0], ['tf1', 1, 2.0], ['sa1', 2, 1.0], ['mc1', 2, 3.0]], self::slots($cmid));
        $cm = get_coursemodule_from_id('quiz', $cmid);
        $this->assertEquals(7, $DB->get_field('quiz', 'sumgrades', ['id' => $cm->instance]));
        // Unchanged questions get no new version.
        $this->assertSame($tfbefore, $this->latest_question_id($cmid, 'tf1'));
    }

    /**
     * With attempts, a corrected question gets a new version: old attempts keep the old text, new attempts get the new
     * one, no grade changes (F04Q.AC03).
     */
    public function test_update_with_attempts_corrects_questions(): void {
        global $DB;
        [$item, $placement] = $this->place_quiz(policy::AUTO);
        $cmid = (int) $placement->cmid;
        $finished = $this->attempt($cmid, true);
        $running = $this->attempt($cmid, false);
        $finishedgrade = $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $finished->id]);

        $xml = str_replace('The earth is round.', 'The earth is a sphere.', self::fixture_xml());
        $version = $this->generator->publish_new_version((int) $item->id, self::package($xml), 'quiz.zip');
        $this->assertTrue(
            placement_manager::apply_automatically((int) $placement->id, (int) $version->id),
            (string) placement_manager::get_downgrade_reason(placement_manager::get((int) $placement->id))
        );
        $this->assertSame((int) $version->id, (int) placement_manager::get((int) $placement->id)->appliedversionid);

        $this->assertStringContainsString('round', $this->attempt_question_text($finished->id));
        $this->assertStringContainsString('round', $this->attempt_question_text($running->id));
        $new = $this->attempt($cmid, false);
        $this->assertStringContainsString('sphere', $this->attempt_question_text($new->id));
        $this->assertSame($finishedgrade, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $finished->id]));
        // One entry with two versions, not a second question.
        $latest = $this->latest_question_id($cmid, 'tf1');
        $entry = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $latest]);
        $this->assertSame(2, $DB->count_records('question_versions', ['questionbankentryid' => $entry]));
    }

    /**
     * With attempts, a version that adds a question is not applied automatically; the teacher gets the reason (F04Q.AC04).
     */
    public function test_update_with_attempts_refuses_structure_change(): void {
        [$item, $placement] = $this->place_quiz(policy::AUTO);
        $cmid = (int) $placement->cmid;
        $this->attempt($cmid, true);

        $slots = array_merge(self::fixture_slots(), [['question' => 'sa2', 'page' => 2]]);
        $version = $this->generator->publish_new_version(
            (int) $item->id,
            self::package(self::xml_with_extra_question(), $slots),
            'quiz.zip'
        );
        $this->assertFalse(placement_manager::apply_automatically((int) $placement->id, (int) $version->id));

        $placement = placement_manager::get((int) $placement->id);
        $this->assertSame((int) $version->id, (int) $placement->pendingversionid);
        $this->assertStringContainsString(
            get_string('noinplace_quizstructure', 'local_nextpool'),
            placement_manager::get_downgrade_reason($placement)
        );
        $this->assertCount(3, self::slots($cmid));

        // A changed mark is a structure change as well.
        $marks = self::fixture_slots();
        $marks[0]['maxmark'] = 5;
        $cm = get_fast_modinfo($placement->courseid)->get_cm($cmid);
        $markversion = $this->generator->publish_new_version((int) $item->id, self::package(null, $marks), 'quiz.zip');
        $this->assertSame(
            get_string('noinplace_quizstructure', 'local_nextpool'),
            (new quiz())->check_update($cm, $item, $markversion)
        );
    }

    /**
     * A question changed by a teacher in the course is never overwritten; the update waits (F04Q.AC05, q21).
     */
    public function test_local_change_is_kept(): void {
        global $DB;
        [$item, $placement] = $this->place_quiz(policy::AUTO);
        $cmid = (int) $placement->cmid;
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $teacherversion = $questiongenerator->update_question(
            \question_bank::load_question_data($this->latest_question_id($cmid, 'sa1')),
            null,
            ['questiontext' => ['text' => 'Course version', 'format' => FORMAT_HTML]]
        );

        $xml = str_replace('What is the capital of France?', 'Name the capital of France.', self::fixture_xml());
        $version = $this->generator->publish_new_version((int) $item->id, self::package($xml), 'quiz.zip');
        $this->assertFalse(placement_manager::apply_automatically((int) $placement->id, (int) $version->id));
        $this->assertSame(
            get_string('noinplace_quizlocal', 'local_nextpool', $teacherversion->name),
            placement_manager::get_downgrade_reason(placement_manager::get((int) $placement->id))
        );
        $this->assertSame((int) $teacherversion->id, $this->latest_question_id($cmid, 'sa1'));
        $this->assertSame('Course version', $DB->get_field('question', 'questiontext', ['id' => $teacherversion->id]));

        // A question the teacher added to the quiz counts as a change in the course too.
        [$item2, $placement2] = $this->place_quiz(policy::AUTO);
        $cm2 = get_fast_modinfo($placement2->courseid)->get_cm((int) $placement2->cmid);
        $category = question_get_default_category(\context_module::instance($cm2->id)->id, true);
        $own = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Own question']);
        $quizrecord = $DB->get_record('quiz', ['id' => $cm2->instance]);
        $quizrecord->cmid = $cm2->id;
        quiz_add_quiz_question($own->id, $quizrecord);
        $version2 = $this->generator->publish_new_version((int) $item2->id, self::package($xml), 'quiz.zip');
        $this->assertStringContainsString('Own question', (string) (new quiz())->check_update($cm2, $item2, $version2));
    }

    /**
     * After the reason, a teacher can place the version as a new quiz next to the old one, which is hidden, not
     * deleted, and keeps its attempts; the teacher's settings stay (q29, q31).
     */
    public function test_place_alongside(): void {
        global $DB;
        [$item, $placement, $course] = $this->place_quiz(policy::AUTO);
        $oldcmid = (int) $placement->cmid;
        $oldquizid = (int) get_coursemodule_from_id('quiz', $oldcmid)->instance;
        $DB->update_record('quiz', (object) ['id' => $oldquizid, 'attempts' => 2, 'timelimit' => 600]);
        $this->attempt($oldcmid, true);
        $slots = array_merge(self::fixture_slots(), [['question' => 'sa2', 'page' => 2]]);
        $version = $this->generator->publish_new_version(
            (int) $item->id,
            self::package(self::xml_with_extra_question(), $slots),
            'quiz.zip'
        );
        placement_manager::apply_automatically((int) $placement->id, (int) $version->id);

        $placement = placement_manager::place_alongside((int) $placement->id, (int) $version->id);
        $newcmid = (int) $placement->cmid;
        $this->assertNotSame($oldcmid, $newcmid);
        $this->assertSame((int) $version->id, (int) $placement->appliedversionid);
        $this->assertNull($placement->pendingversionid);

        $modinfo = get_fast_modinfo($course);
        $old = $modinfo->get_cm($oldcmid);
        $new = $modinfo->get_cm($newcmid);
        $this->assertEquals(0, $old->visible);
        $this->assertEquals(1, $new->visible);
        $this->assertSame($old->sectionnum, $new->sectionnum);
        $sequence = $modinfo->get_sections()[$old->sectionnum];
        $this->assertSame(array_search($oldcmid, $sequence) + 1, array_search($newcmid, $sequence));
        $this->assertSame(1, $DB->count_records('quiz_attempts', ['quiz' => $oldquizid]));
        $this->assertCount(4, self::slots($newcmid));
        $newquiz = $DB->get_record('quiz', ['id' => $new->instance]);
        $this->assertEquals(2, $newquiz->attempts);
        $this->assertEquals(600, $newquiz->timelimit);
        $this->assertTrue($DB->record_exists(
            placement_manager::LOG_TABLE,
            ['placementid' => $placement->id, 'action' => placement_manager::ACTION_ALONGSIDE]
        ));

        // Deleting the hidden old quiz later does not touch the placement.
        placement_manager::delete_for_cm($oldcmid);
        $this->assertSame($newcmid, (int) placement_manager::get((int) $placement->id)->cmid);
    }

    /**
     * Placing alongside also keeps the overall feedback with its files, the completion conditions and the grade
     * items of the old quiz; slots keep the grade item of their catalogue question (bug91).
     */
    public function test_place_alongside_keeps_feedback_completion_and_grade_items(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $item = $this->generator->create_published_item(['type' => 'quiz'], self::package(), 'quiz.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 1, policy::AUTO);
        $oldcmid = (int) $placement->cmid;
        $oldcm = get_coursemodule_from_id('quiz', $oldcmid, 0, false, MUST_EXIST);
        $oldcontext = \context_module::instance($oldcmid);

        // Completion: passing grade, view, expected date and the quiz's own rule.
        $expected = time() + WEEKSECS;
        $DB->update_record('course_modules', (object) ['id' => $oldcmid, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1, 'completionexpected' => $expected, 'completiongradeitemnumber' => 0,
            'completionpassgrade' => 1]);
        $DB->update_record('quiz', (object) ['id' => $oldcm->instance, 'completionminattempts' => 2]);
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $oldcm->instance,
            'itemnumber' => 0, 'courseid' => $course->id]);
        $gradeitem->gradepass = 5;
        $gradeitem->update();
        rebuild_course_cache((int) $course->id, true);

        // Overall feedback in two bands, the upper one with a file.
        $upper = $DB->insert_record('quiz_feedback', (object) ['quizid' => $oldcm->instance, 'feedbacktext' =>
            '<p>Well done <img src="@@PLUGINFILE@@/star.png" alt="Star"></p>', 'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 5, 'maxgrade' => 11]);
        $DB->insert_record('quiz_feedback', (object) ['quizid' => $oldcm->instance, 'feedbacktext' => '<p>Try again</p>',
            'feedbacktextformat' => FORMAT_HTML, 'mingrade' => 0, 'maxgrade' => 5]);
        get_file_storage()->create_file_from_string(['contextid' => $oldcontext->id, 'component' => 'mod_quiz',
            'filearea' => 'feedback', 'itemid' => $upper, 'filepath' => '/', 'filename' => 'star.png'], 'png');

        // Two grade items: tf1 counts for "Knowledge", mc1 for "Skills", sa1 for none.
        $structure = \mod_quiz\quiz_settings::create((int) $oldcm->instance)->get_structure();
        $knowledge = (object) ['quizid' => $oldcm->instance, 'name' => 'Knowledge'];
        $skills = (object) ['quizid' => $oldcm->instance, 'name' => 'Skills'];
        $structure->create_grade_item($knowledge);
        $structure->create_grade_item($skills);
        $structure->update_slot_grade_item($structure->get_slot_by_number(1), (int) $knowledge->id);
        $structure->update_slot_grade_item($structure->get_slot_by_number(3), (int) $skills->id);

        $this->attempt($oldcmid, true);
        $this->setAdminUser();
        $slots = array_merge(self::fixture_slots(), [['question' => 'sa2', 'page' => 2]]);
        $version = $this->generator->publish_new_version(
            (int) $item->id,
            self::package(self::xml_with_extra_question(), $slots),
            'quiz.zip'
        );
        $placement = placement_manager::place_alongside((int) $placement->id, (int) $version->id);
        $newcm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $newcontext = \context_module::instance($newcm->id);

        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $newcm->completion);
        $this->assertEquals(1, $newcm->completionview);
        $this->assertEquals($expected, $newcm->completionexpected);
        $this->assertSame(0, (int) $newcm->completiongradeitemnumber);
        $this->assertEquals(1, $newcm->completionpassgrade);
        $newquiz = $DB->get_record('quiz', ['id' => $newcm->instance]);
        $this->assertEquals(2, $newquiz->completionminattempts);
        $this->assertEquals(5, \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $newquiz->id, 'itemnumber' => 0, 'courseid' => $course->id])->gradepass);

        $feedback = array_values($DB->get_records('quiz_feedback', ['quizid' => $newquiz->id], 'mingrade DESC'));
        $this->assertCount(2, $feedback);
        $this->assertStringContainsString('Well done', $feedback[0]->feedbacktext);
        $this->assertEquals([5, 11], [(float) $feedback[0]->mingrade, (float) $feedback[0]->maxgrade]);
        $this->assertSame('<p>Try again</p>', $feedback[1]->feedbacktext);
        $this->assertTrue(get_file_storage()->file_exists(
            $newcontext->id,
            'mod_quiz',
            'feedback',
            (int) $feedback[0]->id,
            '/',
            'star.png'
        ));
        // The old quiz keeps its own feedback and files.
        $this->assertSame(2, $DB->count_records('quiz_feedback', ['quizid' => $oldcm->instance]));
        $this->assertTrue(get_file_storage()->file_exists($oldcontext->id, 'mod_quiz', 'feedback', $upper, '/', 'star.png'));

        $items = array_values($DB->get_records('quiz_grade_items', ['quizid' => $newquiz->id], 'sortorder'));
        $this->assertSame(['Knowledge', 'Skills'], array_column($items, 'name'));
        $gradeitems = $DB->get_records_menu('quiz_slots', ['quizid' => $newquiz->id], 'slot', 'slot, quizgradeitemid');
        $this->assertEquals([1 => $items[0]->id, 2 => null, 3 => $items[1]->id, 4 => null], $gradeitems);
    }

    /**
     * Types without the option refuse placing alongside.
     */
    public function test_place_alongside_needs_support(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'page'], '<p>One</p>', 'page.html');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $version = $this->generator->publish_new_version((int) $item->id, '<p>Two</p>', 'page.html');
        $this->expectExceptionMessage(get_string('error_noalongside', 'local_nextpool'));
        placement_manager::place_alongside((int) $placement->id, (int) $version->id);
    }

    /**
     * Latest question id of a catalogue question in a quiz.
     *
     * @param int $cmid Course module id.
     * @param string $idnumber Catalogue idnumber.
     * @return int
     */
    private function latest_question_id(int $cmid, string $idnumber): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT qv.questionid
               FROM {question_versions} qv
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = ? AND qbe.idnumber = ?
           ORDER BY qv.version DESC',
            [\context_module::instance($cmid)->id, $idnumber],
            IGNORE_MULTIPLE
        );
    }

    /**
     * Question text of slot 1 in an attempt.
     *
     * @param int $attemptid Attempt id.
     * @return string
     */
    private function attempt_question_text(int $attemptid): string {
        return (string) \mod_quiz\quiz_attempt::create($attemptid)->get_question_attempt(1)->get_question(false)->questiontext;
    }
    /**
     * Untrusted HTML in imported question text is cleaned before it reaches the question bank (bug138).
     */
    public function test_question_html_is_cleaned_on_import(): void {
        global $DB;
        $xml = self::fixture_xml();
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);
        $text = $dom->getElementsByTagName('questiontext')->item(0)->getElementsByTagName('text')->item(0);
        while ($text->firstChild) {
            $text->removeChild($text->firstChild);
        }
        $text->appendChild($dom->createCDATASection('<p>Safe question</p><script>window.nextpoolAttack=true;</script>'));
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'quiz'], self::package($dom->saveXML()), 'quiz.zip');
        placement_manager::place((int) $item->id, (int) $course->id, 1, policy::NOTIFY);
        $all = implode('', $DB->get_fieldset_select('question', 'questiontext', '1=1'));
        $this->assertStringContainsString('Safe question', $all);
        $this->assertStringNotContainsString('<script', $all);
        $this->assertStringNotContainsString('window.nextpoolAttack', $all);
    }
}
