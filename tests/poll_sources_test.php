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

use local_nextpool\local\source\manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\task\poll_sources;

/**
 * Tests for the storage location check that imports changed files as drafts (F06 polling).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(poll_sources::class)]
final class poll_sources_test extends \advanced_testcase {
    /**
     * Run the task and return its output.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new poll_sources())->execute();
        return (string) ob_get_clean();
    }

    /**
     * A changed file of a published item becomes one draft version; nothing else is imported.
     */
    public function test_poll(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item([], 'version 1', 'handout.pdf');
        $draftitem = $generator->create_item();
        $v1 = version_manager::get_latest((int) $item->id);

        $this->run_task();
        $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]), 'Unchanged file.');

        $generator->put_file(manager::get((int) $item->sourceid), 'handout.pdf', 'version 2');
        $output = $this->run_task();
        $this->assertStringContainsString('imported 1 draft', $output);
        $v2 = version_manager::get_latest((int) $item->id);
        $this->assertSame(2, (int) $v2->versionnr);
        // The draft from the storage location check is labelled like an import (task103).
        $this->assertSame(version_manager::build_label($v2->filename, (int) $v2->timecreated), $v2->label);
        $this->assertSame(status::DRAFT, $v2->status);
        $this->assertSame(0, (int) $v2->createdby, 'Created by the check, not by a person.');
        $this->assertEquals($v1->id, $v2->basedonversionid);

        // An open draft blocks further imports until it is published or discarded.
        $generator->put_file(manager::get((int) $item->sourceid), 'handout.pdf', 'version 3');
        $this->run_task();
        $this->assertSame(2, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
        $this->assertSame(0, $DB->count_records(version_manager::TABLE, ['itemid' => $draftitem->id]));
    }

    /**
     * A task whose storage locations can be replaced, counting how often each is loaded.
     *
     * @param array $replacements Storage location id => instance of \local_nextpool\local\source\base to use.
     * @return poll_sources
     */
    private function make_task(array $replacements = []): poll_sources {
        return new class ($replacements) extends poll_sources {
            /** @var array<int,int> Storage location id => number of loads. */
            public array $loads = [];

            /**
             * Constructor.
             *
             * @param array $replacements Storage location id => instance.
             */
            public function __construct(
                /** @var array Storage location id => instance. */
                private array $replacements
            ) {
            }

            #[\Override]
            protected function load_source(int $sourceid): \local_nextpool\local\source\base {
                $this->loads[$sourceid] = ($this->loads[$sourceid] ?? 0) + 1;
                return $this->replacements[$sourceid] ?? parent::load_source($sourceid);
            }
        };
    }

    /**
     * A Moodle file storage location that reports a fixed revision and counts downloads, or fails.
     *
     * @param int $sourceid Storage location id.
     * @param string $revision Revision to report, e.g. a new ETag.
     * @param \Throwable|null $failure Thrown by stat() instead.
     * @return \nextpoolsource_moodle\source
     */
    private function make_source(int $sourceid, string $revision, ?\Throwable $failure = null): \nextpoolsource_moodle\source {
        global $DB;
        $record = $DB->get_record(manager::TABLE, ['id' => $sourceid], '*', MUST_EXIST);
        $config = manager::get_stored_config($sourceid);
        return new class ($record, $config, $revision, $failure) extends \nextpoolsource_moodle\source {
            /** @var int Number of read() calls. */
            public int $reads = 0;
            /** @var int Number of stat() calls. */
            public int $stats = 0;

            /**
             * Constructor.
             *
             * @param \stdClass $record Record.
             * @param array $config Configuration.
             * @param string $revision Revision to report.
             * @param \Throwable|null $failure Thrown by stat().
             */
            public function __construct(
                \stdClass $record,
                array $config,
                /** @var string Revision to report. */
                private string $revision,
                /** @var \Throwable|null Thrown by stat(). */
                private ?\Throwable $failure
            ) {
                parent::__construct($record, $config);
            }

            #[\Override]
            public function stat(string $path): ?\local_nextpool\local\source\entry {
                $this->stats++;
                if ($this->failure) {
                    throw $this->failure;
                }
                $entry = parent::stat($path);
                return $entry ? new \local_nextpool\local\source\entry(
                    path: $entry->path,
                    size: $entry->size,
                    mimetype: $entry->mimetype,
                    revision: $this->revision,
                    mtime: $entry->mtime,
                ) : null;
            }

            #[\Override]
            public function read(string $path, string $targetfile): void {
                $this->reads++;
                parent::read($path, $targetfile);
            }
        };
    }

    /**
     * A new revision with the same content is recorded, so the file is downloaded once and not every hour (bug36).
     */
    public function test_new_revision_same_content(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item();
        $source = $this->make_source((int) $item->sourceid, 'etag-2');

        $output = $this->run_with($this->make_task([(int) $item->sourceid => $source]));
        $this->assertStringContainsString(get_string('error_unchanged', 'local_nextpool'), $output);
        $this->assertSame(1, $source->reads);
        $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
        $this->assertSame('etag-2', version_manager::get_latest((int) $item->id)->sourcerevision);

        $this->run_with($this->make_task([(int) $item->sourceid => $source]));
        $this->assertSame(1, $source->reads, 'The recorded revision is unchanged, nothing is downloaded.');
    }

    /**
     * Any failure of one storage location is logged and the others are still checked (bug60);
     * each storage location is loaded once per run (bug56).
     */
    public function test_failures_and_loading(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $broken = $generator->create_published_item();
        $good = $generator->create_published_item();
        $second = $generator->create_published_item(['sourceid' => $good->sourceid], 'other', 'other.pdf');
        $generator->put_file(manager::get((int) $good->sourceid), 'handout.pdf', 'version 2');

        foreach ([new \TypeError('Broken backend'), new \JsonException('Broken answer')] as $failure) {
            $task = $this->make_task([(int) $broken->sourceid => $this->make_source((int) $broken->sourceid, '', $failure)]);
            $output = $this->run_with($task);
            $this->assertStringContainsString("Item {$broken->id}: " . $failure->getMessage(), $output);
            $this->assertSame([(int) $broken->sourceid => 1, (int) $good->sourceid => 1], $task->loads);
        }
        $this->assertSame(2, $DB->count_records(version_manager::TABLE, ['itemid' => $good->id]), 'Imported once.');
        $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $second->id]));
    }

    /**
     * Disabled storage locations and items with a version in review are left alone (Q-19).
     */
    public function test_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $disabled = $generator->create_published_item();
        $generator->put_file(manager::get((int) $disabled->sourceid), 'handout.pdf', 'version 2');
        manager::set_enabled((int) $disabled->sourceid, false);
        $output = $this->run_task();
        $this->assertStringContainsString('No enabled storage location.', $output);

        $inreview = $generator->create_published_item();
        $generator->put_file(manager::get((int) $inreview->sourceid), 'handout.pdf', 'version 2');
        $review = version_manager::create_from_source((int) $inreview->id, 'handout.pdf');
        version_manager::set_status((int) $review->id, status::REVIEW);
        $generator->put_file(manager::get((int) $inreview->sourceid), 'handout.pdf', 'version 3');

        $output = $this->run_task();
        $this->assertStringContainsString('imported 0 draft', $output);
        $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $disabled->id]));
        $this->assertSame(2, $DB->count_records(version_manager::TABLE, ['itemid' => $inreview->id]));
    }

    /**
     * Run a task instance and return its output.
     *
     * @param poll_sources $task Task.
     * @return string
     */
    private function run_with(poll_sources $task): string {
        ob_start();
        $task->execute();
        return (string) ob_get_clean();
    }

    /**
     * A storage location that fails as a whole is asked once per run, not once per item; its other items wait for
     * the next run, other storage locations are checked (bug163).
     */
    public function test_failed_storage_location_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $first = $generator->create_published_item();
        $second = $generator->create_published_item(['sourceid' => $first->sourceid], 'other', 'other.pdf');
        $good = $generator->create_published_item();
        $generator->put_file(manager::get((int) $good->sourceid), 'handout.pdf', 'version 2');
        $broken = $this->make_source((int) $first->sourceid, '', new source_exception('error_sourceunavailable'));

        $output = $this->run_with($this->make_task([(int) $first->sourceid => $broken]));

        $this->assertSame(1, $broken->stats, 'The unreachable storage location is asked once.');
        $this->assertStringContainsString("Item {$first->id}: ", $output);
        $this->assertStringNotContainsString("Item {$second->id}: ", $output);
        $this->assertStringContainsString('Skipped 1 item(s)', $output);
        $this->assertSame(2, $DB->count_records(version_manager::TABLE, ['itemid' => $good->id]), 'The others are checked.');

        // A failure of one file does not stop the other files of the storage location.
        $notfound = $this->make_source((int) $first->sourceid, '', new source_exception('error_invalidpath'));
        $this->run_with($this->make_task([(int) $first->sourceid => $notfound]));
        $this->assertSame(2, $notfound->stats);
    }

    /**
     * Invalid content is downloaded once per revision; manual imports and changed revisions retry (bug163).
     */
    public function test_invalid_revision_is_not_downloaded_repeatedly(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item(['type' => 'url'], 'https://example.com/old', 'link.txt');
        $generator->put_file(manager::get((int) $item->sourceid), 'link.txt', 'Invalid URL content');
        $source = $this->make_source((int) $item->sourceid, 'invalid-revision');
        $replacement = [(int) $item->sourceid => $source];

        $this->run_with($this->make_task($replacement));
        $this->assertSame(1, $source->reads);
        $output = $this->run_with($this->make_task($replacement));
        $this->assertSame(1, $source->reads, 'An unchanged invalid revision is not downloaded twice.');
        $this->assertStringContainsString('unchanged rejected source revision', $output);
        $this->assertSame(1, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));

        // Manual import never consults the polling cache, even with the identical revision.
        try {
            version_manager::create_from_source((int) $item->id, 'link.txt', [], $source);
            $this->fail('Invalid content must still be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalidurlfile', $e->errorcode);
        }
        $this->assertSame(2, $source->reads);

        $changed = $this->make_source((int) $item->sourceid, 'fixed-revision');
        $generator->put_file(manager::get((int) $item->sourceid), 'link.txt', 'https://example.com/fixed');
        $this->run_with($this->make_task([(int) $item->sourceid => $changed]));
        $this->assertSame(1, $changed->reads);
        $this->assertSame(2, $DB->count_records(version_manager::TABLE, ['itemid' => $item->id]));
    }

    /**
     * Cache purging retries a rejected revision; revisions without a change marker are never cached.
     */
    public function test_invalid_revision_cache_can_be_retried(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $item = $generator->create_published_item(['type' => 'url'], 'https://example.com/old', 'link.txt');
        $latest = version_manager::get_latest((int) $item->id);
        $entry = new \local_nextpool\local\source\entry('link.txt', revision: 'bad');
        \local_nextpool\local\rejected_source_revision::remember($item, $latest, $entry);
        $this->assertTrue(\local_nextpool\local\rejected_source_revision::contains($item, $latest, $entry));
        \cache::make('local_nextpool', 'rejectedrevision')->purge();
        $this->assertFalse(\local_nextpool\local\rejected_source_revision::contains($item, $latest, $entry));
        $unmarked = new \local_nextpool\local\source\entry('link.txt');
        \local_nextpool\local\rejected_source_revision::remember($item, $latest, $unmarked);
        $this->assertFalse(\local_nextpool\local\rejected_source_revision::contains($item, $latest, $unmarked));
    }

    /**
     * HTTP clients of the backends get a total timeout and stop stalled transfers; core sets only a connect
     * timeout (bug163).
     */
    public function test_http_clients_have_time_limits(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = \local_nextpool\local\source\base::limit_transfer(new \curl());
        $options = (new \ReflectionProperty(\curl::class, 'options'))->getValue($curl);
        $this->assertSame(\local_nextpool\local\source\base::REQUEST_TIMEOUT, $options['CURLOPT_TIMEOUT']);
        $this->assertSame(\local_nextpool\local\source\base::LOW_SPEED_LIMIT, $options['CURLOPT_LOW_SPEED_LIMIT']);
        $this->assertSame(\local_nextpool\local\source\base::LOW_SPEED_TIME, $options['CURLOPT_LOW_SPEED_TIME']);
    }
}
