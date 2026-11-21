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

use local_nextpool\event\item_created;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Tests for catalogue items (F01).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(status::class)]
final class item_manager_test extends \advanced_testcase {
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
     * A new item is a draft with a UUID, the system context and normalised authors.
     */
    public function test_create(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();

        $item = $this->generator->create_item(
            ['title' => '  Fire safety basics ', 'language' => 'en', 'duration' => 600],
            [['userid' => $teacher->id, 'name' => 'ignored', 'role' => 'Editor'], ['name' => 'Jane Doe'], ['name' => '  ']]
        );

        $this->assertSame('Fire safety basics', $item->title);
        $this->assertSame(status::DRAFT, $item->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $item->uuid);
        $this->assertEquals(\context_system::instance()->id, $item->contextid);
        $this->assertNull($item->currentversionid);

        $authors = item_manager::get_authors($item->id);
        $this->assertCount(2, $authors);
        $this->assertEquals($teacher->id, $authors[0]->userid);
        $this->assertNull($authors[0]->name, 'Names of Moodle users are not copied.');
        $this->assertSame('Editor', $authors[0]->role);
        $this->assertSame('Jane Doe', $authors[1]->name);

        $events = array_filter($sink->get_events(), fn($e) => $e instanceof item_created);
        $this->assertCount(1, $events);
        $this->assertSame(item_manager::get_by_uuid($item->uuid)->id, $item->id);
    }

    /**
     * Author names for display and file attribution: full names of users, free-text names, unescaped;
     * the same for mod_nextpool and the content types (bug63, Q-13).
     */
    public function test_get_author_names(): void {
        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $item = $this->generator->create_item([], [
            ['userid' => $teacher->id, 'role' => 'Editor'],
            ['name' => 'Jane <Doe>'],
        ]);

        $this->assertSame([
            ['name' => 'Ada Lovelace', 'role' => 'Editor'],
            ['name' => 'Jane <Doe>', 'role' => null],
        ], item_manager::get_author_names((int) $item->id));
        $this->assertSame([], item_manager::get_author_names(-1));
    }

    /**
     * Invalid metadata is rejected.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'empty title' => [['title' => ' ']],
            'unknown type' => [['type' => 'video']],
            'unknown licence' => [['license' => 'made-up']],
            'unknown language' => [['language' => 'xx_YY']],
            'negative duration' => [['duration' => -1]],
            'unknown category' => [['categoryid' => 999999]],
            'unknown source' => [['sourceid' => 999999]],
        ];
    }

    /**
     * Rejecting invalid metadata.
     *
     * @param array $record Fields overriding a valid item.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_create_rejects_invalid(array $record): void {
        $this->expectException(\moodle_exception::class);
        $this->generator->create_item($record);
    }

    /**
     * Items are only available site-wide or in a course category, never in a course.
     */
    public function test_context_must_be_system_or_category(): void {
        $category = $this->getDataGenerator()->create_category();
        $item = $this->generator->create_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $this->assertEquals(\context_coursecat::instance($category->id)->id, $item->contextid);

        $course = $this->getDataGenerator()->create_course();
        $this->expectException(\moodle_exception::class);
        $this->generator->create_item(['contextid' => \context_course::instance($course->id)->id]);
    }

    /**
     * At least one author is required.
     */
    public function test_requires_author(): void {
        $this->expectException(\moodle_exception::class);
        $this->generator->create_item([], [['name' => '']]);
    }

    /**
     * Updating metadata and authors; type and backend are locked once versions exist.
     */
    public function test_update_and_lock(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'a.pdf', 'v1');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        $item = item_manager::update($item->id, (object) ['title' => 'New title', 'type' => 'page'], [['name' => 'Other']]);
        $this->assertSame('New title', $item->title);
        $this->assertSame('page', $item->type);
        $this->assertSame('Other', item_manager::get_authors($item->id)[0]->name);

