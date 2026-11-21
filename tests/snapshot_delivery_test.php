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

use local_nextpool\local\snapshot_sender;
use local_nextpool\local\source\manager;
use local_nextpool\local\version_manager;

/**
 * Tests for the delivery of version snapshots to the editorial team (bug38).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('local_nextpool_pluginfile')]
#[\PHPUnit\Framework\Attributes\CoversClass(snapshot_sender::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
final class snapshot_delivery_test extends \advanced_testcase {
    /**
     * A snapshot gets its type from the file name, whatever the storage location claims, and is only ever sent
     * through the sender that forces a download; users without editorial rights get nothing.
     */
    public function test_snapshot_is_a_download(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $source = $generator->create_source();
        $generator->put_file($source, 'foo.pdf', '<html><script>alert(1)</script></html>');
        // The storage location claims HTML for a file named .pdf.
        $DB->set_field('files', 'mimetype', 'text/html', ['component' => 'nextpoolsource_moodle', 'filename' => 'foo.pdf']);
        $this->assertSame('text/html', manager::get($source->get_id())->stat('foo.pdf')->mimetype);
        $item = $generator->create_item(['sourceid' => $source->get_id()]);
        $version = version_manager::create_from_source((int) $item->id, 'foo.pdf');
        $this->assertSame('application/pdf', $version->mimetype);
        $this->assertSame('application/pdf', version_manager::get_file((int) $version->id)->get_mimetype());

        $sender = new class extends snapshot_sender {
            /** @var \stored_file[] Files that would have been sent. */
            public array $sent = [];

            #[\Override]
            public function send(\stored_file $file, array $options): void {
                $this->sent[] = $file;
            }
        };
        \core\di::set(snapshot_sender::class, $sender);
        $args = [(int) $version->id, 'foo.pdf'];
        local_nextpool_pluginfile(get_site(), null, \context_system::instance(), version_manager::FILEAREA, $args, false);
        $this->assertCount(1, $sender->sent, 'Sent through the download-only sender, although the URL asked for inline.');
        $this->assertSame('foo.pdf', $sender->sent[0]->get_filename());

        $this->setUser($this->getDataGenerator()->create_user());
        $served = local_nextpool_pluginfile(get_site(), null, \context_system::instance(), version_manager::FILEAREA, $args, false);
        $this->assertFalse($served);
        $this->assertCount(1, $sender->sent);
    }
}
