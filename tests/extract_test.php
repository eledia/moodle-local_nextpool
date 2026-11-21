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

use local_nextpool\local\content_type\base;
use local_nextpool\local\content_type\book;
use local_nextpool\local\content_type\h5p;
use local_nextpool\local\content_type\page;
use local_nextpool\local\content_type\quiz;
use local_nextpool\local\content_type\url;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\quiz_package;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Taking an activity over as the content of a proposal: round trip extract → place for page, URL, book, quiz
 * (F07, q64, task83) and H5P (q64 second step, task86; test66).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(url::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(book::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(quiz::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(h5p::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_manager::class)]
final class extract_test extends \advanced_testcase {
    /** @var string A one-pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Extract an activity with the handler of a type.
     *
     * @param string $type Item type.
     * @param int $cmid Course module id.
     * @return array{0:string,1:string} Bytes and file name.
     */
    private function extract(string $type, int $cmid): array {
        $cm = get_course_and_cm_from_cmid($cmid)[1];
        $handler = item_types::get_handler($type);
        $this->assertTrue($handler->supports_extract());
        $path = make_request_directory() . '/extract';
        $filename = $handler->extract($cm, $path);
        return [(string) file_get_contents($path), $filename];
    }

    /**
     * Place extracted bytes as a new item in a new course.
     *
     * @param string $type Item type.
     * @param string $bytes Version file.
     * @param string $filename File name.
     * @return \cm_info The new activity.
     */
    private function place(string $type, string $bytes, string $filename): \cm_info {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => $type], $bytes, $filename);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        return get_fast_modinfo($course)->get_cm((int) $placement->cmid);
    }

    /**
     * A page with an embedded image becomes a ZIP with index.html and the image; placing it gives the same content
     * with the image in the new page. A page without files is a plain HTML file.
     */
    public function test_page_round_trip(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $content = '<h2>Exits</h2><p>Use the stairs.<img src="@@PLUGINFILE@@/img/exit%20sign.png" alt="Exit sign" /></p>';
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Evacuation',
            'content' => $content,
            'contentformat' => FORMAT_HTML,
        ]);
        $context = \context_module::instance($page->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/img/', 'filename' => 'exit sign.png',
        ], base64_decode(self::PNG));

        [$bytes, $filename] = $this->extract('page', (int) $page->cmid);
        $this->assertSame('Evacuation.zip', $filename);
        $cm = $this->place('page', $bytes, $filename);

        $placed = $DB->get_record('page', ['id' => $cm->instance]);
        $this->assertSame(
            trim(purify_html($content)),
            str_replace('@@PLUGINFILE@@/files/', '@@PLUGINFILE@@/', $placed->content),
            'The placed page has the same content, the image under files/.'
        );
        $file = get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            'mod_page',
            'content',
            0,
            '/files/img/',
            'exit sign.png'
        );
        $this->assertNotFalse($file);
        $this->assertSame(base64_decode(self::PNG), $file->get_content());

        // Without files: one HTML file; an update writes it into the same page and removes the old files.
        $DB->set_field('page', 'content', '<p>Only text.</p>', ['id' => $page->id]);
        [$bytes, $filename] = $this->extract('page', (int) $page->cmid);
        $this->assertSame('Evacuation.html', $filename);
        $this->assertStringContainsString('<p>Only text.</p>', $bytes);
        $item = $DB->get_record('local_nextpool_item', ['id' => placement_manager::get_by_cmid((int) $cm->id)->itemid]);
        $version = $this->generator->publish_new_version((int) $item->id, $bytes, $filename);
        placement_manager::apply((int) placement_manager::get_by_cmid((int) $cm->id)->id, (int) $version->id);
        $this->assertSame('<p>Only text.</p>', $DB->get_field('page', 'content', ['id' => $cm->instance]));
        $this->assertFalse(get_file_storage()->file_exists(
            \context_module::instance($cm->id)->id,
            'mod_page',
            'content',
            0,
            '/files/img/',
            'exit sign.png'
        ));
    }

    /**
     * A ZIP page needs index.html.
     */
    public function test_page_zip_without_index(): void {
        $path = make_request_directory() . '/page.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('other.html', '<p>x</p>');
        $zip->close();
        $this->expectExceptionMessage(get_string('error_pagenoindex', 'local_nextpool', 'index.html'));
        $this->place('page', (string) file_get_contents($path), 'page.zip');
    }

    /**
     * A ZIP page larger unpacked than the site allows is refused before it becomes a version (bug109).
     */
    public function test_page_zip_too_large(): void {
        global $CFG;
        $CFG->maxbytes = 1024;
        $path = make_request_directory() . '/page.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('index.html', '<p>' . str_repeat('x', 2048) . '</p>');
        $zip->close();
        $this->assertLessThan(1024, filesize($path), 'Small packed, large unpacked.');
        $this->expectExceptionMessage(get_string('error_pagetoolarge', 'local_nextpool', display_size(1024)));
        $this->place('page', (string) file_get_contents($path), 'page.zip');
    }

    /**
     * A URL becomes a link file with the same address; addresses the catalogue cannot place stop the take-over.
     */
    public function test_url_round_trip(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $address = 'https://example.org/safety?lang=de&page=2';
        $url = $this->getDataGenerator()->create_module('url', ['course' => $course->id, 'name' => 'Rules',
            'externalurl' => $address]);
        [$bytes, $filename] = $this->extract('url', (int) $url->cmid);
        $this->assertSame('Rules.url', $filename);
        $cm = $this->place('url', $bytes, $filename);
        $this->assertSame($address, $DB->get_field('url', 'externalurl', ['id' => $cm->instance]));

        $DB->set_field('url', 'externalurl', 'ftp://example.org/file', ['id' => $url->id]);
        $this->expectExceptionMessage(get_string('error_invalidurlfile', 'local_nextpool'));
        $this->extract('url', (int) $url->cmid);
    }

    /**
     * A book becomes a ZIP of the core import format: visible chapters in order, subchapters, chapter files.
     */
    public function test_book_round_trip(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Manual']);
        /** @var \mod_book_generator $bookgenerator */
        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $intro = $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => 1, 'title' => 'Intro & welcome',
            'content' => '<p>Welcome</p><p><img src="@@PLUGINFILE@@/pic.png" alt="Exit" /></p>']);
        $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => 2, 'title' => 'Stairs', 'subchapter' => 1,
            'content' => '<p>Use the stairs.</p>']);
        $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => 3, 'title' => 'Draft', 'hidden' => 1,
            'content' => '<p>Not ready.</p>']);
        $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => 4, 'title' => 'Contacts',
            'content' => '<p>Call 112.</p>']);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($book->cmid)->id, 'component' => 'mod_book', 'filearea' => 'chapter',
            'itemid' => $intro->id, 'filepath' => '/', 'filename' => 'pic.png',
        ], base64_decode(self::PNG));

        [$bytes, $filename] = $this->extract('book', (int) $book->cmid);
        $this->assertSame('Manual.zip', $filename);
        $cm = $this->place('book', $bytes, $filename);

        $chapters = array_values($DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum'));
        $this->assertSame(['Intro & welcome', 'Stairs', 'Contacts'], array_column($chapters, 'title'));
        $this->assertSame(['0', '1', '0'], array_map('strval', array_column($chapters, 'subchapter')));
        $this->assertSame('<p>Use the stairs.</p>', trim($chapters[1]->content));
        $this->assertSame(
            '<p>Welcome</p><p><img src="@@PLUGINFILE@@/pic.png" alt="Exit" /></p>',
            str_replace('@@PLUGINFILE@@/files/1/', '@@PLUGINFILE@@/', trim($chapters[0]->content))
        );
        $this->assertTrue(get_file_storage()->file_exists(
            \context_module::instance($cm->id)->id,
            'mod_book',
            'chapter',
            $chapters[0]->id,
            '/files/1/',
            'pic.png'
        ));

        // A book with hidden chapters only has nothing to take over.
        $DB->set_field('book_chapters', 'hidden', 1, ['bookid' => $book->id]);
        $this->expectExceptionMessage(get_string('error_bookempty', 'local_nextpool'));
        $this->extract('book', (int) $book->cmid);
    }

    /**
     * Slots of a quiz: idnumber, page, mark and question text per slot.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    private static function quiz_slots(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $records = $DB->get_records_sql(
            "SELECT s.slot, s.page, s.maxmark, qbe.idnumber, q.questiontext
               FROM {quiz_slots} s
               JOIN {question_references} qr ON qr.itemid = s.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v WHERE v.questionbankentryid = qbe.id)
               JOIN {question} q ON q.id = qv.questionid
              WHERE s.quizid = ?
           ORDER BY s.slot",
            [$cm->instance]
        );
        return array_values(array_map(
            static fn($r) => [$r->idnumber, (int) $r->page, (float) $r->maxmark, strip_tags($r->questiontext)],
            $records
        ));
    }

    /**
     * A quiz with changes in the course becomes a quiz package: the questions the slots use, order, marks and pages;
     * a question added in the course gets an idnumber. Placing the package gives the same quiz.
     */
    public function test_quiz_round_trip(): void {
        global $DB;
        $package = make_request_directory() . '/quiz.zip';
        $zip = new \ZipArchive();
        $zip->open($package, \ZipArchive::CREATE);
        $zip->addFile(__DIR__ . '/fixtures/quiz/questions.xml', quiz_package::QUESTIONS_FILE);
        $zip->addFile(__DIR__ . '/fixtures/quiz/quiz.json', quiz_package::DESCRIPTION_FILE);
        $zip->close();
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'quiz'], (string) file_get_contents($package), 'quiz.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cmid = (int) $placement->cmid;
        $quiz = $DB->get_record('quiz', ['id' => get_coursemodule_from_id('quiz', $cmid)->instance]);
        $quiz->cmid = $cmid;
        $context = \context_module::instance($cmid);

        // The teacher corrects a question, changes a mark and adds a question on a new page.
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $sa1 = $DB->get_record_sql(
            "SELECT q.* FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = ? AND qbe.idnumber = 'sa1'",
            [$context->id]
        );
        $questiongenerator->update_question(\question_bank::load_question_data((int) $sa1->id), null, [
            'questiontext' => ['text' => 'Capital of France, please?', 'format' => FORMAT_HTML],
        ]);
        $DB->set_field('quiz_slots', 'maxmark', 4, ['quizid' => $quiz->id, 'slot' => 3]);
        $category = question_get_default_category($context->id, true);
        $added = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id,
            'questiontext' => ['text' => 'Capital of Spain?', 'format' => FORMAT_HTML]]);
        quiz_add_quiz_question($added->id, $quiz, 3, 1.5);
        $addedentryid = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $added->id]);

        [$bytes, $filename] = $this->extract('quiz', $cmid);
        $this->assertSame($quiz->name . '.zip', $filename);
        $path = make_request_directory() . '/extracted.zip';
        file_put_contents($path, $bytes);
        $read = quiz_package::read($path);
        $this->assertSame([
            ['question' => 'tf1', 'page' => 1, 'maxmark' => 2.0],
            ['question' => 'sa1', 'page' => 1, 'maxmark' => 1.0],
            ['question' => 'mc1', 'page' => 2, 'maxmark' => 4.0],
            ['question' => 'q' . $addedentryid, 'page' => 3, 'maxmark' => 1.5],
        ], $read['slots']);

        $cm = $this->place('quiz', $bytes, $filename);
        $expected = self::quiz_slots($cmid);
        // The question added in the course has no idnumber there; the catalogue gives it one.
        $this->assertNull($expected[3][0]);
        $expected[3][0] = 'q' . $addedentryid;
        $this->assertSame($expected, self::quiz_slots((int) $cm->id), 'The placed quiz is the same quiz.');
        $this->assertSame('Capital of France, please?', self::quiz_slots((int) $cm->id)[1][3]);
    }

    /**
     * Random questions and question types the catalogue does not take stop the take-over with their names (q30).
     */
    public function test_quiz_refuses_questions(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = question_get_default_category(\context_module::instance($quiz->cmid)->id, true);
        $handler = new quiz();
        $cm = get_fast_modinfo($course)->get_cm((int) $quiz->cmid);
        try {
            $handler->extract($cm, make_request_directory() . '/x.zip');
            $this->fail('An empty quiz has nothing to take over.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_extractquizempty', $e->errorcode);
        }

        $ok = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        $description = $questiongenerator->create_question('description', null, ['category' => $category->id,
            'name' => 'Read first']);
        $quiz->cmid = (int) $quiz->cmid;
        quiz_add_quiz_question($ok->id, $quiz, 1);
        quiz_add_quiz_question($description->id, $quiz, 1);
        // A random question is a slot with a question set reference instead of a question.
        $DB->insert_record('quiz_slots', (object) ['quizid' => $quiz->id, 'slot' => 3, 'page' => 1, 'maxmark' => 1]);

        try {
            $handler->extract(get_fast_modinfo($course)->get_cm((int) $quiz->cmid), make_request_directory() . '/x.zip');
            $this->fail('Refused questions stop the take-over.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_extractquizqtype', $e->errorcode);
            $this->assertStringContainsString('Read first', $e->getMessage());
            $this->assertStringContainsString(get_string('extract_randomquestion', 'local_nextpool', 3), $e->getMessage());
        }
    }

    /**
     * Page, URL, book, quiz and H5P take an activity over (q64); files and SCORM packages are uploaded.
     */
    public function test_supported_types(): void {
        foreach (['page', 'url', 'book', 'quiz', 'h5p'] as $type) {
            $this->assertTrue(item_types::get_handler($type)->supports_extract(), $type);
        }
        foreach (['file', 'scorm'] as $type) {
            $this->assertFalse(item_types::get_handler($type)->supports_extract(), $type);
        }
    }

    /**
     * The greeting card fixture of core, optionally with a changed text.
     *
     * @param string $marker Name of a property added to the content, '' for the fixture as it is.
     * @return string Bytes of the package.
     */
    private static function greeting_card(string $marker = ''): string {
        global $CFG;
        $source = make_request_directory() . '/card.h5p';
        copy($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p', $source);
        if ($marker !== '') {
            $zip = new \ZipArchive();
            $zip->open($source);
            $content = (string) $zip->getFromName('content/content.json');
            $zip->addFromString('content/content.json', str_replace('}', ',"' . $marker . '":true}', $content));
            $zip->close();
        }
        return (string) file_get_contents($source);
    }

    /**
     * Replace the package of an H5P activity, as core does when teachers edit the content in Moodle.
     *
     * @param \cm_info $cm H5P activity.
     * @param string $bytes New package.
     */
    private static function replace_package(\cm_info $cm, string $bytes): void {
        $fs = get_file_storage();
        $context = \context_module::instance($cm->id);
        $fs->delete_area_files($context->id, 'mod_h5pactivity', 'package', 0);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_h5pactivity',
            'filearea' => 'package',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'card.h5p',
        ], $bytes);
    }

    /**
     * H5P (q64 second step): an unchanged activity gives the snapshot back, also when the activity got the package
     * without its libraries (q39), so it is refused as unchanged; content changed in the course becomes the package
     * of the proposal, and placing it gives the same content. An activity without a valid package is refused.
     */
    public function test_h5p_round_trip(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'h5p'], self::greeting_card(), 'Cards/card.h5p', true);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $snapshot = version_manager::get_file((int) $item->currentversionid);

        [$bytes, $filename] = $this->extract('h5p', (int) $cm->id);
        $this->assertSame($snapshot->get_contenthash(), sha1($bytes));
        $this->assertStringEndsWith('.h5p', $filename);
        $contentonly = h5p::create_content_only_package($snapshot);
        self::replace_package($cm, (string) file_get_contents($contentonly));
        [$bytes] = $this->extract('h5p', (int) $cm->id);
        $this->assertSame($snapshot->get_contenthash(), sha1($bytes), 'Without libraries it is still unchanged.');
        try {
            proposal_manager::extract($cm, 'h5p', (int) $item->id);
            $this->fail('An unchanged H5P activity is no proposal.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_unchanged', $e->errorcode);
        }

        $changed = self::greeting_card('nextpoolchanged');
        self::replace_package($cm, $changed);
        [$localfile, $filename] = proposal_manager::extract($cm, 'h5p', (int) $item->id);
        $this->assertSame('card.h5p', $filename, 'The proposal keeps the file name of the item.');
        $this->assertSame(sha1($changed), sha1_file($localfile));

        $newcourse = $this->getDataGenerator()->create_course();
        $copy = $this->generator->create_published_item(
            ['type' => 'h5p'],
            (string) file_get_contents($localfile),
            'copy.h5p',
            true
        );
        $newplacement = placement_manager::place((int) $copy->id, (int) $newcourse->id, 0, policy::NOTIFY);
        $newcm = get_fast_modinfo($newcourse)->get_cm((int) $newplacement->cmid);
        [$roundtrip] = $this->extract('h5p', (int) $newcm->id);
        $this->assertSame(sha1($changed), sha1($roundtrip));
        $this->assertStringContainsString('nextpoolchanged', $this->zip_entry($roundtrip, 'content/content.json'));

        get_file_storage()->delete_area_files(\context_module::instance($newcm->id)->id, 'mod_h5pactivity', 'package', 0);
        $this->expectExceptionMessage(get_string('error_extracth5p', 'local_nextpool'));
        $this->extract('h5p', (int) $newcm->id);
    }

    /**
     * One entry of a ZIP given as bytes.
     *
     * @param string $bytes ZIP.
     * @param string $name Entry name.
     * @return string Content, '' if missing.
     */
    private function zip_entry(string $bytes, string $name): string {
        $path = make_request_directory() . '/entry.zip';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName($name);
        $zip->close();
        return $content;
    }

    /**
     * F07.AC01 with take-over: a teacher proposes the changed page of a placement as new version; the proposal keeps
     * the file name of the item and goes into review. Types without take-over are refused.
     */
    public function test_propose_from_activity(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $item = $this->generator->create_published_item(
            ['type' => 'page', 'title' => 'Evacuation'],
            '<p>Old</p>',
            'Safety/evacuation.html'
        );
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $DB->set_field('page', 'content', '<p>Meet at the car park.</p>', ['id' => $cm->instance]);

        // A role that may propose but not manage activities cannot take over their content (bug108).
        $proposer = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('local/nextpool:proposeversions', CAP_ALLOW, $roleid, \context_course::instance($course->id));
        $this->setUser($proposer);
        $this->assertFalse(proposal_manager::can_extract($cm, 'page'));
        $this->assertNull(proposal_manager::get_extract_cm($item, (int) $course->id));

        $this->setUser($teacher);
        $this->assertTrue(proposal_manager::can_extract($cm, 'page'));
        $this->assertSame((int) $cm->id, (int) proposal_manager::get_extract_cm($item, (int) $course->id)->id);
        [$localfile, $filename] = proposal_manager::extract($cm, 'page', (int) $item->id);
        $this->assertSame('evacuation.html', $filename);
        $version = proposal_manager::propose_version(
            (int) $item->id,
            (int) $course->id,
            $localfile,
            $filename,
            '<p>Meeting point added.</p>',
            FORMAT_HTML,
            [],
            (int) $cm->id
        );
        $this->assertSame(status::REVIEW, $version->status);
        $this->assertSame(version_manager::ORIGIN_PROPOSAL, $version->origin);
        $this->assertStringContainsString('Meet at the car park.', version_manager::get_file((int) $version->id)->get_content());
        $this->assertSame(
            (int) $placement->appliedversionid,
            (int) placement_manager::get((int) $placement->id)->appliedversionid,
            'Nothing in the course changes.'
        );

        // An unchanged activity is refused like an unchanged upload.
        $this->setAdminUser();
        $this->generator->publish_new_version((int) $item->id, (string) file_get_contents($localfile), 'Safety/evacuation.html');
        $this->setUser($teacher);
        try {
            proposal_manager::extract($cm, 'page', (int) $item->id);
            $this->fail('An unchanged activity is no proposal.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_unchanged', $e->errorcode);
        }

        // A file placed in the course cannot be taken over.
        $this->setAdminUser();
        $fileitem = $this->generator->create_published_item(['title' => 'Handout']);
        $fileplacement = placement_manager::place((int) $fileitem->id, (int) $course->id, 0, policy::NOTIFY);
        $filecm = get_fast_modinfo($course)->get_cm((int) $fileplacement->cmid);
        $this->assertFalse(proposal_manager::can_extract($filecm, 'file'));
        $this->assertNull(proposal_manager::get_extract_cm($fileitem, (int) $course->id));
        $this->expectExceptionMessage(get_string('error_extractnotsupported', 'local_nextpool'));
        proposal_manager::extract($filecm, 'file', (int) $fileitem->id);
    }
}
