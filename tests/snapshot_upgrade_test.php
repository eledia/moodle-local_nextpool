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

use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\snapshot_sender;
use local_nextpool\local\version_manager;

/**
 * Upgrade step for snapshots stored before bug234 was fixed (savepoint 2026111542).
 *
 * The tests put snapshots back into their old state (no owner) and let core's H5P player deploy them, as
 * /h5p/embed.php did, before they run the step.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nextpool\local\h5p_guard::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_nextpool_upgrade')]
final class snapshot_upgrade_test extends \advanced_testcase {
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
     * Bytes of the core greeting card fixture, which carries its libraries.
     *
     * @return string
     */
    private function greeting_card(): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p');
    }

    /**
     * Ask core's H5P player for a file, as /h5p/embed.php does.
     *
     * @param \stored_file $file File.
     * @return int|false H5P id or false.
     */
    private function embed(\stored_file $file) {
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
        $messages = new \stdClass();
        $config = (object) ['frame' => 0, 'export' => 0, 'embed' => 0, 'copyright' => 0];
        [, $h5pid] = \core_h5p\api::create_content_from_pluginfile_url(
            $url->out(false),
            $config,
            new \core_h5p\factory(),
            $messages
        );
        return $h5pid;
    }

    /**
     * A published H5P item whose snapshot is in the state before bug234 (no owner) and was deployed by core.
     *
     * @return \stored_file The snapshot.
     */
    private function exposed_snapshot(): \stored_file {
        global $DB;
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->greeting_card(), 'card.h5p', true);
        $file = version_manager::get_file((int) $item->currentversionid);
        // Before bug234 neither the file nor its directory entry had an owner.
        $DB->set_field('files', 'userid', null, [
            'contextid' => $file->get_contextid(),
            'component' => 'local_nextpool',
            'filearea' => version_manager::FILEAREA,
            'itemid' => $file->get_itemid(),
        ]);
        $file = version_manager::get_file((int) $item->currentversionid);
        $this->assertNotEmpty($this->embed($file), 'Without an owner core deploys the snapshot.');
        $this->assertTrue($DB->record_exists('h5p', ['pathnamehash' => $file->get_pathnamehash()]));
        return $file;
    }

    /**
     * Owners of all files of the site with their file id as key.
     *
     * @return array
     */
    private function owners(): array {
        global $DB;
        return $DB->get_records_menu('files', null, 'id', 'id, userid');
    }

    /**
     * The step gives snapshots the guest user as owner, removes their deployed contents, counts them and leaves
     * everything else alone; a second run changes nothing.
     */
    public function test_secure_existing_snapshots(): void {
        global $CFG, $DB;
        $snapshot = $this->exposed_snapshot();
        $directory = get_file_storage()->get_file(
            $snapshot->get_contextid(),
            'local_nextpool',
            version_manager::FILEAREA,
            $snapshot->get_itemid(),
            '/',
            '.'
        );
        $this->assertNull($directory->get_userid());

        // A placed activity, deployed by core with the publisher as owner.
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->greeting_card(), 'other.h5p', true);
        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $files = get_file_storage()->get_area_files(
            \context_module::instance((int) $placement->cmid)->id,
            'mod_h5pactivity',
            'package',
            0,
            'id',
            false
        );
        $package = reset($files);
        $this->assertNotEmpty($this->embed($package));

        // Files without an owner of the same component in another area and of the storage location (as before bug238).
        $systemcontextid = \context_system::instance()->id;
        $other = get_file_storage()->create_file_from_string(['contextid' => $systemcontextid, 'component' => 'local_nextpool',
            'filearea' => 'other', 'itemid' => 0, 'filepath' => '/', 'filename' => 'card.h5p'], $this->greeting_card());
        $DB->set_field('files', 'userid', null, ['component' => 'nextpoolsource_moodle']);
        $sourcefiles = $DB->get_records('files', ['component' => 'nextpoolsource_moodle']);
        $this->assertNotEmpty($sourcefiles);

        $before = $this->owners();
        $h5pcount = $DB->count_records('h5p');
        $libraries = $DB->count_records('h5p_libraries');

        $this->assertSame(1, version_manager::secure_existing_snapshots());

        $after = $this->owners();
        $this->assertEquals($CFG->siteguest, $after[$snapshot->get_id()]);
        $this->assertNull($after[$directory->get_id()], 'Directory entries are left alone.');
        $this->assertEquals($package->get_userid(), $after[$package->get_id()]);
        $this->assertNull($after[$other->get_id()]);
        foreach ($sourcefiles as $file) {
            $this->assertNull($after[$file->id]);
        }
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        $this->assertEquals([$snapshot->get_id()], $changed, 'Only the snapshot changed its owner.');

        $this->assertFalse($DB->record_exists('h5p', ['pathnamehash' => $snapshot->get_pathnamehash()]));
        $this->assertTrue($DB->record_exists('h5p', ['pathnamehash' => $package->get_pathnamehash()]));
        $this->assertSame($h5pcount - 1, $DB->count_records('h5p'));
        $this->assertSame($libraries, $DB->count_records('h5p_libraries'), 'Libraries stay installed.');

        // A second run (a resumed upgrade) changes nothing.
        $this->assertSame(0, version_manager::secure_existing_snapshots());
        $this->assertEquals($after, $this->owners());
        $this->assertSame($h5pcount - 1, $DB->count_records('h5p'));
    }

    /**
     * Core still showed a deployed snapshot after its owner had changed; after the step it shows it to nobody,
     * also when the upgrade stopped after the owner change.
     */
    public function test_core_no_longer_serves_old_snapshot(): void {
        global $CFG, $DB;
        $snapshot = $this->exposed_snapshot();
        $user = $this->getDataGenerator()->create_user();

        // Interrupted after the first part: the owner is right, but core shows the deployed content without a check.
        $DB->set_field('files', 'userid', $CFG->siteguest, ['id' => $snapshot->get_id()]);
        $this->setUser($user);
        $this->assertNotEmpty($this->embed(version_manager::get_file($snapshot->get_itemid())));

        $this->setAdminUser();
        $this->assertSame(1, version_manager::secure_existing_snapshots());

        $this->setUser($user);
        $this->assertEmpty($this->embed(version_manager::get_file($snapshot->get_itemid())));
        $this->setAdminUser();
        $this->assertEmpty($this->embed(version_manager::get_file($snapshot->get_itemid())));
        $this->assertFalse($DB->record_exists('h5p', ['pathnamehash' => $snapshot->get_pathnamehash()]));
    }

    /**
     * Editors still download the snapshot after the step.
     */
    public function test_editors_still_download_snapshot(): void {
        $snapshot = $this->exposed_snapshot();
        version_manager::secure_existing_snapshots();

        $sender = new class extends snapshot_sender {
            /** @var \stored_file[] Files that would have been sent. */
            public array $sent = [];

            #[\Override]
            public function send(\stored_file $file, array $options): void {
                $this->sent[] = $file;
            }
        };
        \core\di::set(snapshot_sender::class, $sender);
        $args = [$snapshot->get_itemid(), $snapshot->get_filename()];
        local_nextpool_pluginfile(get_site(), null, \context_system::instance(), version_manager::FILEAREA, $args, false);
        $this->assertCount(1, $sender->sent);
        $this->assertSame($snapshot->get_contenthash(), $sender->sent[0]->get_contenthash());
    }

    /**
     * The upgrade step runs the method, warns about libraries when it removed contents and sets its savepoint.
     */
    public function test_upgrade_step(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/nextpool/db/upgrade.php');
        $snapshot = $this->exposed_snapshot();
        set_config('version', 2026111541, 'local_nextpool');

        $this->expectOutputRegex('/Removed 1 H5P contents.*Check the H5P content types/s');
        $this->assertTrue(xmldb_local_nextpool_upgrade(2026111541));

        $this->assertSame(2026111591, (int) get_config('local_nextpool', 'version'));
        $this->assertEquals($CFG->siteguest, $DB->get_field('files', 'userid', ['id' => $snapshot->get_id()]));
        $this->assertFalse($DB->record_exists('h5p', ['pathnamehash' => $snapshot->get_pathnamehash()]));
        $this->assertTrue($DB->record_exists('upgrade_log', ['plugin' => 'local_nextpool', 'type' => UPGRADE_LOG_NOTICE]));
    }
}
