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

namespace nextpoolsource_moodle;

use local_nextpool\local\h5p_guard;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\source\manager;
use local_nextpool\local\version_manager;

/**
 * Files of the storage location "Moodle files" must not be shown or deployed through core's H5P player (bug238).
 *
 * The files live in the system context. Core's /h5p/embed.php checks no access there and treats files without an
 * owner as trusted, so an .h5p file stored without an owner could be shown to anybody who knows its address
 * (storage location id, folder and file name) and could install the libraries in the package. The tests call the
 * core function behind /h5p/embed.php.
 *
 * @package   nextpoolsource_moodle
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(h5p_guard::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_nextpoolsource_moodle_upgrade')]
final class h5p_exposure_test extends \advanced_testcase {
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
     * @return array [H5P id or false, error messages]
     */
    private function embed(\stored_file $file): array {
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
        $factory = new \core_h5p\factory();
        $messages = new \stdClass();
        $config = (object) ['frame' => 0, 'export' => 0, 'embed' => 0, 'copyright' => 0];
        [, $h5pid] = \core_h5p\api::create_content_from_pluginfile_url($url->out(false), $config, $factory, $messages);
        $errors = array_map(fn($e) => $e->message, $factory->get_framework()->getMessages('error') ?: []);
        return [$h5pid, $errors];
    }

    /**
     * An .h5p file written into a storage location "Moodle files".
     *
     * @param string $path Path in the storage location.
     * @return \stored_file
     */
    private function stored_card(string $path = 'units/card.h5p'): \stored_file {
        $source = $this->generator->create_source('moodle');
        $this->generator->put_file($source, $path, $this->greeting_card());
        $entry = $source->stat($path);
        return get_file_storage()->get_file_by_id((int) $entry->externalid);
    }

    /**
     * A logged-in user without any Nextpool right cannot have a file of the storage location shown or deployed.
     */
    public function test_file_is_not_deployed_for_unprivileged_user(): void {
        global $CFG, $DB;
        $file = $this->stored_card();
        $this->assertEquals($CFG->siteguest, $file->get_userid());

        $this->setUser($this->getDataGenerator()->create_user());
        [$h5pid, $errors] = $this->embed($file);
        $this->assertEmpty($h5pid, 'Core must not deploy the file.');
        $this->assertContains(get_string('nopermissiontodeploy', 'core_h5p'), $errors);
        $this->assertFalse($DB->record_exists('h5p', []));
        $this->assertFalse($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
    }

    /**
     * A visitor who is not logged in cannot have a file deployed either.
     *
     * Core up to at least 5.1.3 and 5.2.1 asks for no login for files in the system context; later core
     * releases require one, then core_h5p\api refuses before the deploy check.
     */
    public function test_file_is_not_deployed_for_visitor(): void {
        global $DB;
        $file = $this->stored_card();
        $this->setUser(null);

        try {
            [$h5pid] = $this->embed($file);
            $this->assertEmpty($h5pid);
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }
        $this->assertFalse($DB->record_exists('h5p', []));
        $this->assertFalse($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
    }

    /**
     * Even a site administrator does not deploy a file of the storage location: it is no H5P content of its own.
     */
    public function test_file_is_not_deployed_for_admin(): void {
        global $DB;
        [$h5pid] = $this->embed($this->stored_card());
        $this->assertEmpty($h5pid);
        $this->assertFalse($DB->record_exists('h5p', []));
    }

    /**
     * Overwriting a file keeps the guarded owner.
     */
    public function test_overwritten_file_keeps_owner(): void {
        global $CFG;
        $source = $this->generator->create_source('moodle');
        $this->generator->put_file($source, 'card.h5p', 'old');
        $this->generator->put_file($source, 'card.h5p', $this->greeting_card());
        $file = get_file_storage()->get_file_by_id((int) $source->stat('card.h5p')->externalid);
        $this->assertEquals($CFG->siteguest, $file->get_userid());
        $this->assertSame(sha1($this->greeting_card()), $file->get_contenthash());
    }

    /**
     * Importing from the storage location, placing and playing still work.
     */
    public function test_import_and_placement_still_work(): void {
        global $DB;
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->greeting_card(), 'card.h5p', true);
        $version = version_manager::get((int) $item->currentversionid);
        $this->assertSame(sha1($this->greeting_card()), version_manager::get_file((int) $version->id)->get_contenthash());

        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $context = \context_module::instance((int) $placement->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        [$h5pid] = $this->embed(reset($files));
        $this->assertNotEmpty($h5pid, 'The placed activity deploys as before.');
        $this->assertTrue($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
    }

    /**
     * The upgrade step gives existing files the guest user as owner, removes what core deployed from them, warns
     * about libraries, leaves other files alone and sets its savepoint; a second run changes nothing.
     */
    public function test_upgrade_step(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/nextpool/source/moodle/db/upgrade.php');

        // A file in the state before bug238: no owner, already deployed by core.
        $file = $this->stored_card();
        $DB->set_field('files', 'userid', null, ['component' => 'nextpoolsource_moodle']);
        $file = get_file_storage()->get_file_by_id($file->get_id());
        [$h5pid] = $this->embed($file);
        $this->assertNotEmpty($h5pid, 'Without an owner core deploys the file.');
        $directory = get_file_storage()->get_file(
            $file->get_contextid(),
            'nextpoolsource_moodle',
            source::FILEAREA,
            $file->get_itemid(),
            '/',
            '.'
        );

        // Other files without an owner in the system context stay as they are.
        $other = get_file_storage()->create_file_from_string(['contextid' => $file->get_contextid(),
            'component' => 'local_nextpool', 'filearea' => 'other', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'card.h5p'], $this->greeting_card());
        $before = $DB->get_records_menu('files', null, 'id', 'id, userid');
        $libraries = $DB->count_records('h5p_libraries');

        set_config('version', 2026111401, 'nextpoolsource_moodle');
        $this->expectOutputRegex('/Removed 1 H5P contents.*storage location.*Check the H5P content types/s');
        $this->assertTrue(xmldb_nextpoolsource_moodle_upgrade(2026111401));

        $this->assertSame(2026111405, (int) get_config('nextpoolsource_moodle', 'version'));
        $after = $DB->get_records_menu('files', null, 'id', 'id, userid');
        $this->assertEquals($CFG->siteguest, $after[$file->get_id()]);
        $this->assertNull($after[$directory->get_id()], 'Directory entries are left alone.');
        $this->assertNull($after[$other->get_id()]);
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        $this->assertEquals([$file->get_id()], $changed, 'Only the file of the storage location changed its owner.');
        $this->assertFalse($DB->record_exists('h5p', ['pathnamehash' => $file->get_pathnamehash()]));
        $this->assertSame($libraries, $DB->count_records('h5p_libraries'), 'Libraries stay installed.');
        $this->assertTrue($DB->record_exists('upgrade_log', ['plugin' => 'nextpoolsource_moodle', 'type' => UPGRADE_LOG_NOTICE]));

        $this->setUser($this->getDataGenerator()->create_user());
        [$h5pid] = $this->embed(get_file_storage()->get_file_by_id($file->get_id()));
        $this->assertEmpty($h5pid, 'Core no longer shows the formerly deployed content.');

        // A second run (a resumed upgrade) changes nothing.
        $this->setAdminUser();
        $this->assertSame(0, h5p_guard::secure_area('nextpoolsource_moodle', source::FILEAREA, $file->get_contextid()));
        $this->assertEquals($after, $DB->get_records_menu('files', null, 'id', 'id, userid'));
    }
}
