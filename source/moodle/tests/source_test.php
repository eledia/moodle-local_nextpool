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

namespace nextpoolsource_moodle;

use local_nextpool\local\source\manager;
use local_nextpool\local\source\source_exception;

/**
 * Tests for the Moodle file storage backend.
 *
 * @package   nextpoolsource_moodle
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source::class)]
final class source_test extends \advanced_testcase {
    /**
     * Write a string into a backend.
     *
     * @param source $source Backend.
     * @param string $path Path.
     * @param string $content Content.
     */
    private function put(source $source, string $path, string $content): void {
        $file = make_request_directory() . '/in';
        file_put_contents($file, $content);
        $source->write($path, $file);
    }

    /**
     * The Moodle file storage has no web interface: no link "Open in storage location" (F08.AC10, task84).
     */
    public function test_no_web_url(): void {
        $this->resetAfterTest();
        /** @var source $source */
        $source = manager::create('moodle', 'Local', ['basefolder' => 'Catalogue']);
        $this->put($source, 'handout.pdf', 'pdf');
        $this->assertFalse($source->supports_web_url());
        $this->assertNull($source->get_web_url('handout.pdf'));
    }

    /**
     * Write, stat, list and read inside a base folder.
     */
    public function test_roundtrip(): void {
        $this->resetAfterTest();
        /** @var source $source */
        $source = manager::create('moodle', 'Local', ['basefolder' => 'Catalogue']);
        $this->put($source, 'Safety/handout.pdf', 'pdf-content');
        $this->put($source, 'readme.txt', 'hello');

        $entry = $source->stat('Safety/handout.pdf');
        $this->assertSame('Safety/handout.pdf', $entry->path);
        $this->assertSame('handout.pdf', $entry->get_name());
        $this->assertFalse($entry->isfolder);
        $this->assertSame(11, $entry->size);
        $this->assertSame(sha1('pdf-content'), $entry->revision);

        $this->assertTrue($source->stat('Safety')->isfolder);
        $this->assertTrue($source->stat('')->isfolder);
        $this->assertNull($source->stat('missing.txt'));

        $list = $source->list_entries('');
        $this->assertSame(['Safety', 'readme.txt'], array_map(fn($e) => $e->path, $list));
        $this->assertSame(['Safety/handout.pdf'], array_map(fn($e) => $e->path, $source->list_entries('Safety')));

        $target = make_request_directory() . '/out';
        $source->read('Safety/handout.pdf', $target);
        $this->assertSame('pdf-content', file_get_contents($target));

        // Overwriting changes the revision.
        $this->put($source, 'Safety/handout.pdf', 'changed');
        $this->assertSame(sha1('changed'), $source->stat('Safety/handout.pdf')->revision);
    }

    /**
     * Two instances do not see each other's files, and nothing leaves the base folder.
     */
    public function test_isolation(): void {
        $this->resetAfterTest();
        /** @var source $a */
        $a = manager::create('moodle', 'A', ['basefolder' => 'Base']);
        /** @var source $b */
        $b = manager::create('moodle', 'B', []);
        $this->put($a, 'x.txt', 'a');

        $this->assertNull($b->stat('x.txt'));
        $this->assertNull($b->stat('Base/x.txt'));
        $this->assertEmpty($b->list_entries(''));

        $this->expectException(source_exception::class);
        $a->stat('../x.txt');
    }

    /**
     * Deleting removes one file of this instance, ignores missing files and keeps folders (TD-405).
     */
    public function test_delete(): void {
        $this->resetAfterTest();
        /** @var source $source */
        $source = manager::create('moodle', 'Local', ['basefolder' => 'Uploads']);
        /** @var source $other */
        $other = manager::create('moodle', 'Other', ['basefolder' => 'Uploads']);
        $this->put($source, 'kurs-2/7-package.zip', 'zip');
        $this->put($source, 'kurs-2/keep.txt', 'x');
        $this->put($other, 'kurs-2/7-package.zip', 'zip');

        $source->delete('kurs-2/7-package.zip');
        $this->assertNull($source->stat('kurs-2/7-package.zip'));
        $this->assertNotNull($source->stat('kurs-2/keep.txt'));
        $this->assertNotNull($other->stat('kurs-2/7-package.zip'), 'Other instances keep their files.');

        // Already gone: nothing to do, no error.
        $source->delete('kurs-2/7-package.zip');

        $this->expectException(source_exception::class);
        $source->delete('../kurs-2/keep.txt');
    }

    /**
     * Reading a missing file or a folder fails.
     */
    public function test_read_errors(): void {
        $this->resetAfterTest();
        /** @var source $source */
        $source = manager::create('moodle', 'Local', []);
        $this->put($source, 'dir/a.txt', 'a');
        $target = make_request_directory() . '/out';

        try {
            $source->read('nope.txt', $target);
            $this->fail('Expected exception.');
        } catch (source_exception $e) {
            $this->assertSame('error_notfound', $e->errorcode);
        }
        $this->expectException(source_exception::class);
        $source->list_entries('nope');
    }
}
