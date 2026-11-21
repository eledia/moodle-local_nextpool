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

use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * A file uploaded with a new item becomes draft version 1 (task101, test98).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
final class item_upload_test extends \advanced_testcase {
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
     * Local file with the given content.
     *
     * @param string $content Content.
     * @return string Absolute path.
     */
    private function local_file(string $content): string {
        $path = make_request_directory() . '/upload';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * The file is written into the base folder of the storage location and stored as draft version 1.
     */
    public function test_upload_creates_version_1(): void {
        $source = $this->generator->create_source('moodle', ['basefolder' => 'Catalogue']);
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        $version = version_manager::create_from_upload((int) $item->id, $this->local_file('first'), 'Handout 1.pdf');

        $this->assertEquals(1, $version->versionnr);
        $this->assertSame(version_manager::build_label('Handout 1.pdf'), $version->label);
        $this->assertSame(status::DRAFT, $version->status);
        $this->assertSame('Handout 1.pdf', $version->filename);
        $this->assertSame('Handout 1.pdf', $version->sourceref);
        $this->assertSame('first', version_manager::get_file((int) $version->id)->get_content());
        $entry = source_manager::get($source->get_id())->stat('Handout 1.pdf');
        $this->assertNotNull($entry);
        // The storage location check sees the file as unchanged and creates no second draft.
        $this->assertNull(version_manager::check_for_update((int) $item->id));
        $this->assertCount(1, version_manager::get_versions((int) $item->id));
    }

    /**
     * An existing file in the storage location is never replaced.
     */
    public function test_existing_file_is_not_replaced(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'handout.pdf', 'other item');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        try {
            version_manager::create_from_upload((int) $item->id, $this->local_file('mine'), 'handout.pdf');
            $this->fail('An existing file must not be replaced.');
        } catch (source_exception $e) {
            $this->assertSame('error_fileexists', $e->errorcode);
        }
        $this->assertSame([], version_manager::get_versions((int) $item->id));
        $tempfile = make_request_directory() . '/check';
        source_manager::get($source->get_id())->read('handout.pdf', $tempfile);
        $this->assertSame('other item', file_get_contents($tempfile));
    }

    /**
     * The upload is only for items without versions.
     */
    public function test_only_for_items_without_versions(): void {
        $source = $this->generator->create_source();
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        version_manager::create_from_upload((int) $item->id, $this->local_file('first'), 'a.txt');

        $this->expectException(\coding_exception::class);
        version_manager::create_from_upload((int) $item->id, $this->local_file('second'), 'b.txt');
    }

    /**
     * A file name that cleans to nothing is refused before anything is written.
     */
    public function test_invalid_file_name(): void {
        $source = $this->generator->create_source();
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        $this->expectException(source_exception::class);
        version_manager::create_from_upload((int) $item->id, $this->local_file('x'), '../');
    }
}
