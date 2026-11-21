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

use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\base;
use local_nextpool\local\source\manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Test data generator for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_nextpool_generator extends component_generator_base {
    /** @var int Counter for unique names. */
    protected int $count = 0;

    #[\Override]
    public function reset() {
        $this->count = 0;
        parent::reset();
    }

    /**
     * Create a storage backend instance (default: Moodle file storage).
     *
     * @param string $plugin Backend plugin.
     * @param array $config Configuration including 'basefolder'.
     * @param string|null $name Display name.
     * @return base
     */
    public function create_source(string $plugin = 'moodle', array $config = [], ?string $name = null): base {
        $this->count++;
        return manager::create($plugin, $name ?? 'Source ' . $this->count, $config);
    }

    /**
     * Put a file with the given content into a backend.
     *
     * @param base $source Backend instance.
     * @param string $path Path relative to its base folder.
     * @param string $content File content.
     */
    public function put_file(base $source, string $path, string $content): void {
        $tempfile = make_request_directory() . '/upload';
        file_put_contents($tempfile, $content);
        $source->write($path, $tempfile);
    }

    /**
     * Create a catalogue item.
     *
     * @param array $record Item fields; sourceid is created if missing.
     * @param array|null $authors Authors, default one free-text author.
     * @return stdClass
     */
    public function create_item(array $record = [], ?array $authors = null): stdClass {
        $this->count++;
        if (empty($record['sourceid'])) {
            $record['sourceid'] = $this->create_source()->get_id();
        }
        $record += [
            'title' => 'Item ' . $this->count,
            'type' => 'file',
            'license' => 'cc-4.0',
        ];
        return item_manager::create((object) $record, $authors ?? [['name' => 'Author ' . $this->count]]);
    }

    /**
     * Create a published item with one published version from a file in a Moodle file backend.
     *
     * @param array $record Item fields; sourceid is created if missing.
     * @param string $content Content of version 1.
     * @param string $path Path of the file in the backend.
     * @param bool $h5plibraries Publish with the option to update H5P libraries (q39).
     * @return stdClass The item.
     */
    public function create_published_item(
        array $record = [],
        string $content = 'version 1',
        string $path = 'handout.pdf',
        bool $h5plibraries = false
    ): stdClass {
        if (empty($record['sourceid'])) {
            $record['sourceid'] = $this->create_source()->get_id();
        }
        $this->put_file(manager::get((int) $record['sourceid']), $path, $content);
        $item = $this->create_item($record);
        $this->publish_new_version((int) $item->id, $content, $path, $h5plibraries);
        item_manager::set_status((int) $item->id, status::REVIEW);
        return item_manager::set_status((int) $item->id, status::PUBLISHED);
    }

    /**
     * Write new content to an item's backend file, import it and publish the version.
     *
     * @param int $itemid Item id.
     * @param string $content New content.
     * @param string $path Path of the file in the backend.
     * @param bool $h5plibraries Publish with the option to update H5P libraries (q39).
     * @return stdClass The published version.
     */
    public function publish_new_version(
        int $itemid,
        string $content,
        string $path = 'handout.pdf',
        bool $h5plibraries = false
    ): stdClass {
        $item = item_manager::get($itemid);
        $this->put_file(manager::get((int) $item->sourceid), $path, $content);
        $version = version_manager::create_from_source($itemid, $path);
        version_manager::set_status((int) $version->id, status::REVIEW);
        return version_manager::set_status((int) $version->id, status::PUBLISHED, 0, $h5plibraries);
    }

    /**
     * Give an item competencies (F14).
     *
     * @param int $itemid Item id.
     * @param array $entries Entries in nugget format: competency (idnumber), level, function, objectives.
     * @return stdClass[] The stored competencies.
     */
    public function create_item_competencies(int $itemid, array $entries): array {
        return item_competencies::set($itemid, $entries);
    }
}
