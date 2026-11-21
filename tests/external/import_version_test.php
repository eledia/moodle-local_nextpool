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

namespace local_nextpool\external;

use core_external\external_api;
use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Tests for the web service local_nextpool_import_version (F06, CI push).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(import_version::class)]
final class import_version_test extends \advanced_testcase {
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
     * Call the function as a client would, including the return value cleaning.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function call(array $args): array {
        $result = import_version::execute(...$args);
        return external_api::clean_returnvalue(import_version::execute_returns(), $result);
    }

    /**
     * A new file becomes a version in the target status; an unchanged file returns the latest version.
     */
    public function test_import_and_publish(): void {
        $item = $this->generator->create_published_item([], 'version 1', 'handout.pdf');
        $source = manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'handout.pdf', 'version 2');

        $result = $this->call([
            'itemuuid' => $item->uuid, 'path' => 'handout.pdf', 'label' => '2.0',
            'changelog' => '<p>From CI</p>', 'targetstatus' => status::PUBLISHED,
        ]);
        $this->assertSame(2, $result['versionnr']);
        $this->assertSame(status::PUBLISHED, $result['status']);
        $this->assertFalse($result['unchanged']);
        $this->assertEquals($result['versionid'], item_manager::get((int) $item->id)->currentversionid);
        $this->assertSame('2.0', version_manager::get($result['versionid'])->label);

        $again = $this->call(['itemuuid' => $item->uuid, 'path' => 'handout.pdf', 'targetstatus' => status::PUBLISHED]);
        $this->assertTrue($again['unchanged']);
        $this->assertSame($result['versionid'], $again['versionid']);

