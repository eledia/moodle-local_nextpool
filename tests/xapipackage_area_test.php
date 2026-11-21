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

use local_nextpool\local\item_manager;
use local_nextpool\local\version_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/nextpool/lib.php');

/**
 * Tests for the file area of unpacked xAPI packages (task45).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_nextpool_pluginfile')]
final class xapipackage_area_test extends \advanced_testcase {
    /**
     * Draft item of type xapi with one version.
     *
     * @param array $record Item fields.
     * @return array{0:\stdClass,1:\stdClass} Item and version.
     */
    private function create_item_with_version(array $record = []): array {
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_item(['type' => 'xapi'] + $record);
        // A real TinCan package: the xAPI handler of mod_nextpool checks it on import (task78d).
        $package = $this->getDataGenerator()->get_plugin_generator('mod_nextpool')->create_package();
        $generator->put_file(\local_nextpool\local\source\manager::get((int) $item->sourceid), 'package.zip', $package);
        $version = version_manager::create_from_source((int) $item->id, 'package.zip');
        return [item_manager::get((int) $item->id), $version];
    }

    /**
     * Store a file of an unpacked package, as mod_nextpool will.
     *
     * @param int $contextid Item context.
     * @param int $versionid Version id.
     * @param string $filepath Path inside the package.
     * @param string $filename File name.
     * @return \stored_file
     */
    private function add_package_file(int $contextid, int $versionid, string $filepath, string $filename): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'local_nextpool',
            'filearea' => version_manager::FILEAREA_XAPIPACKAGE,
            'itemid' => $versionid,
            'filepath' => $filepath,
            'filename' => $filename,
        ], '<html></html>');
    }

    /**
     * Number of files (without directories) in the package area of a version.
     *
     * @param int $contextid Context.
     * @param int $versionid Version id.
     * @return int
     */
    private function count_package_files(int $contextid, int $versionid): int {
        return count(get_file_storage()->get_area_files(
            $contextid,
            'local_nextpool',
            version_manager::FILEAREA_XAPIPACKAGE,
            $versionid,
            'id',
            false
        ));
    }

    /**
     * Deleting an item deletes the unpacked packages of its versions, and only those.
     */
    public function test_delete_item_deletes_packages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$item, $version] = $this->create_item_with_version();
        [$other, $otherversion] = $this->create_item_with_version();
        $this->add_package_file((int) $item->contextid, (int) $version->id, '/', 'index.html');
        $this->add_package_file((int) $item->contextid, (int) $version->id, '/assets/', 'app.js');
        $this->add_package_file((int) $other->contextid, (int) $otherversion->id, '/', 'index.html');

        item_manager::delete((int) $item->id);

        $this->assertSame(0, $this->count_package_files((int) $item->contextid, (int) $version->id));
        $this->assertSame(1, $this->count_package_files((int) $other->contextid, (int) $otherversion->id));
    }

    /**
     * Moving an item to another context moves its unpacked packages along.
     */
    public function test_context_change_moves_packages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $categorycontextid = (int) \context_coursecat::instance($category->id)->id;
        [$item, $version] = $this->create_item_with_version();
        $systemcontextid = (int) $item->contextid;
        $this->add_package_file($systemcontextid, (int) $version->id, '/', 'index.html');
        $this->add_package_file($systemcontextid, (int) $version->id, '/assets/', 'app.js');

        item_manager::update((int) $item->id, (object) ['contextid' => $categorycontextid]);

        $this->assertSame(0, $this->count_package_files($systemcontextid, (int) $version->id));
        $this->assertSame(2, $this->count_package_files($categorycontextid, (int) $version->id));
        $file = get_file_storage()->get_file(
            $categorycontextid,
            'local_nextpool',
            version_manager::FILEAREA_XAPIPACKAGE,
            (int) $version->id,
            '/assets/',
            'app.js'
        );
        $this->assertNotFalse($file);

        item_manager::delete((int) $item->id);
        $this->assertSame(0, $this->count_package_files($categorycontextid, (int) $version->id));
    }

    /**
     * The catalogue's file callback never serves unpacked packages, not even to an administrator.
     */
    public function test_pluginfile_refuses_packages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $categorycontext = \context_coursecat::instance($category->id);
        [$item, $version] = $this->create_item_with_version();
        [$incategory, $categoryversion] = $this->create_item_with_version(['contextid' => $categorycontext->id]);
        $this->add_package_file((int) $item->contextid, (int) $version->id, '/', 'index.html');
        $this->add_package_file((int) $categorycontext->id, (int) $categoryversion->id, '/', 'index.html');

        $site = get_site();
        $served = local_nextpool_pluginfile(
            $site,
            null,
            \context_system::instance(),
            version_manager::FILEAREA_XAPIPACKAGE,
            [(int) $version->id, 'index.html'],
            false
        );
        $this->assertFalse($served);
        $served = local_nextpool_pluginfile(
            $site,
            null,
            $categorycontext,
            version_manager::FILEAREA_XAPIPACKAGE,
            [(int) $categoryversion->id, 'index.html'],
            false
        );
        $this->assertFalse($served);
        $this->assertNotEmpty($incategory->id);
    }
}
