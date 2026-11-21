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

use local_nextpool\event\item_deleted;
use local_nextpool\event\version_deleted;
use local_nextpool\event\version_withdrawn;
use local_nextpool\event\version_cancelled;
use local_nextpool\local\comment_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\proposal_manager;
use local_nextpool\local\source\manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Lifecycle events and useful navigation from catalogue logs (bug170).
 *
 * @package local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_deleted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_deleted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_withdrawn::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_cancelled::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
final class lifecycle_events_test extends \advanced_testcase {
    /** @var \local_nextpool_generator Catalogue generator. */
    private \local_nextpool_generator $generator;
    /** @var \core\event\base[] Events delivered to the real external observer. */
    private static array $observed = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        self::$observed = [];
    }

    /**
     * Create a real draft snapshot using the storage API.
     *
     * @param \stdClass $item Item.
     * @param string $content Content, changed for each new version.
     * @param array $options Existing import options.
     * @return \stdClass Version.
     */
    private function version(\stdClass $item, string $content = 'Version one', array $options = []): \stdClass {
        $source = manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'handout.txt', $content);
        return version_manager::create_from_source((int) $item->id, 'handout.txt', $options);
    }

    /**
     * Deletion preserves a snapshot and links to the list rather than a deleted item.
     */
    public function test_item_deleted(): void {
        global $DB, $USER;
        $context = \context_coursecat::instance($this->getDataGenerator()->create_category()->id);
        $item = $this->generator->create_item(['contextid' => $context->id]);
        $version = $this->version($item);
        $sink = $this->redirectEvents();
        item_manager::delete((int) $item->id);
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof item_deleted));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame((int) $item->id, (int) $event->objectid);
        $this->assertSame((int) $USER->id, (int) $event->userid);
        $this->assertSame((int) $context->id, (int) $event->contextid);
        $this->assertSame('d', $event->crud);
        $this->assertSame($item->uuid, $event->other['uuid']);
        $this->assertSame($item->uuid, $event->get_record_snapshot(item_manager::TABLE, $item->id)->uuid);
        $this->assertEquals(new \moodle_url('/local/nextpool/items.php'), $event->get_url());
        $this->assertFalse($DB->record_exists(item_manager::TABLE, ['id' => $item->id]));
        $this->assertFalse($DB->record_exists(version_manager::TABLE, ['id' => $version->id]));
    }

    /**
     * An explicitly deleted version has its own event and a surviving parent link.
     */
    public function test_version_deleted(): void {
        global $DB;
        $item = $this->generator->create_item();
        $version = $this->version($item);
        $sink = $this->redirectEvents();
        version_manager::delete_unpublished((int) $version->id);
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof version_deleted));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('d', $event->crud);
        $this->assertEquals($version->id, $event->objectid);
        $this->assertEquals($item->id, $event->other['itemid']);
        $this->assertSame($item->uuid, $event->other['uuid']);
        $this->assertEquals($version->versionnr, $event->other['versionnr']);
        $this->assertSame(status::DRAFT, $event->get_record_snapshot(version_manager::TABLE, $version->id)->status);
        $this->assertEquals(new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]), $event->get_url());
        $this->assertFalse($DB->record_exists(version_manager::TABLE, ['id' => $version->id]));
        $this->assertTrue($DB->record_exists(item_manager::TABLE, ['id' => $item->id]));
    }

    /**
     * Withdrawal and cancellation keep their explicit actor, including an unnumbered proposal.
     */
    public function test_withdrawal_and_cancellation(): void {
        $item = $this->generator->create_published_item([], 'Version one', 'handout.txt');
        $version = $this->generator->publish_new_version((int) $item->id, 'Version two', 'handout.txt');
        $actor = $this->getDataGenerator()->create_user();
        $proposal = $this->version($item, 'Proposed version', [
            'origin' => version_manager::ORIGIN_PROPOSAL, 'createdby' => $actor->id,
        ]);
        $sink = $this->redirectEvents();
        version_manager::set_status((int) $version->id, status::WITHDRAWN, (int) $actor->id);
        proposal_manager::cancel((int) $proposal->id, (int) $actor->id);
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof version_withdrawn || $e instanceof version_cancelled
        ));
        $this->assertCount(2, $events);
        $this->assertInstanceOf(version_withdrawn::class, $events[0]);
        $this->assertInstanceOf(version_cancelled::class, $events[1]);
        foreach ($events as $event) {
            $this->assertEquals($actor->id, $event->userid);
            $this->assertEquals($item->id, $event->other['itemid']);
            $this->assertSame('u', $event->crud);
            $this->assertEquals(
                new \moodle_url('/local/nextpool/review.php', ['versionid' => $event->objectid]),
                $event->get_url()
            );
            $this->assertNotEmpty($event->get_name());
            $this->assertStringContainsString((string) $actor->id, $event->get_description());
            $this->assertSame(\core\event\base::NOT_MAPPED, $event::get_objectid_mapping());
            $this->assertFalse($event::get_other_mapping());
        }
        $this->assertSame(
            status::WITHDRAWN,
            $events[0]->get_record_snapshot(version_manager::TABLE, $version->id)->status
        );
        $this->assertNull($events[1]->other['versionnr']);
        $this->assertSame(
            status::CANCELLED,
            $events[1]->get_record_snapshot(version_manager::TABLE, $proposal->id)->status
        );
    }

    /**
     * Failed workflow operations do not falsely announce a completed action.
     */
    public function test_rejected_operations_emit_no_lifecycle_events(): void {
        $item = $this->generator->create_published_item([], 'Version one', 'handout.txt');
        $published = version_manager::get((int) $item->currentversionid);
        $draft = $this->version($item, 'Draft two');
        $sink = $this->redirectEvents();
        $operations = [
            static fn() => item_manager::delete((int) $item->id),
            static fn() => version_manager::delete_unpublished((int) $published->id),
            static fn() => version_manager::set_status((int) $published->id, status::WITHDRAWN),
            static fn() => version_manager::set_status((int) $draft->id, status::CANCELLED),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Expected a rejected lifecycle operation');
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
        $this->assertSame([], $sink->get_events());
        $this->assertSame(status::PUBLISHED, version_manager::get((int) $published->id)->status);
        $this->assertSame(status::DRAFT, version_manager::get((int) $draft->id)->status);
    }

    /**
     * All pre-existing item, version and comment events provide navigation without extra database reads.
     */
    public function test_existing_event_urls(): void {
        $item = $this->generator->create_item();
        $version = $this->version($item);
        $comment = comment_manager::add((int) $item->id, 'A comment', FORMAT_PLAIN);
        foreach (['item_created', 'item_updated'] as $name) {
            $class = 'local_nextpool\\event\\' . $name;
            $this->assertEquals(
                new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]),
                $class::create_from_item($item)->get_url()
            );
        }
        foreach (['version_created', 'version_submitted', 'version_returned', 'version_published', 'version_rejected'] as $name) {
            $class = 'local_nextpool\\event\\' . $name;
            $this->assertEquals(
                new \moodle_url('/local/nextpool/review.php', ['versionid' => $version->id]),
                $class::create_from_version($version, $item)->get_url()
            );
        }
        foreach (['comment_created', 'comment_deleted'] as $name) {
            $class = 'local_nextpool\\event\\' . $name;
            $expected = new \moodle_url('/local/nextpool/feedback.php', ['itemid' => $item->id]);
            $expected->set_anchor('local-nextpool-comment-' . $comment->id);
            $this->assertEquals($expected, $class::create_from_comment($comment, $item)->get_url());
        }
    }

    /**
     * Core's real external event buffer waits for the caller's transaction and discards rolled-back actions.
     */
    public function test_external_observer_and_rollback(): void {
        global $DB;
        $this->preventResetByRollback();
        $item = $this->generator->create_item();
        $rollbackitem = $this->generator->create_item();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => item_deleted::class,
            'callback' => self::class . '::observe_deleted', 'internal' => false,
        ]]);
        $transaction = $DB->start_delegated_transaction();
        item_manager::delete((int) $item->id);
        $this->assertSame([], self::$observed);
        $transaction->allow_commit();
        $this->assertCount(1, self::$observed);
        $transaction = $DB->start_delegated_transaction();
        item_manager::delete((int) $rollbackitem->id);
        $this->assertCount(1, self::$observed);
        try {
            $transaction->rollback(new \RuntimeException('Expected rollback'));
        } catch (\RuntimeException $e) {
            $this->assertSame('Expected rollback', $e->getMessage());
        }
        $this->assertCount(1, self::$observed);
        $this->assertTrue($DB->record_exists(item_manager::TABLE, ['id' => $rollbackitem->id]));
    }

    /**
     * Actual external observer used by the transaction test.
     *
     * @param \core\event\base $event Event.
     */
    public static function observe_deleted(\core\event\base $event): void {
        self::$observed[] = $event;
    }
}