        version_manager::create_from_source($item->id, 'a.pdf');
        $this->expectException(\moodle_exception::class);
        item_manager::update($item->id, (object) ['type' => 'file']);
    }

    /**
     * Status workflow: publishing needs a published version; invalid transitions fail.
     */
    public function test_status_workflow(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'a.pdf', 'v1');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);

        $item = item_manager::set_status($item->id, status::REVIEW);
        try {
            item_manager::set_status($item->id, status::PUBLISHED);
            $this->fail('Publishing without a published version must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_nopublishedversion', $e->errorcode);
        }

        $version = version_manager::create_from_source($item->id, 'a.pdf');
        version_manager::set_status($version->id, status::REVIEW);
        version_manager::set_status($version->id, status::PUBLISHED);
        $item = item_manager::set_status($item->id, status::PUBLISHED);
        $this->assertSame(status::PUBLISHED, $item->status);

        $this->expectException(\moodle_exception::class);
        item_manager::set_status($item->id, status::DRAFT);
    }

    /**
     * Only never-published items can be deleted, including versions and snapshots.
     */
    public function test_delete(): void {
        global $DB;
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'a.pdf', 'v1');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $version = version_manager::create_from_source($item->id, 'a.pdf');

        item_manager::delete($item->id);
        $this->assertFalse($DB->record_exists(item_manager::TABLE, ['id' => $item->id]));
        $this->assertFalse($DB->record_exists(item_manager::AUTHOR_TABLE, ['itemid' => $item->id]));
        $this->assertNull(version_manager::get_file($version->id));

        $published = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $v = version_manager::create_from_source($published->id, 'a.pdf');
        version_manager::set_status($v->id, status::REVIEW);
        version_manager::set_status($v->id, status::PUBLISHED);
        $this->expectException(\moodle_exception::class);
        item_manager::delete($published->id);
    }

    /**
     * Tags are stored in the catalogue collection, follow the item's context and go with the item (F10).
     */
    public function test_tags(): void {
        global $DB;
        $item = $this->generator->create_item();
        item_manager::set_tags((int) $item->id, ['Onboarding', 'Safety']);
        $this->assertEqualsCanonicalizing(['Onboarding', 'Safety'], item_manager::get_tags((int) $item->id));

        $collection = \core_tag_area::get_collection('local_nextpool', item_manager::TAG_ITEMTYPE);
        $this->assertNotEquals(\core_tag_collection::get_default(), $collection);
        $this->assertSame('nextpool', $DB->get_field('tag_coll', 'name', ['id' => $collection]));

        $category = $this->getDataGenerator()->create_category();
        $catcontext = \context_coursecat::instance($category->id);
        item_manager::update((int) $item->id, (object) ['contextid' => $catcontext->id]);
        $conditions = ['itemtype' => item_manager::TAG_ITEMTYPE, 'itemid' => $item->id];
        $instances = $DB->get_fieldset('tag_instance', 'contextid', $conditions);
        $this->assertSame([(string) $catcontext->id, (string) $catcontext->id], array_map('strval', $instances));

        item_manager::set_tags((int) $item->id, ['Safety']);
        $this->assertSame(['Safety'], item_manager::get_tags((int) $item->id));

        item_manager::delete((int) $item->id);
        $this->assertFalse($DB->record_exists('tag_instance', ['itemtype' => item_manager::TAG_ITEMTYPE, 'itemid' => $item->id]));
    }

    /**
     * The editorial list counts the versions waiting for review exactly like the review queue: versions in review
     * and drafts from the storage location, not manual drafts or decided versions.
     */
    public function test_search_counts_versions_waiting_for_review(): void {
        $this->setAdminUser();
        $item = $this->generator->create_published_item(['title' => 'Waiting item']);
        $source = source_manager::get((int) $item->sourceid);
        $contextids = [(int) \context_system::instance()->id];
        $count = fn(): int => (int) item_manager::search($contextids, ['search' => 'Waiting item'])[$item->id]->reviewcount;
        $this->assertSame(0, $count());

        // A manual draft does not wait for review yet.
        $this->generator->put_file($source, 'handout.pdf', 'manual draft');
        $manual = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $this->assertSame(0, $count());

        // Submitted for review.
        version_manager::set_status((int) $manual->id, status::REVIEW);
        $this->assertSame(1, $count());

        // A draft from the storage location check waits for review as well (q73).
        $this->generator->put_file($source, 'handout.pdf', 'polled change');
        $polled = version_manager::create_from_source((int) $item->id, 'handout.pdf', ['createdby' => 0]);
        $this->assertSame(2, $count());

        $html = status::review_pending_badge(2);
        $this->assertStringContainsString(get_string('reviewpending_many', 'local_nextpool', 2), $html);
        $this->assertStringContainsString('local-nextpool-statuspill', $html);
        $this->assertStringContainsString(get_string('reviewpending_one', 'local_nextpool'), status::review_pending_badge(1));

        // Decided versions no longer wait for review, regardless of their origin.
        version_manager::set_status((int) $manual->id, status::REJECTED, reason: 'Not suitable for publication.');
        $this->assertSame(1, $count());
        version_manager::set_status((int) $polled->id, status::REVIEW);
        version_manager::set_status((int) $polled->id, status::PUBLISHED);
        $this->assertSame(0, $count());
    }
}
