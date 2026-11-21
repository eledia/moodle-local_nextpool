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

use local_nextpool\local\content_type\h5p;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\version_manager;

/**
 * Tests for the H5P content type: place, update in place, refusal rules (task25b, q18, q19, q39, bug88).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(h5p::class)]
final class content_type_h5p_test extends \advanced_testcase {
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
     * Bytes of a core H5P fixture package.
     *
     * @param string $name File name in h5p/tests/fixtures.
     * @return string
     */
    private function fixture(string $name): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/h5p/tests/fixtures/' . $name);
    }

    /**
     * The greeting card fixture with a changed text: same kind of content, different package.
     *
     * @param string $marker Name of the added property, for several different packages.
     * @return string
     */
    private function changed_greeting_card(string $marker = 'nextpoolchanged'): string {
        $dir = make_request_directory();
        $zip = new \ZipArchive();
        $source = $dir . '/source.h5p';
        file_put_contents($source, $this->fixture('greeting-card.h5p'));
        $zip->open($source);
        $content = (string) $zip->getFromName('content/content.json');
        $zip->addFromString('content/content.json', str_replace('}', ',"' . $marker . '":true}', $content));
        $zip->close();
        return (string) file_get_contents($source);
    }

    /**
     * Context of the course module of a placement.
     *
     * @param \stdClass $placement Placement.
     * @return \context_module
     */
    private function cm_context(\stdClass $placement): \context_module {
        return \context_module::instance((int) $placement->cmid);
    }

    /**
     * An H5P item becomes an H5P activity; a new version replaces the package and keeps attempts.
     *
     * Published with H5P library updates allowed (q39), so the activity gets the package as it is.
     */
    public function test_place_and_update(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p', true);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $this->assertSame('h5pactivity', $cm->modname);
        $contextid = $this->cm_context($placement)->id;
        $files = get_file_storage()->get_area_files($contextid, 'mod_h5pactivity', 'package', 0, 'id', false);
        $package = reset($files);
        $this->assertSame('card.h5p', $package->get_filename());
        $this->assertEquals(get_admin()->id, $package->get_userid(), 'The package belongs to the publisher (q18).');

        // An attempt of a learner must survive the update.
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attemptid = $DB->insert_record('h5pactivity_attempts', (object) [
            'h5pactivityid' => $cm->instance, 'userid' => $learner->id, 'attempt' => 1,
            'timecreated' => time(), 'timemodified' => time(), 'rawscore' => 1, 'maxscore' => 1,
            'scaled' => 1, 'duration' => 10, 'completion' => 1, 'success' => 1,
        ]);

        $version2 = $this->generator->publish_new_version((int) $item->id, $this->changed_greeting_card(), 'card.h5p', true);
        $this->assertNull((new h5p())->check_update($cm, $item, $version2));
        placement_manager::apply((int) $placement->id, (int) $version2->id);

        $contextid = $this->cm_context($placement)->id;
        $files = get_file_storage()->get_area_files($contextid, 'mod_h5pactivity', 'package', 0, 'id', false);
        $updated = reset($files);
        $this->assertSame('card.h5p', $updated->get_filename(), 'The file name stays, so the H5P record stays.');
        $this->assertSame(version_manager::get_file((int) $version2->id)->get_contenthash(), $updated->get_contenthash());
        $this->assertSame((int) $placement->cmid, (int) placement_manager::get((int) $placement->id)->cmid);
        $this->assertTrue($DB->record_exists('h5pactivity_attempts', ['id' => $attemptid]));
    }

    /**
     * A different kind of H5P content, saved learner progress or libraries the publisher may not install
     * are reasons to ask the teacher instead of updating automatically.
     */
    public function test_check_update_refusals(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        // With the library option, so it can be placed on a site without the library (bug88).
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p', true);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $handler = new h5p();

        // Other main library.
        $other = $this->generator->publish_new_version((int) $item->id, $this->fixture('drag.h5p'), 'card.h5p');
        $this->assertStringContainsString('H5P.DragText', (string) $handler->check_update($cm, $item, $other));

        // Saved progress of a learner (xAPI state "state" of this activity).
        $same = $this->generator->publish_new_version((int) $item->id, $this->changed_greeting_card(), 'card.h5p', true);
        $DB->insert_record('xapi_states', (object) [
            'component' => 'mod_h5pactivity', 'userid' => get_admin()->id, 'itemid' => $this->cm_context($placement)->id,
            'stateid' => 'state', 'statedata' => '{"progress":1}', 'registration' => null,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertSame(get_string('noinplace_h5pstates', 'local_nextpool'), $handler->check_update($cm, $item, $same));
        $DB->delete_records('xapi_states');
        $this->assertNull($handler->check_update($cm, $item, $same));

        // Libraries missing and a publisher without the right to install them.
        $publisher = $this->getDataGenerator()->create_user();
        $DB->set_field('local_nextpool_version', 'publishedby', $publisher->id, ['id' => $same->id]);
        $same = version_manager::get((int) $same->id);
        $this->assertSame(
            get_string('noinplace_h5plibraries', 'local_nextpool', 'H5P.GreetingCard 1.0'),
            $handler->check_update($cm, $item, $same)
        );

        // Libraries missing and the version published without library updates (q39): refused whoever published it.
        $nooption = $this->generator->publish_new_version(
            (int) $item->id,
            $this->changed_greeting_card('nooption'),
            'card.h5p'
        );
        $this->assertEquals(get_admin()->id, $nooption->publishedby);
        $this->assertSame(
            get_string('noinplace_h5plibrariesoff', 'local_nextpool', 'H5P.GreetingCard 1.0'),
            $handler->check_update($cm, $item, $nooption)
        );
    }

    /**
     * h5p.json and library checks read the package without deploying it.
     */
    public function test_package_helpers(): void {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'card.h5p',
        ], $this->fixture('greeting-card.h5p'));
        $json = h5p::read_h5p_json($file);
        $this->assertSame('H5P.GreetingCard', $json['mainLibrary']);
        $this->assertSame(['H5P.GreetingCard 1.0'], h5p::get_missing_libraries($json));

        $notzip = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'broken.h5p',
        ], 'no zip');
        $this->assertNull(h5p::read_h5p_json($notzip));
    }

    /**
     * The package belongs to the person who published the version, not to the teacher who places or updates it
     * (q18, Q-18).
     */
    public function test_package_owner_is_the_publisher(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $reviewer = $this->getDataGenerator()->create_user();
        $secondreviewer = $this->getDataGenerator()->create_user();

        $this->setUser($reviewer);
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p', true);
        // The reviewer may not install H5P libraries, so the site needs the library before courses can place it (bug98).
        $this->install_greeting_card_library();
        $this->setUser($teacher);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $owner = fn() => (int) array_values(get_file_storage()->get_area_files(
            $this->cm_context($placement)->id,
            'mod_h5pactivity',
            'package',
            0,
            'id',
            false
        ))[0]->get_userid();
        $this->assertEquals($reviewer->id, $owner(), 'Placed by the teacher, owned by the publisher.');

        // Only the second publisher may install H5P libraries; the teacher may not. The library check follows
        // the publisher, so the teacher can apply the version (the check would refuse with the teacher's rights).
        $manager = \core\di::get(\moodle_database::class)->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign((int) $manager, (int) $secondreviewer->id, \context_system::instance()->id);
        $this->assertFalse(has_capability('moodle/h5p:updatelibraries', \context_system::instance(), $teacher));
        $this->setUser($secondreviewer);
        $version2 = $this->generator->publish_new_version((int) $item->id, $this->changed_greeting_card(), 'card.h5p', true);
        $this->setUser($teacher);
        placement_manager::apply((int) $placement->id, (int) $version2->id);
        $this->assertEquals($secondreviewer->id, $owner(), 'Updated by the teacher, owned by the publisher of version 2.');
    }

    /**
     * Saved progress is found where core stores it: the xAPI state API keys H5P states by the module context id,
     * and deploying new content resets them, after which the update is possible again (Q-26).
     */
    public function test_saved_progress_as_core_stores_it(): void {
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p', true);
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $version2 = $this->generator->publish_new_version((int) $item->id, $this->changed_greeting_card(), 'card.h5p', true);
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $handler = new h5p();
        $this->assertNull($handler->check_update($cm, $item, $version2));

        // The H5P player saves progress through core's xAPI state API with the module context id as activity id.
        $this->setUser($learner);
        $activityid = (string) $this->cm_context($placement)->id;
        $xapi = \core_xapi\handler::create('mod_h5pactivity');
        $xapi->save_state(new \core_xapi\local\state(
            \core_xapi\local\statement\item_agent::create_from_user($learner),
            \core_xapi\local\statement\item_activity::create_from_id($activityid),
            'state',
            (object) ['h5p' => '{"progress":1}'],
            null
        ));
        $this->setAdminUser();
        $this->assertSame(get_string('noinplace_h5pstates', 'local_nextpool'), $handler->check_update($cm, $item, $version2));

        // Core resets these states when it deploys changed content (core_h5p\framework::resetContentUserData()).
        $xapi->reset_states($activityid, null, 'state');
        $this->assertNull($handler->check_update($cm, $item, $version2));
    }

    /**
     * The H5P package of a placed activity.
     *
     * @param \stdClass $placement Placement.
     * @return \stored_file The package.
     */
    private function placed_package(\stdClass $placement): \stored_file {
        $files = get_file_storage()->get_area_files(
            $this->cm_context($placement)->id,
            'mod_h5pactivity',
            'package',
            0,
            'id',
            false
        );
        return reset($files);
    }

    /**
     * Entry names of a ZIP package.
     *
     * @param \stored_file $file Package.
     * @return string[]
     */
    private function zip_entries(\stored_file $file): array {
        $path = make_request_directory() . '/p.zip';
        $file->copy_content_to($path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $names;
    }

    /**
     * Deploy an H5P package the way core does when the activity is viewed (core_h5p\api::create_content_from_file()).
     *
     * @param \stored_file $file Package.
     * @return int|false|null H5P id, false or null if core did not deploy it.
     */
    private function deploy(\stored_file $file) {
        $factory = new \core_h5p\factory();
        $onlyupdatelibs = !\core_h5p\helper::can_update_library($file);
        return \core_h5p\helper::save_h5p($factory, $file, (object) [], $onlyupdatelibs);
    }

    /**
     * Without the option at publishing, a package installs no H5P libraries, even if its publisher could (q39).
     *
     * On a site without the libraries such a version cannot be placed (bug88); once they are installed, the
     * activity gets the content only.
     */
    public function test_no_library_updates_without_option(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p');
        $version = version_manager::get((int) $item->currentversionid);
        $this->assertFalse(h5p::allows_library_updates($version));

        // The content-only package: h5p.json and the content, no library folders. Core deploys nothing from it
        // and installs no library while the site does not have the library.
        $contentonly = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance(get_admin()->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(), 'filepath' => '/', 'filename' => 'card.h5p',
            'userid' => get_admin()->id,
        ], h5p::create_content_only_package(version_manager::get_file((int) $version->id)));
        $entries = $this->zip_entries($contentonly);
        $this->assertContains('h5p.json', $entries);
        $this->assertContains('content/content.json', $entries);
        $this->assertEmpty(preg_grep('~^H5P\.GreetingCard-1\.0/~', $entries), 'No library folders in the package.');
        $this->assertEmpty($this->deploy($contentonly));
        $this->assertFalse($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));

        // With the libraries on the site, the activity gets the content-only package, owned by the publisher.
        $this->install_greeting_card_library();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $package = $this->placed_package($placement);
        $this->assertEquals(get_admin()->id, $package->get_userid());
        $this->assertSame($entries, $this->zip_entries($package));
        $this->assertNotEmpty($this->deploy($package));
        $this->assertSame(1, $DB->count_records('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
    }

    /**
     * A version published without the library option whose libraries the site lacks cannot be placed: the
     * teacher gets a reason, the editorial view names the missing libraries (bug88).
     */
    public function test_place_refused_without_libraries(): void {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p');
        $version = version_manager::get((int) $item->currentversionid);
        $handler = new h5p();
        $reason = get_string('cannotplace_h5plibraries', 'local_nextpool', 'H5P.GreetingCard 1.0');
        $this->assertSame($reason, $handler->check_place($item, $version));
        $this->assertSame(['H5P.GreetingCard 1.0'], h5p::get_blocking_libraries($version));

        try {
            placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
            $this->fail('Placing must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_cannotplace', $e->errorcode);
            $this->assertStringContainsString($reason, $e->getMessage());
        }
        $this->assertSame(0, placement_manager::count_for_item((int) $item->id));
        $this->assertEmpty(get_fast_modinfo($course)->get_instances_of('h5pactivity'), 'No orphaned activity.');

        // The editorial view names the libraries.
        $PAGE->set_url('/local/nextpool/item.php', ['id' => $item->id]);
        $versions = fn() => (new \local_nextpool\output\item_page(\local_nextpool\local\item_manager::get((int) $item->id)))
            ->export_for_template($PAGE->get_renderer('core'))['versions'];
        $hint = fn() => array_column($versions(), 'h5plibraryhint', 'versionnr')[1];
        $this->assertSame(get_string('h5plibrariesmissing', 'local_nextpool', 'H5P.GreetingCard 1.0'), $hint());

        // A version with the library option may bring its libraries: it can be placed.
        $withoption = $this->generator->publish_new_version((int) $item->id, $this->changed_greeting_card(), 'card.h5p', true);
        $this->assertNull($handler->check_place($item, $withoption));
        $this->assertSame([], h5p::get_blocking_libraries($withoption));

        // A disabled library counts as missing, as core would not show the content (core_h5p\api::is_library_enabled()).
        $library = $this->install_greeting_card_library();
        $this->assertNull($handler->check_place($item, $version));
        $this->assertSame('', $hint());
        \core_h5p\api::set_library_enabled((int) $library->id, false);
        $this->assertSame($reason, $handler->check_place($item, $version));
        \core_h5p\api::set_library_enabled((int) $library->id, true);

        // Core needs the same minor version: a newer minor version of the library does not help.
        $this->assertSame(['H5P.GreetingCard 1.1'], h5p::get_missing_libraries(['preloadedDependencies' => [
            ['machineName' => 'H5P.GreetingCard', 'majorVersion' => 1, 'minorVersion' => 1],
        ]]));
    }

    /**
     * A version with the library option cannot be placed either if its publisher may not update H5P libraries:
     * core would deploy the package without installing them, as check_update() already knows (bug98).
     */
    public function test_place_refused_with_option_but_without_library_right(): void {
        $course = $this->getDataGenerator()->create_course();
        $publisher = $this->getDataGenerator()->create_user();
        $this->assertFalse(has_capability('moodle/h5p:updatelibraries', \context_system::instance(), $publisher));
        $this->setUser($publisher);
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p', true);
        $version = version_manager::get((int) $item->currentversionid);
        $this->assertTrue(h5p::allows_library_updates($version));
        $handler = new h5p();
        $reason = get_string('cannotplace_h5plibraries', 'local_nextpool', 'H5P.GreetingCard 1.0');
        $this->assertSame($reason, $handler->check_place($item, $version));

        $this->setAdminUser();
        try {
            placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
            $this->fail('Placing must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_cannotplace', $e->errorcode);
        }
        $this->assertEmpty(get_fast_modinfo($course)->get_instances_of('h5pactivity'), 'No orphaned activity.');

        // Once the site has the library, the version can be placed.
        $this->install_greeting_card_library();
        $this->assertNull($handler->check_place($item, $version));
    }

    /**
     * Install the library of the greeting card fixture as core does: from the full package of a person who may.
     *
     * @return \stdClass The library record.
     */
    private function install_greeting_card_library(): \stdClass {
        $path = make_request_directory() . '/greeting-card.h5p';
        file_put_contents($path, $this->fixture('greeting-card.h5p'));
        $file = \core_h5p\helper::create_fake_stored_file_from_path($path, (int) get_admin()->id);
        // Libraries only (skip content), as core's library upload does.
        \core_h5p\helper::save_h5p(new \core_h5p\factory(), $file, (object) [], false, true);
        return \core\di::get(\moodle_database::class)->get_record(
            'h5p_libraries',
            ['machinename' => 'H5P.GreetingCard'],
            '*',
            MUST_EXIST
        );
    }

    /**
     * With the option at publishing, the package keeps its libraries and core installs them; a content-only
     * package then deploys with the installed libraries (q39).
     */
    public function test_library_updates_with_option(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p', true);
        $version = version_manager::get((int) $item->currentversionid);
        $this->assertTrue(h5p::allows_library_updates($version));
        $this->assertSame(['H5P.GreetingCard 1.0.6'], h5p::get_library_changes(version_manager::get_file((int) $version->id)));

        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $package = $this->placed_package($placement);
        $this->assertSame(version_manager::get_file((int) $version->id)->get_contenthash(), $package->get_contenthash());
        $this->assertNotEmpty($this->deploy($package));
        $this->assertTrue($DB->record_exists('h5p_libraries', ['machinename' => 'H5P.GreetingCard']));
        $this->assertSame([], h5p::get_library_changes(version_manager::get_file((int) $version->id)));

        // A later version without the option: the libraries are there now, so the update goes through with content only.
        $cm = get_fast_modinfo($course)->get_cm((int) $placement->cmid);
        $version2 = $this->generator->publish_new_version((int) $item->id, $this->changed_greeting_card(), 'card.h5p');
        $this->assertNull((new h5p())->check_update($cm, $item, $version2));
        placement_manager::apply((int) $placement->id, (int) $version2->id);
        $updated = $this->placed_package($placement);
        $this->assertEmpty(preg_grep('~^H5P\.GreetingCard-1\.0/~', $this->zip_entries($updated)));
        $this->assertNotEmpty($this->deploy($updated));
    }

    /**
     * The review shows a hint when the package brings new or changed libraries; published versions say whether
     * library updates were allowed; set_status() stores the option (q39).
     */
    public function test_review_hint_and_stored_option(): void {
        global $PAGE;
        $item = $this->generator->create_published_item(['type' => 'h5p'], $this->fixture('greeting-card.h5p'), 'card.h5p');
        $this->generator->put_file(
            \local_nextpool\local\source\manager::get((int) $item->sourceid),
            'card.h5p',
            $this->changed_greeting_card()
        );
        $review = version_manager::create_from_source((int) $item->id, 'card.h5p');
        version_manager::set_status((int) $review->id, \local_nextpool\local\status::REVIEW);

        $PAGE->set_url('/local/nextpool/item.php', ['id' => $item->id]);
        $output = $PAGE->get_renderer('core');
        $export = fn() => array_column(
            (new \local_nextpool\output\item_page(\local_nextpool\local\item_manager::get((int) $item->id)))
                ->export_for_template($output)['versions'],
            'h5plibraryhint',
            'versionnr'
        );
        $hints = $export();
        $this->assertSame(get_string('h5plibraryreviewhint', 'local_nextpool', 'H5P.GreetingCard 1.0.6'), $hints[2]);
        $this->assertSame(
            get_string('h5plibrariesmissing', 'local_nextpool', 'H5P.GreetingCard 1.0'),
            $hints[1],
            'Published without the option and the library is missing: courses cannot place it (bug88).'
        );

        $published = version_manager::set_status((int) $review->id, \local_nextpool\local\status::PUBLISHED, 0, true);
        $this->assertSame(1, (int) $published->h5plibraries);
        $this->assertSame(get_string('h5plibrariesallowed', 'local_nextpool'), $export()[2]);
        $this->assertSame(0, (int) \core\di::get(\moodle_database::class)->get_field(
            'local_nextpool_version',
            'h5plibraries',
            ['itemid' => $item->id, 'versionnr' => 1]
        ));
    }
}