        $this->generator->put_file($source, 'handout.pdf', 'version 3');
        $draft = $this->call(['itemuuid' => $item->uuid, 'path' => 'handout.pdf']);
        $this->assertSame(status::DRAFT, $draft['status']);
        $this->assertSame(3, $draft['versionnr']);
    }

    /**
     * An editor without review rights may import but not publish; nothing is imported then.
     */
    public function test_rights(): void {
        global $DB;
        $item = $this->generator->create_published_item([], 'version 1', 'handout.pdf');
        $this->generator->put_file(manager::get((int) $item->sourceid), 'handout.pdf', 'version 2');

        $editor = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $editor->id, \context_system::instance());
        $this->setUser($editor);

        try {
            $this->call(['itemuuid' => $item->uuid, 'path' => 'handout.pdf', 'targetstatus' => status::PUBLISHED]);
            $this->fail('Publishing needs the review capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
        }
        $review = $this->call(['itemuuid' => $item->uuid, 'path' => 'handout.pdf', 'targetstatus' => status::REVIEW]);
        $this->assertSame(status::REVIEW, $review['status']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        $this->call(['itemuuid' => $item->uuid, 'path' => 'handout.pdf']);
    }

    /**
     * Item editors import the file of their item only, not any other file of the storage location (bug102, F08.AC08).
     */
    public function test_item_editor_imports_own_file_only(): void {
        global $DB;
        $item = $this->generator->create_published_item([], 'version 1', 'Safety/handout.pdf');
        // Another item in the same storage location.
        $other = $this->generator->create_published_item(['sourceid' => $item->sourceid], 'other 1', 'Internal/salaries.pdf');
        $source = manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'Safety/handout.pdf', 'version 2');
        $this->generator->put_file($source, 'Internal/salaries.pdf', 'other 2');
        $editor = $this->getDataGenerator()->create_user();
        item_editor_manager::add((int) $item->id, (int) $editor->id);
        $this->setUser($editor);

        foreach (['Internal/salaries.pdf', '/Internal//salaries.pdf'] as $path) {
            try {
                $this->call(['itemuuid' => $item->uuid, 'path' => $path]);
                $this->fail('Item editors may not import other files: ' . $path);
            } catch (\required_capability_exception $e) {
                $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
            }
        }
        try {
            $this->call(['itemuuid' => $other->uuid, 'path' => 'Internal/salaries.pdf']);
            $this->fail('Item editors may not import into other items.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $other->id]));
        }

        $draft = $this->call(['itemuuid' => $item->uuid, 'path' => '/Safety/handout.pdf']);
        $this->assertSame(status::DRAFT, $draft['status']);
        $this->assertSame(2, $draft['versionnr']);
    }

    /**
     * h5plibraries publishes an H5P version with library updates allowed, like the option in the publish form;
     * it is off by default and needs the same right as publishing (q39, bug89).
     */
    public function test_h5p_library_option(): void {
        global $CFG, $DB;
        $fixture = fn(string $name) => (string) file_get_contents($CFG->dirroot . '/h5p/tests/fixtures/' . $name);
        $item = $this->generator->create_published_item(['type' => 'h5p'], $fixture('greeting-card.h5p'), 'card.h5p');
        $source = manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'card.h5p', $fixture('drag.h5p'));

        // Default: off.
        $result = $this->call(['itemuuid' => $item->uuid, 'path' => 'card.h5p', 'targetstatus' => status::PUBLISHED]);
        $this->assertSame(0, (int) version_manager::get($result['versionid'])->h5plibraries);

        $this->generator->put_file($source, 'card.h5p', $fixture('greeting-card.h5p'));
        $result = $this->call([
            'itemuuid' => $item->uuid, 'path' => 'card.h5p', 'targetstatus' => status::PUBLISHED, 'h5plibraries' => true,
        ]);
        $version = version_manager::get($result['versionid']);
        $this->assertSame(status::PUBLISHED, $result['status']);
        $this->assertSame(1, (int) $version->h5plibraries);
        $this->assertTrue(\local_nextpool\local\content_type\h5p::allows_library_updates($version));

        // Only together with publishing an H5P item; nothing is imported otherwise.
        $this->generator->put_file($source, 'card.h5p', $fixture('drag.h5p'));
        $count = $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]);
        foreach ([status::DRAFT, status::REVIEW] as $targetstatus) {
            try {
                $this->call([
                    'itemuuid' => $item->uuid, 'path' => 'card.h5p', 'targetstatus' => $targetstatus, 'h5plibraries' => true,
                ]);
                $this->fail('h5plibraries needs targetstatus published.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('h5plibraries', $e->getMessage());
            }
        }
        $file = $this->generator->create_published_item([], 'version 1', 'handout.pdf');
        $this->generator->put_file(manager::get((int) $file->sourceid), 'handout.pdf', 'version 2');
        try {
            $this->call([
                'itemuuid' => $file->uuid, 'path' => 'handout.pdf', 'targetstatus' => status::PUBLISHED, 'h5plibraries' => true,
            ]);
            $this->fail('h5plibraries needs an H5P item.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('h5plibraries', $e->getMessage());
        }
        $this->assertSame($count, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));

        // The same right as the publish form: whoever may not publish cannot set the option either.
        $editor = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $editor->id, \context_system::instance());
        $this->setUser($editor);
        try {
            $this->call([
                'itemuuid' => $item->uuid, 'path' => 'card.h5p', 'targetstatus' => status::PUBLISHED, 'h5plibraries' => true,
            ]);
            $this->fail('Publishing needs the review capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame($count, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
        }
    }

    /**
     * Unknown items, unknown target states and missing files are rejected.
     */
    public function test_invalid_input(): void {
        $item = $this->generator->create_published_item([], 'version 1', 'handout.pdf');
        try {
            $this->call(['itemuuid' => $item->uuid, 'path' => 'handout.pdf', 'targetstatus' => 'withdrawn']);
            $this->fail('Only draft, review and published are allowed.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('targetstatus', $e->getMessage());
        }
        try {
            $this->call(['itemuuid' => $item->uuid, 'path' => 'missing.pdf']);
            $this->fail('A missing file must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_notfound', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        $this->call(['itemuuid' => '00000000-0000-4000-8000-000000000000', 'path' => 'handout.pdf']);
    }
}
