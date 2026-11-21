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

use local_nextpool\local\access;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Version snapshots must not be shown or deployed through core's H5P player (/h5p/embed.php, bug234).
 *
 * Core checks nothing for files in the system context and treats files without an owner as trusted, so a
 * snapshot without an owner could be shown to anybody who knows its address and could install the libraries
 * in the package. The tests call the core function behind /h5p/embed.php, core_h5p\player.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('local_nextpool_pluginfile')]
#[\PHPUnit\Framework\Attributes\CoversClass(version_manager::class)]
final class h5p_snapshot_exposure_test extends \advanced_testcase {
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
     * Ask core's H5P player for a pluginfile address, as /h5p/embed.php does.
     *
     * @param \moodle_url $url Pluginfile address.
     * @return array [H5P id or false, error messages]
     */
    private function embed(\moodle_url $url): array {
        $factory = new \core_h5p\factory();
        $messages = new \stdClass();
        $config = (object) ['frame' => 0, 'export' => 0, 'embed' => 0, 'copyright' => 0];
        [, $h5pid] = \core_h5p\api::create_content_from_pluginfile_url($url->out(false), $config, $factory, $messages);
        $errors = array_map(fn($e) => $e->message, $factory->get_framework()->getMessages('error') ?: []);
        return [$h5pid, $errors];
    }

    /**
     * Address of a version snapshot.
     *
     * @param \stdClass $version Version record.
     * @return \moodle_url
     */
    private function snapshot_url(\stdClass $version): \moodle_url {
        $file = version_manager::get_file((int) $version->id);
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * A logged-in user without any Nextpool right cannot have the snapshot of a draft shown or deployed.
     */
    public function test_draft_snapshot_is_not_deployed_for_unprivileged_user(): void {
        global $DB;
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'card.h5p', $this->greeting_card());
        $item = $this->generator->create_item(['type' => 'h5p', 'sourceid' => $source->get_id()]);
        $draft = version_manager::create_from_source((int) $item->id, 'card.h5p');
        $this->assertSame(status::DRAFT, $draft->status);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertFalse(access::can_view($item), 'The user has no right to see the item.');

        [$h5pid, $errors] = $this->embed($this->snapshot_url($draft));
        $this->assertEmpty($h5pid, 'Core must not deploy the snapshot.');
        $this->assertContains(get_string('nopermissiontodeploy', 'core_h5p'), $errors);
        $this->assertFalse($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
        $this->assertFalse($DB->record_exists('h5p', []));
    }

    /**
     * A visitor who is not logged in cannot have a snapshot deployed either.
     *
     * Core up to at least 5.1.3 and 5.2.1 asks for no login for files in the system context; later core
     * releases require one, then core_h5p\api refuses before the deploy check.
     */
    public function test_snapshot_is_not_deployed_for_visitor(): void {
        global $DB;
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->greeting_card(), 'card.h5p', true);
        $url = $this->snapshot_url(version_manager::get((int) $item->currentversionid));
        $this->setUser(null);

        try {
            [$h5pid] = $this->embed($url);
            $this->assertEmpty($h5pid);
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }
        $this->assertFalse($DB->record_exists('h5p', []));
        $this->assertFalse($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
    }

    /**
     * Even a site administrator does not deploy a snapshot: snapshots are no H5P content of their own.
     */
    public function test_snapshot_is_not_deployed_for_admin(): void {
        global $DB;
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->greeting_card(), 'card.h5p', true);
        $version = version_manager::get((int) $item->currentversionid);

        [$h5pid] = $this->embed($this->snapshot_url($version));
        $this->assertEmpty($h5pid);
        $this->assertFalse($DB->record_exists('h5p', []));
    }

    /**
     * Snapshots get an owner that may never deploy H5P, and placing still works with the publisher as owner.
     */
    public function test_placed_activity_still_deploys(): void {
        global $CFG, $DB;
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->greeting_card(), 'card.h5p', true);
        $version = version_manager::get((int) $item->currentversionid);
        $this->assertEquals($CFG->siteguest, version_manager::get_file((int) $version->id)->get_userid());

        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $context = \context_module::instance((int) $placement->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $package = reset($files);
        $this->assertEquals(get_admin()->id, $package->get_userid());

        $url = \moodle_url::make_pluginfile_url($context->id, 'mod_h5pactivity', 'package', 0, '/', $package->get_filename());
        [$h5pid] = $this->embed($url);
        $this->assertNotEmpty($h5pid, 'The placed activity deploys as before.');
        $this->assertTrue($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
    }
}
