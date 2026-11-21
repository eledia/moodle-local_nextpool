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

use local_nextpool\local\content_type\page;
use local_nextpool\local\content_type\url;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;

/**
 * Tests for the page and url content type handlers (F04).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(url::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nextpool\local\content_type\base::class)]
final class content_type_test extends \advanced_testcase {
    /**
     * An HTML file becomes a page; an update rewrites the content of the same page.
     */
    public function test_page_place_and_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $course = $this->getDataGenerator()->create_course();
        $item = $generator->create_published_item(
            ['type' => 'page', 'title' => 'Evacuation'],
            '<html><head><title>x</title></head><body><h2>Exits</h2><p>Use the stairs.</p></body></html>',
            'evacuation.html'
        );
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('page', $cm->modname);
        $record = $DB->get_record('page', ['id' => $cm->instance]);
        $this->assertSame('<h2>Exits</h2><p>Use the stairs.</p>', $record->content);

        $version2 = $generator->publish_new_version((int) $item->id, '<p>Use the stairs, not the lift.</p>', 'evacuation.html');
        placement_manager::apply((int) $placement->id, (int) $version2->id);
        $updated = $DB->get_record('page', ['id' => $cm->instance]);
        $this->assertSame('<p>Use the stairs, not the lift.</p>', $updated->content);
        $this->assertGreaterThan((int) $record->revision, (int) $updated->revision);

        $version3 = $generator->publish_new_version(
            (int) $item->id,
            '<p onclick="alert(1)">Stairs</p><script>alert(2)</script>',
            'evacuation.html'
        );
        placement_manager::apply((int) $placement->id, (int) $version3->id);
        $content = $DB->get_field('page', 'content', ['id' => $cm->instance]);
        $this->assertStringContainsString('Stairs', $content);
        $this->assertStringNotContainsString('script', $content);
        $this->assertStringNotContainsString('onclick', $content);
    }

    /**
     * A link file becomes a URL resource; an update changes the address of the same resource.
     */
    public function test_url_place_and_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $course = $this->getDataGenerator()->create_course();
        $item = $generator->create_published_item(['type' => 'url'], "https://example.com/first\n", 'link.txt');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::AUTO);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('https://example.com/first', $DB->get_field('url', 'externalurl', ['id' => $cm->instance]));

        $shortcut = "[InternetShortcut]\nURL=https://example.com/second\n";
        $version2 = $generator->publish_new_version((int) $item->id, $shortcut, 'link.txt');
        placement_manager::apply((int) $placement->id, (int) $version2->id);
        $this->assertSame('https://example.com/second', $DB->get_field('url', 'externalurl', ['id' => $cm->instance]));
    }

    /**
     * Link files in different notations.
     *
     * @return array
     */
    public static function url_provider(): array {
        return [
            'plain' => ["https://example.com/a\n", 'https://example.com/a'],
            'shortcut' => ["[InternetShortcut]\r\nURL=http://example.com/b\r\n", 'http://example.com/b'],
            'leading blank lines' => ["\n\n  https://example.com/c  \n", 'https://example.com/c'],
            'ftp' => ['ftp://example.com/d', null],
            'javascript' => ['javascript:alert(1)', null],
            'empty' => ['', null],
        ];
    }

    /**
     * Reading the address from a link file.
     *
     * @param string $content File content.
     * @param string|null $expected Address, or null if the file must be rejected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('url_provider')]
    public function test_extract_url(string $content, ?string $expected): void {
        if ($expected === null) {
            $this->expectException(\moodle_exception::class);
        }
        $this->assertSame($expected, url::extract_url($content));
    }

    /**
     * Item types with the file each one needs.
     *
     * @return array
     */
    public static function intro_provider(): array {
        return [
            'file' => ['file', 'resource', 'handout.pdf', 'pdf', (int) FORMAT_HTML],
            'page' => ['page', 'page', 'page.html', '<p>Page</p>', (int) FORMAT_HTML],
            'url' => ['url', 'url', 'link.txt', 'https://example.com/', (int) FORMAT_HTML],
            'markdown' => ['page', 'page', 'page.html', '<p>Page</p>', (int) FORMAT_MARKDOWN],
        ];
    }

    /**
     * The item description becomes a cleaned intro, since core shows intros without cleaning (bug41).
     *
     * @param string $type Item type.
     * @param string $modname Module the type creates.
     * @param string $path File name.
     * @param string $content File content.
     * @param int $format Format of the description.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('intro_provider')]
    public function test_intro_is_cleaned(string $type, string $modname, string $path, string $content, int $format): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item([
            'type' => $type,
            'description' => '<p onclick="steal()">Safety <b>first</b></p><script>steal()</script>',
            'descriptionformat' => $format,
        ], $content, $path);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame($modname, $cm->modname);
        $intro = $DB->get_record($modname, ['id' => $cm->instance], 'intro, introformat');
        $this->assertEquals(FORMAT_HTML, $intro->introformat);
        $this->assertStringContainsString('<b>first</b>', $intro->intro);
        $this->assertStringNotContainsString('<script', $intro->intro);
        $this->assertStringNotContainsString('onclick', $intro->intro);
    }
}
