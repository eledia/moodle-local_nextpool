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

use local_nextpool\local\content_type\book;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;

/**
 * Tests for the book content type: ZIP with one HTML file per chapter, updated in place (q16).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(book::class)]
final class content_type_book_test extends \advanced_testcase {
    /**
     * ZIP with the given files.
     *
     * @param array $files Path in the ZIP => content.
     * @return string
     */
    private function zip(array $files): string {
        $path = make_request_directory() . '/book.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return (string) file_get_contents($path);
    }

    /**
     * A chapter file.
     *
     * @param string $title Title.
     * @param string $body Body HTML.
     * @return string
     */
    private function chapter(string $title, string $body): string {
        return "<html><head><title>$title</title></head><body>$body</body></html>";
    }

    /**
     * A book ZIP becomes a book with cleaned chapters; a new version replaces the chapters in the same book.
     */
    public function test_place_and_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $course = $this->getDataGenerator()->create_course();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $version1 = $this->zip([
            '01_intro.html' => $this->chapter('Intro', '<p>Welcome</p><img src="img/pic.png" alt="Exit">'),
            '02_safety.html' => $this->chapter('Safety', '<p onclick="alert(1)">Stairs</p><script>alert(2)</script>'),
            'img/pic.png' => $png,
        ]);
        $item = $generator->create_published_item(['type' => 'book'], $version1, 'manual.zip');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('book', $cm->modname);
        $chapters = array_values($DB->get_records('book_chapters', ['bookid' => $cm->instance], 'pagenum'));
        $this->assertSame(['Intro', 'Safety'], array_column($chapters, 'title'));
        $this->assertStringNotContainsString('script', $chapters[1]->content);
        $this->assertStringNotContainsString('onclick', $chapters[1]->content);
        $this->assertStringContainsString('@@PLUGINFILE@@', $chapters[0]->content, 'Images are relinked to the chapter files.');
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($context->id, 'mod_book', 'chapter', $chapters[0]->id, '/img/', 'pic.png'));
        $revision = (int) $DB->get_field('book', 'revision', ['id' => $cm->instance]);

        $version2 = $generator->publish_new_version((int) $item->id, $this->zip([
            '01_intro.html' => $this->chapter('Intro', '<p>Welcome back</p>'),
            '02_safety.html' => $this->chapter('Safety', '<p>Stairs</p>'),
            '03_contacts.html' => $this->chapter('Contacts', '<p>Call 112</p>'),
        ]), 'manual.zip');
        $this->assertNull((new book())->check_update($cm, $item, $version2));
        placement_manager::apply((int) $placement->id, (int) $version2->id);

        $titles = $DB->get_fieldset('book_chapters', 'title', ['bookid' => $cm->instance]);
        $this->assertEqualsCanonicalizing(['Intro', 'Safety', 'Contacts'], $titles);
        $this->assertSame((int) $placement->cmid, (int) placement_manager::get((int) $placement->id)->cmid);
        $this->assertGreaterThan($revision, (int) $DB->get_field('book', 'revision', ['id' => $cm->instance]));
        $this->assertFalse(
            get_file_storage()->file_exists($context->id, 'mod_book', 'chapter', $chapters[0]->id, '/img/', 'pic.png')
        );

        // A version without HTML files is no book. Importing refuses it since bug205; one stored before is checked here.
        $DB->set_field('local_nextpool_item', 'type', 'file', ['id' => $item->id]);
        $empty = $generator->publish_new_version((int) $item->id, $this->zip(['readme.txt' => 'no chapters']), 'manual.zip');
        $DB->set_field('local_nextpool_item', 'type', 'book', ['id' => $item->id]);
        $this->assertSame(get_string('error_bookempty', 'local_nextpool'), (new book())->check_update($cm, $item, $empty));
    }
}
