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

use local_nextpool\local\direct_upload;
use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\review_manager;
use local_nextpool\local\source\base as source_base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use nextpoolsource_nextcloud\local\webdav_client;
use nextpoolsource_onedrive\fake_graph_client;
use nextpoolsource_onedrive\testable_source;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../source/nextcloud/tests/fixtures/fake_webdav_client.php');
require_once(__DIR__ . '/../source/onedrive/tests/fixtures/fake_graph_client.php');
require_once(__DIR__ . '/../source/onedrive/tests/fixtures/testable_source.php');

/**
 * Review decisions and publishing into the storage location (F07, task80, test61, test62).
 *
 * Runs against the Moodle file storage and against fakes of Nextcloud (WebDAV) and OneDrive (Graph); no real system
 * is contacted.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(review_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nextpool\output\review_page::class)]
final class review_manager_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;
    /** @var \nextpoolsource_nextcloud\fake_webdav_client|null Fake Nextcloud of the current test. */
    private $webdav = null;
    /** @var fake_graph_client|null Fake Graph of the current test. */
    private ?fake_graph_client $graph = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    #[\Override]
    protected function tearDown(): void {
        testable_source::$fake = null;
        parent::tearDown();
    }

    /**
     * Storage backends under test.
     *
     * @return array
     */
    public static function backend_provider(): array {
        return ['Moodle file storage' => ['moodle'], 'Nextcloud fake' => ['nextcloud'], 'OneDrive fake' => ['onedrive']];
    }

    /**
     * A storage location of a backend; the fakes are wired to fresh fake servers.
     *
     * @param string $backend moodle, nextcloud or onedrive.
     * @return source_base
     */
    private function make_source(string $backend): source_base {
        if ($backend === 'moodle') {
            return $this->generator->create_source();
        }
        if ($backend === 'nextcloud') {
            $this->webdav = new \nextpoolsource_nextcloud\fake_webdav_client();
            $record = (object) ['id' => 900, 'plugin' => 'nextcloud', 'name' => 'Cloud', 'enabled' => 1];
            return new class ($record, ['basefolder' => 'Catalogue'], $this->webdav) extends \nextpoolsource_nextcloud\source {
                /**
                 * Constructor.
                 *
                 * @param \stdClass $record Record.
                 * @param array $config Configuration.
                 * @param webdav_client $fake Client to return.
                 */
                public function __construct(
                    \stdClass $record,
                    array $config,
                    /** @var webdav_client Client to return. */
                    private webdav_client $fake,
                ) {
                    parent::__construct($record, $config);
                }

                #[\Override]
                protected function create_client(): webdav_client {
                    return $this->fake;
                }
            };
        }
        $this->graph = new fake_graph_client();
        testable_source::$fake = $this->graph;
        $record = (object) ['id' => 901, 'plugin' => 'onedrive', 'name' => 'Library', 'enabled' => 1];
        return new testable_source($record, [
            'tenantid' => 'contoso.onmicrosoft.com',
            'clientid' => '6bb5e8d9-efa9-455a-8548-3ac82d948080',
            'clientsecret' => 'secret',
            'driveid' => fake_graph_client::DRIVE,
            'basefolder' => 'Kurse/A',
        ]);
    }

    /**
     * Change a file in the storage location from outside Moodle.
     *
     * @param string $backend Backend.
     * @param source_base $source Storage location.
     * @param string $path Path relative to the base folder.
     * @param string $content New content.
     */
    private function change_outside(string $backend, source_base $source, string $path, string $content): void {
        match ($backend) {
            'moodle' => $this->generator->put_file($source, $path, $content),
            'nextcloud' => $this->webdav->add_file('Catalogue/' . $path, $content),
            'onedrive' => $this->graph->add_file('Kurse/A/' . $path, $content),
        };
    }

    /**
     * Read a file of the storage location.
     *
     * @param source_base $source Storage location.
     * @param string $path Path.
     * @return string Content.
     */
    private static function read(source_base $source, string $path): string {
        $target = make_request_directory() . '/read';
        $source->read($path, $target);
        return file_get_contents($target);
    }

    /**
     * A published item whose version 1 is the file handout.pdf in the given storage location.
     *
     * @param source_base $source Storage location (the item record points to a Moodle storage location for the fakes).
     * @param array $record Item fields.
     * @return \stdClass The item.
     */
    private function create_published_item(source_base $source, array $record = []): \stdClass {
        $record += ['sourceid' => $source instanceof \nextpoolsource_moodle\source ? $source->get_id() : 0];
        $item = $this->generator->create_item($record);
        $this->generator->put_file($source, 'handout.pdf', 'version 1');
        $version = version_manager::create_from_source((int) $item->id, 'handout.pdf', [], $source);
        version_manager::set_status((int) $version->id, status::REVIEW);
        version_manager::set_status((int) $version->id, status::PUBLISHED);
        item_manager::set_status((int) $item->id, status::REVIEW);
        return item_manager::set_status((int) $item->id, status::PUBLISHED);
    }

    /**
     * A proposal from a course, submitted for review.
     *
     * @param \stdClass $item Item record.
     * @param string $content Content.
     * @param int $userid Proposing user.
     * @param int $courseid Course of the proposal.
     * @return \stdClass The version.
     */
    private function propose(\stdClass $item, string $content, int $userid, int $courseid = 0): \stdClass {
        $courseid = $courseid ?: (int) $this->getDataGenerator()->create_course()->id;
        $file = make_request_directory() . '/proposal';
        file_put_contents($file, $content);
        $latest = version_manager::get_latest((int) $item->id);
        $version = version_manager::create_from_file((int) $item->id, $file, 'handout.pdf', new entry(''), [
            'origin' => version_manager::ORIGIN_PROPOSAL,
            'userid' => $userid,
            'courseid' => $courseid,
            'basedonversionid' => $latest ? (int) $latest->id : 0,
            'changelog' => 'Emergency numbers added.',
        ]);
        return version_manager::set_status((int) $version->id, status::REVIEW, $userid);
    }

    /**
     * F07.AC03, AC16: publishing a proposal writes it to the item's file, numbers it and the next check finds nothing.
     *
     * @param string $backend Backend.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('backend_provider')]
    public function test_publish_writes_into_storage(string $backend): void {
        $source = $this->make_source($backend);
        $item = $this->create_published_item($source);
        $teacher = $this->getDataGenerator()->create_user();
        $rejected = $this->propose($item, 'proposal A', (int) $teacher->id);
        $proposal = $this->propose($item, 'proposal B', (int) $teacher->id);
        review_manager::reject((int) $rejected->id, 'Duplicate.');

        $published = review_manager::publish((int) $proposal->id, ['changelog' => '<p>Checked</p>'], $source);

        $this->assertSame(status::PUBLISHED, $published->status);
        $this->assertSame(2, (int) $published->versionnr, 'The rejected proposal leaves no gap (AC16).');
        $this->assertSame('handout.pdf', $published->sourceref);
        $this->assertSame($source->stat('handout.pdf')->revision, $published->sourcerevision);
        $this->assertSame('<p>Checked</p>', $published->changelog);
        $this->assertSame('proposal B', self::read($source, 'handout.pdf'));
        $this->assertEquals($published->id, item_manager::get((int) $item->id)->currentversionid);
        $this->assertNull(version_manager::check_for_update((int) $item->id, $source), 'The next check imports nothing.');
        $this->assertNull(version_manager::get($rejected->id)->versionnr);
    }

    /**
     * F07.AC08, test62: an unreachable or read-only storage location stops publishing; nothing changes.
     *
     * @param string $backend Backend.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('backend_provider')]
    public function test_unreachable_storage(string $backend): void {
        $source = $this->make_source($backend);
        $item = $this->create_published_item($source);
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);

        match ($backend) {
            'moodle' => source_manager::set_enabled($source->get_id(), false),
            'nextcloud' => $this->webdav->forcestatus = 503,
            'onedrive' => $this->graph->force = ['/drives/' => ['status' => 503]],
        };
        try {
            review_manager::publish((int) $proposal->id, [], $backend === 'moodle' ? null : $source);
            $this->fail('Publishing must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_publishfailed', $e->errorcode);
            $this->assertNotEmpty($e->a, 'The message names the reason.');
        }
        $proposal = version_manager::get($proposal->id);
        $this->assertSame(status::REVIEW, $proposal->status);
        $this->assertNull($proposal->versionnr);
        $after = placement_manager::get((int) $placement->id);
        $this->assertEquals($placement->appliedversionid, $after->appliedversionid, 'No placement changes.');
        $this->assertNull($after->pendingversionid);
    }

    /**
     * F07.AC08: a storage location that cannot be written to stops publishing.
     */
    public function test_readonly_storage(): void {
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);
        $record = (object) ['id' => $source->get_id(), 'plugin' => 'moodle', 'name' => 'RO', 'enabled' => 1];
        $readonly = new class ($record, []) extends \nextpoolsource_moodle\source {
            #[\Override]
            public function supports_write(): bool {
                return false;
            }
        };
        try {
            review_manager::publish((int) $proposal->id, [], $readonly);
            $this->fail('Publishing must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_publishfailed', $e->errorcode);
            $this->assertSame(get_string('error_sourcereadonly', 'local_nextpool'), $e->a);
        }
        $this->assertSame(status::REVIEW, version_manager::get($proposal->id)->status);
    }

    /**
     * Q74, test62: a file changed in the storage location stops publishing; "Check now" turns it into a draft.
     *
     * @param string $backend Backend.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('backend_provider')]
    public function test_conflict_and_check_now(string $backend): void {
        $source = $this->make_source($backend);
        $item = $this->create_published_item($source);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);
        $this->change_outside($backend, $source, 'handout.pdf', 'edited in the cloud');

        try {
            review_manager::publish((int) $proposal->id, [], $source);
            $this->fail('Publishing must stop.');
        } catch (\moodle_exception $e) {
            $this->assertSame(review_manager::ERROR_SOURCECHANGED, $e->errorcode);
        }
        $this->assertSame(status::REVIEW, version_manager::get($proposal->id)->status);
        $this->assertSame('edited in the cloud', self::read($source, 'handout.pdf'), 'The change is not overwritten.');

        $draft = review_manager::sync_now((int) $item->id, $source);
        $this->assertSame(2, (int) $draft->versionnr);
        $this->assertSame(status::DRAFT, $draft->status);
        $this->assertSame(version_manager::ORIGIN_SOURCE, $draft->origin);
        $this->assertNull(review_manager::sync_now((int) $item->id, $source), 'Nothing new after the import.');
        $this->assertSame(status::REVIEW, version_manager::get($proposal->id)->status);

        // The reviewers have seen the change; publishing now replaces it.
        $published = review_manager::publish((int) $proposal->id, [], $source);
        $this->assertSame(3, (int) $published->versionnr);
        $this->assertSame('proposal', self::read($source, 'handout.pdf'));
        $this->assertNull(version_manager::check_for_update((int) $item->id, $source));
    }

    /**
     * Q75, test62: a proposed new item goes to the storage location for direct uploads, or where the reviewer chooses.
     */
    public function test_new_item_location(): void {
        $uploads = $this->generator->create_source('moodle', [], 'Uploads');
        $other = $this->generator->create_source('moodle', [], 'Other');
        direct_upload::set_sourceid($uploads->get_id());
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();

        $item = $this->generator->create_item(['sourceid' => $uploads->get_id()]);
        item_manager::set_status((int) $item->id, status::REVIEW);
        $this->assertTrue(review_manager::needs_location($item));
        $proposal = $this->propose($item, 'new item', (int) $teacher->id, (int) $course->id);
        $this->assertSame('vorschlaege/kurs-' . $course->id, review_manager::get_default_folder($proposal));

        $published = review_manager::publish((int) $proposal->id);
        $path = 'vorschlaege/kurs-' . $course->id . '/handout.pdf';
        $this->assertSame($path, $published->sourceref);
        $this->assertSame('new item', self::read(source_manager::get($uploads->get_id()), $path));
        $this->assertSame(1, (int) $published->versionnr);
        $item = item_manager::get((int) $item->id);
        $this->assertSame(status::PUBLISHED, $item->status, 'The item is published with its version (task104).');
        $this->assertFalse(review_manager::needs_location($item));

        // Chosen storage location and folder; an existing file is never replaced.
        $second = $this->generator->create_item(['sourceid' => $uploads->get_id()]);
        $proposal = $this->propose($second, 'second item', (int) $teacher->id, (int) $course->id);
        $this->generator->put_file($other, 'Neu/handout.pdf', 'somebody else');
        try {
            review_manager::publish((int) $proposal->id, ['sourceid' => $other->get_id(), 'folder' => 'Neu']);
            $this->fail('An existing file must not be replaced.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_publishfailed', $e->errorcode);
        }
        $published = review_manager::publish((int) $proposal->id, ['sourceid' => $other->get_id(), 'folder' => 'Neu/Kurs']);
        $this->assertSame('Neu/Kurs/handout.pdf', $published->sourceref);
        $this->assertEquals($other->get_id(), item_manager::get((int) $second->id)->sourceid);
        $this->assertSame('somebody else', self::read($other, 'Neu/handout.pdf'));
    }

    /**
     * Publishing a version publishes an item in draft or review with it; a withdrawn item stays withdrawn (task104).
     */
    public function test_item_status_follows(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'handout.pdf', 'draft');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $version = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        version_manager::set_status((int) $version->id, status::REVIEW);

        $published = review_manager::publish((int) $version->id);
        $this->assertSame(status::PUBLISHED, item_manager::get((int) $item->id)->status);
        $this->assertSame('draft', self::read($source, 'handout.pdf'), 'The file already was this version.');
        $this->assertSame($version->sourcerevision, $published->sourcerevision);

        $item = $this->create_published_item($source, ['title' => 'Withdrawn']);
        item_manager::set_status((int) $item->id, status::WITHDRAWN);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);
        review_manager::publish((int) $proposal->id, [], $source);
        $this->assertSame(status::WITHDRAWN, item_manager::get((int) $item->id)->status);
    }

    /**
     * F07.AC03: placements get the published proposal according to their policy.
     */
    public function test_placements_get_the_version(): void {
        // Placements get the version from an observer that runs after the commit (bug164).
        $this->preventResetByRollback();
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);
        $this->assertNull(placement_manager::get((int) $placement->id)->pendingversionid, 'A proposal reaches no course.');

        review_manager::publish((int) $proposal->id);
        $this->runAdhocTasks(\local_nextpool\task\distribute_version::class);
        $this->assertEquals($proposal->id, placement_manager::get((int) $placement->id)->pendingversionid);
    }

    /**
     * F07.AC17: reviewers may publish their own proposal; it is marked self-approved.
     */
    public function test_self_approval(): void {
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $reviewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:reviewversions', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $reviewer->id, \context_system::instance()->id);
        $proposal = $this->propose($item, 'own proposal', (int) $reviewer->id);

        $this->setUser($reviewer);
        $published = review_manager::publish((int) $proposal->id);
        $this->assertSame(1, (int) $published->selfapproved);
        $log = version_manager::get_log((int) $published->id);
        $this->assertSame(version_manager::LOG_PUBLISHED, end($log)->action);
        $this->assertEquals($reviewer->id, end($log)->userid);
    }

    /**
     * F07.AC04–AC06: returning and rejecting need a reason, which reaches the proposer; the deciding person gets nothing.
     */
    public function test_return_and_reject_notify(): void {
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $teacher = $this->getDataGenerator()->create_user();
        $proposal = $this->propose($item, 'proposal', (int) $teacher->id);

        try {
            review_manager::return_for_revision((int) $proposal->id, '  ');
            $this->fail('A reason is required.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_reasonrequired', $e->errorcode);
        }
        $this->assertSame(status::REVIEW, version_manager::get($proposal->id)->status);

        $sink = $this->redirectMessages();
        review_manager::return_for_revision((int) $proposal->id, 'Please add the emergency numbers.');
        $messages = $sink->get_messages_by_component_and_type('local_nextpool', 'reviewdecision');
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, reset($messages)->useridto);
        $this->assertStringContainsString('Please add the emergency numbers.', reset($messages)->fullmessage);
        $sink->clear();

        // Resubmitted, then rejected: the earlier reason stays in the history (AC05, AC06).
        version_manager::set_status((int) $proposal->id, status::REVIEW, (int) $teacher->id);
        review_manager::reject((int) $proposal->id, 'Replaced by the new handbook.');
        $messages = $sink->get_messages_by_component_and_type('local_nextpool', 'reviewdecision');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Replaced by the new handbook.', reset($messages)->fullmessage);
        $reasons = array_values(array_filter(array_map(
            static fn($log) => $log->reason,
            version_manager::get_log((int) $proposal->id)
        )));
        $this->assertSame(['Please add the emergency numbers.', 'Replaced by the new handbook.'], $reasons);
        $this->assertFalse(\local_nextpool\local\status::can_transition(status::REJECTED, status::REVIEW));
    }

    /**
     * F07.AC03, F08.AC04: on publishing, the proposer and the subscribers are notified, each once, not the reviewer.
     */
    public function test_publish_notifies(): void {
        global $DB, $USER;
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $teacher = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();
        $gone = $this->getDataGenerator()->create_user();
        $unsubscribed = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $editor->id, \context_system::instance()->id);
        $proposal = $this->propose($item, 'proposal', (int) $teacher->id);
        // Only the subscriptions of this test, whatever was subscribed automatically before.
        $DB->delete_records('local_nextpool_subscription', ['itemid' => $item->id]);
        foreach ([$teacher, $editor, $gone, $unsubscribed, $USER] as $user) {
            $DB->insert_record('local_nextpool_subscription', (object) [
                'itemid' => $item->id,
                'userid' => $user->id,
                'courseid' => null,
                'auto' => 1,
                'unsubscribed' => (int) ($user->id === $unsubscribed->id),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $sink = $this->redirectMessages();
        review_manager::publish((int) $proposal->id);
        $decision = $sink->get_messages_by_component_and_type('local_nextpool', 'reviewdecision');
        $published = $sink->get_messages_by_component_and_type('local_nextpool', 'versionpublished');
        $this->assertSame([(int) $teacher->id], array_values(array_map(static fn($m) => (int) $m->useridto, $decision)));
        // The person without access to the item ($gone) and the one who unsubscribed get nothing.
        $this->assertSame([(int) $editor->id], array_values(array_map(static fn($m) => (int) $m->useridto, $published)));
        $this->assertStringContainsString('/local/nextpool/item.php?id=' . $item->id, reset($published)->contexturl);
    }

    /**
     * F07.AC07: after the first of two proposals is published, the second shows that its base is outdated.
     */
    public function test_outdated_base(): void {
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $teacher = $this->getDataGenerator()->create_user();
        $first = $this->propose($item, 'first', (int) $teacher->id);
        $second = $this->propose($item, 'second', (int) $teacher->id);
        $this->assertNull(review_manager::get_outdated_base($second));

        review_manager::publish((int) $first->id);
        $outdated = review_manager::get_outdated_base(version_manager::get($second->id));
        $this->assertSame(1, (int) $outdated['base']->versionnr);
        $this->assertSame(2, (int) $outdated['current']->versionnr);
        $this->assertStringStartsWith('Proposal by ', review_manager::get_version_name($second));
        $this->assertSame('Version 2', review_manager::get_version_name(version_manager::get($first->id)));

        // The review page names both versions; only people who may open the comparison get the link (bug103).
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $page = new \local_nextpool\output\review_page(item_manager::get((int) $item->id), version_manager::get($second->id));
        $data = $page->export_for_template($output);
        $this->assertStringContainsString('Based on version 1, current is version 2.', $data['outdated']);
        $this->assertStringContainsString('/local/nextpool/compare.php', $data['outdated']);

        $this->setUser($teacher);
        $data = $page->export_for_template($output);
        $this->assertStringContainsString('Based on version 1, current is version 2.', $data['outdated']);
        $this->assertStringNotContainsString('compare.php', $data['outdated']);
        $fields = array_column($data['comparison']['rows'], 'field');
        $this->assertNotContains(get_string('comparefield_sourceref', 'local_nextpool'), $fields);
        $this->assertNotContains(get_string('comparefield_contenthash', 'local_nextpool'), $fields);
    }

    /**
     * An older numbered draft is published without touching the newer file in the storage location (t80-c).
     */
    public function test_older_version_is_not_written(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'handout.pdf', 'one');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $v1 = version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $this->generator->put_file($source, 'handout.pdf', 'two');
        version_manager::create_from_source((int) $item->id, 'handout.pdf');
        version_manager::set_status((int) $v1->id, status::REVIEW);

        review_manager::publish((int) $v1->id);
        $this->assertSame('two', self::read($source, 'handout.pdf'));
        $this->assertSame(status::PUBLISHED, version_manager::get($v1->id)->status);
    }

    /**
     * Two versions of an item are not published at the same time: while one is published, another publish stops
     * before it writes (bug161).
     */
    public function test_publish_is_locked_per_item(): void {
        global $CFG;
        // Row locks: database advisory locks can be taken twice by the same connection, i.e. by this test.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $source = $this->generator->create_source();
        $item = $this->create_published_item($source);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);

        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('publish_item_' . $item->id, 0);
        $this->assertNotFalse($lock);
        try {
            review_manager::publish((int) $proposal->id, [], $source);
            $this->fail('Publishing must wait for the other publish.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_publishfailed', $e->errorcode);
            $this->assertSame(get_string('error_publishbusy', 'local_nextpool'), $e->a);
        } finally {
            $lock->release();
        }
        $this->assertSame('version 1', self::read($source, 'handout.pdf'), 'Nothing was written.');
        $this->assertSame(status::REVIEW, version_manager::get($proposal->id)->status);

        $published = review_manager::publish((int) $proposal->id, [], $source);
        $this->assertSame(2, (int) $published->versionnr, 'The lock is released after a publish.');
    }

    /**
     * If the records cannot be changed after the write, the write is undone and no transaction stays open: the
     * storage location holds the latest version again and the next publish does not see a change (bug161).
     */
    public function test_failed_publish_undoes_the_write(): void {
        global $DB;
        // The transactions under test must be the outermost ones.
        $this->preventResetByRollback();
        $moodlesource = $this->generator->create_source();
        $item = $this->create_published_item($moodlesource);
        $latest = version_manager::get_latest((int) $item->id);
        $proposal = $this->propose($item, 'proposal', (int) $this->getDataGenerator()->create_user()->id);
        $other = $this->propose($item, 'other proposal', (int) $this->getDataGenerator()->create_user()->id);
        $record = (object) ['id' => $moodlesource->get_id(), 'plugin' => 'moodle', 'name' => 'Racing', 'enabled' => 1];
        // Another reviewer rejects the proposal while its file is written.
        $source = new class ($record, ['basefolder' => $moodlesource->get_basefolder()]) extends \nextpoolsource_moodle\source {
            /** @var int Version that is rejected during the next write, 0 for none. */
            public int $rejectonwrite = 0;

            #[\Override]
            public function write(string $path, string $sourcefile): entry {
                global $DB;
                $entry = parent::write($path, $sourcefile);
                if ($this->rejectonwrite) {
                    $DB->set_field(version_manager::TABLE, 'status', status::REJECTED, ['id' => $this->rejectonwrite]);
                    $this->rejectonwrite = 0;
                }
                return $entry;
            }
        };
        $source->rejectonwrite = (int) $proposal->id;

        try {
            review_manager::publish((int) $proposal->id, ['changelog' => 'Lost'], $source);
            $this->fail('Publishing must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_statustransition', $e->errorcode);
        }
        $this->assertFalse($DB->is_transaction_started(), 'No transaction is left open.');
        $this->assertSame('version 1', self::read($source, 'handout.pdf'), 'The write is undone.');
        $this->assertEquals($latest->id, item_manager::get((int) $item->id)->currentversionid);
        $this->assertSame('Emergency numbers added.', version_manager::get($proposal->id)->changelog);
        $this->assertSame($source->stat('handout.pdf')->revision, version_manager::get($latest->id)->sourcerevision);

        $published = review_manager::publish((int) $other->id, [], $source);
        $this->assertSame('other proposal', self::read($source, 'handout.pdf'));
        $this->assertSame(2, (int) $published->versionnr);
    }
}
