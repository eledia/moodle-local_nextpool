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
use local_nextpool\local\catalogue_return;
use local_nextpool\local\item_types;
use local_nextpool\local\preview_assets;
use local_nextpool\local\version_manager;
use local_nextpool\output\item_preview;
use local_nextpool\output\item_summary;

/**
 * Catalogue previews without learning records or module instances (F16).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(preview_assets::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_summary::class)]
final class preview_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Teacher. */
    private \stdClass $teacher;
    /** @var \local_nextpool_generator Plugin generator. */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * ZIP fixture bytes.
     *
     * @param array $files Entries by name.
     * @return string
     */
    private function archive(array $files): string {
        $path = make_request_directory() . '/preview.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return file_get_contents($path);
    }

    /**
     * A current PDF is inline for teachers; students and stale preview URLs get no file.
     */
    public function test_file_preview_access_and_current_version(): void {
        $item = $this->generator->create_published_item(['title' => 'Lesson'], '%PDF-1.4 lesson');
        $this->setUser($this->teacher);
        $version = version_manager::get((int) $item->currentversionid);
        $result = item_types::get_handler('file')->get_preview($item, $version, $this->course->id);
        $this->assertSame('frame', $result->kind);
        $this->assertNotNull(preview_assets::find($version->id, $this->course->id, 'handout.pdf'));
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->assertFalse(access::can_preview($item, $this->course->id));
        $this->assertNull(preview_assets::find($version->id, $this->course->id, 'handout.pdf'));
        $this->setAdminUser();
        $this->generator->publish_new_version($item->id, '%PDF-1.4 new');
        $this->assertNull(preview_assets::find($version->id, $this->course->id, 'handout.pdf'));
    }

    /**
     * SVG and HTML files have no inline delivery, independent of their licence.
     */
    public function test_file_preview_rejects_active_formats(): void {
        $item = $this->generator->create_published_item([], '<svg onload="alert(1)"></svg>', 'diagram.svg');
        $version = version_manager::get((int) $item->currentversionid);
        $result = item_types::get_handler('file')->get_preview($item, $version, $this->course->id);
        $this->assertSame('unavailable', $result->kind);
        $this->assertSame('', $result->url);
        $this->assertNull(preview_assets::find($version->id, $this->course->id, 'diagram.svg'));
    }

    /**
     * Page preview strips scripts and serves only safe referenced assets through the access check.
     */
    public function test_page_preview_sanitizes_html_and_proxies_assets(): void {
        $item = $this->generator->create_published_item(['type' => 'page'], $this->archive([
            'index.html' => '<html><body><script>alert(1)</script><p>Lesson</p>'
                . '<img src="files/photo.png" alt="Photo"></body></html>',
            'files/photo.png' => base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='
            ),
        ]), 'page.zip');
        $this->setUser($this->teacher);
        $version = version_manager::get((int) $item->currentversionid);
        $result = item_types::get_handler('page')->get_preview($item, $version, $this->course->id);
        $this->assertSame('html', $result->kind);
        $this->assertStringNotContainsString('<script', $result->html);
        $this->assertStringContainsString('/local_nextpool/preview/', $result->html);
        $this->assertNotNull(preview_assets::find($version->id, $this->course->id, 'files/photo.png'));
        $this->assertNull(preview_assets::find($version->id, $this->course->id, '../index.html'));
    }

    /**
     * Preview rendering writes no activity, grade, completion or learner record; only its own log event.
     */
    public function test_preview_creates_no_learning_records(): void {
        global $DB, $PAGE;
        $item = $this->generator->create_published_item(['type' => 'page'], '<p>Lesson</p>', 'page.html');
        $tables = ['course_modules', 'course_modules_completion', 'grade_grades', 'local_eledia_lrs_statement',
            'local_eledia_lrs_reg', 'xapi_states', 'h5pactivity_attempts', 'quiz_attempts', 'scorm_scoes_value'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $DB->count_records($table);
        }
        $this->setUser($this->teacher);
        $PAGE->set_context(\context_course::instance($this->course->id));
        $sink = $this->redirectEvents();
        $data = item_preview::data($item, $this->course->id, $PAGE->get_renderer('core'), ['search' => 'Lesson']);
        item_preview::log($item, $this->course->id, 'page');
        $this->assertTrue($data['hashtml']);
        $this->assertStringContainsString('search=Lesson', $data['returnurl']);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], $DB->count_records($table), $table);
        }
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof \local_nextpool\event\item_previewed);
        $this->assertCount(1, $events);
        $this->assertEquals($this->course->id, reset($events)->courseid);
    }

    /**
     * Filters cannot introduce a return URL or unvalidated query fields.
     */
    public function test_catalogue_return_is_allowlisted(): void {
        $url = catalogue_return::url($this->course->id, 42, ['search' => 'Lesson', 'page' => 2,
            'returnurl' => 'https://attacker.invalid/', 'sesskey' => 'leak']);
        $this->assertStringContainsString('search=Lesson', $url->out(false));
        $this->assertStringContainsString('#local-nextpool-item-42', $url->out(false));
        $this->assertArrayNotHasKey('returnurl', $url->params());
        $this->assertArrayNotHasKey('sesskey', $url->params());
    }
    /**
     * Book chapters and external links are previewed without creating their Moodle activities.
     */
    public function test_book_and_link_previews(): void {
        global $DB;
        $book = $this->generator->create_published_item(['type' => 'book'], $this->archive([
            '1.html' => '<title>First chapter</title><body><p>Start here.</p></body>',
            '2.html' => '<title>Second chapter</title><body><p>Finish here.</p></body>',
        ]), 'book.zip');
        $result = item_types::get_handler('book')->get_preview(
            $book,
            version_manager::get((int) $book->currentversionid),
            $this->course->id
        );
        $this->assertSame('chapters', $result->kind);
        $this->assertCount(2, $result->chapters);
        $this->assertSame('First chapter', $result->chapters[0]['title']);
        $this->assertStringContainsString('Finish here.', $result->chapters[1]['html']);
        $url = $this->generator->create_published_item(['type' => 'url'], 'https://example.com/lesson', 'lesson.url');
        $link = item_types::get_handler('url')->get_preview(
            $url,
            version_manager::get((int) $url->currentversionid),
            $this->course->id
        );
        $this->assertSame('link', $link->kind);
        $this->assertSame('https://example.com/lesson', $link->url);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $this->course->id]));
    }

    /**
     * A quiz preview contains question text and points, never answers or an attempt.
     */
    public function test_quiz_preview_has_no_answers_or_attempts(): void {
        global $DB;
        $item = $this->generator->create_published_item(['type' => 'quiz'], $this->archive([
            'questions.xml' => file_get_contents(__DIR__ . '/fixtures/quiz/questions.xml'),
            'quiz.json' => file_get_contents(__DIR__ . '/fixtures/quiz/quiz.json'),
        ]), 'quiz.zip');
        $result = item_types::get_handler('quiz')->get_preview(
            $item,
            version_manager::get((int) $item->currentversionid),
            $this->course->id
        );
        $this->assertSame('html', $result->kind);
        $this->assertStringContainsString('What is the capital of France?', $result->html);
        $this->assertStringNotContainsString('Paris', $result->html);
        $this->assertStringContainsString('3 questions', $result->html);
        $this->assertSame(0, $DB->count_records('quiz_attempts'));
        $this->assertSame(0, $DB->count_records('quiz'));
    }

    /**
     * H5P preview copies have unpredictable names and disappear with their version.
     */
    public function test_h5p_preview_copy_and_cleanup(): void {
        global $CFG, $DB, $PAGE;
        $item = $this->generator->create_published_item(
            ['type' => 'h5p'],
            file_get_contents($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p'),
            'card.h5p',
            true
        );
        $version = version_manager::get((int) $item->currentversionid);
        $handler = new \local_nextpool\local\content_type\h5p();
        if (!\local_nextpool\local\h5p_preview::supported()) {
            $this->assertSame('unavailable', $handler->get_preview($item, $version, $this->course->id)->kind);
            $this->assertSame(0, $DB->count_records('h5p'));
            return;
        }
        $file = $handler->get_preview_package($version);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.h5p$/D', $file->get_filename());
        $this->assertEquals($file->get_id(), $handler->get_preview_package($version)->get_id());
        $PAGE->set_context(\context_course::instance($this->course->id));
        $PAGE->set_url('/local/nextpool/preview_h5p.php', ['itemid' => $item->id, 'courseid' => $this->course->id]);
        $preview = \local_nextpool\local\h5p_preview::prepare((int) $version->id, (int) $this->course->id);
        $this->assertNotEmpty(\local_nextpool\local\h5p_preview::render($preview));
        $this->assertSame(0, $DB->count_records('h5pactivity_attempts'));
        $this->assertSame(0, $DB->count_records('local_eledia_lrs_statement'));
        preview_assets::delete((int) $version->id);
        $this->assertEmpty(get_file_storage()->get_area_files(
            \local_nextpool\local\h5p_preview::context()->id,
            'local_nextpool',
            'previewh5p',
            $version->id,
            'id',
            false
        ));
    }

    /**
     * Content language annotates text, without changing the language of surrounding controls.
     */
    public function test_content_language_in_catalogue(): void {
        global $PAGE;
        $item = $this->generator->create_published_item([
            'title' => 'Bonjour', 'description' => 'Une description.', 'language' => 'fr',
        ]);
        $PAGE->set_context(\context_course::instance($this->course->id));
        $PAGE->set_url('/local/nextpool/catalogue.php', ['courseid' => $this->course->id]);
        $output = $PAGE->get_renderer('core');
        $page = new \local_nextpool\output\catalogue_page($this->course->id, [$item->id => $item], [], true);
        $html = $output->render_from_template('local_nextpool/catalogue_page', $page->export_for_template($output));
        $this->assertMatchesRegularExpression('/<h3[^>]+lang="fr"/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]+lang="fr"[^>]*>Une description\./', $html);
    }

    /**
     * A teacher can preview content while being prohibited from creating that activity type.
     */
    public function test_preview_does_not_offer_a_forbidden_activity_type(): void {
        global $DB, $PAGE;
        $item = $this->generator->create_published_item(['type' => 'file'], '%PDF-1.4 lesson');
        $context = \context_course::instance($this->course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/resource:addinstance', CAP_PROHIBIT, $roleid, $context->id);
        $this->setUser($this->teacher);
        $PAGE->set_context($context);
        $this->assertTrue(access::can_preview($item, $this->course->id));
        $data = item_preview::header($item, $this->course->id, $PAGE->get_renderer('core'));
        $this->assertFalse($data['canplace']);
    }
}
