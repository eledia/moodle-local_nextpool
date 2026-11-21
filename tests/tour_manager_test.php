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

use local_nextpool\local\tour_manager;
use tool_usertours\tour;

/**
 * Guided tour installation preserves administrator and learner choices (task177, q136).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(tour_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_nextpool_install')]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_nextpool_upgrade')]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_nextpool_uninstall')]
final class tour_manager_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (tour_manager::TOURS as $key) {
            $id = get_config('local_nextpool', 'tour_installed_' . $key);
            if ($id && $DB->record_exists('tool_usertours_tours', ['id' => $id])) {
                tour::instance($id)->remove();
            }
            unset_config('tour_installed_' . $key, 'local_nextpool');
        }
    }

    /**
     * Installation imports five Core tours with stable targets and translated steps.
     */
    public function test_install_creates_five_translatable_tours(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/nextpool/db/install.php');
        xmldb_local_nextpool_install();
        $translations = [];
        foreach (['en', 'de'] as $language) {
            $translations[$language] = (static function (string $path): array {
                $string = [];
                require($path);
                return $string;
            })($CFG->dirroot . '/local/nextpool/lang/' . $language . '/local_nextpool.php');
        }
        foreach (tour_manager::TOURS as $key) {
            $tour = tour::instance(get_config('local_nextpool', 'tour_installed_' . $key));
            $this->assertTrue($tour->is_enabled());
            $this->assertGreaterThanOrEqual(3, count($tour->get_steps()));
            $this->assertLessThanOrEqual(6, count($tour->get_steps()));
            $this->assertStringStartsWith('/', $tour->get_pathmatch());
            foreach (
                array_merge([$tour->get_name(), $tour->get_description()], array_merge(...array_map(
                    fn($step) => [$step->get_title(), $step->get_content()],
                    $tour->get_steps()
                ))) as $reference
            ) {
                [$identifier, $component] = explode(',', $reference);
                foreach (['en', 'de'] as $language) {
                    // The isolated Core has only English installed; verify both bundled dictionaries without fallback.
                    $this->assertArrayHasKey($identifier, $translations[$language]);
                    $this->assertNotEmpty($translations[$language][$identifier]);
                }
                $this->assertNotSame(
                    $translations['en'][$identifier],
                    $translations['de'][$identifier]
                );
                $this->assertSame($translations['en'][$identifier], \tool_usertours\helper::get_string_from_input($reference));
            }
            foreach ($tour->get_steps() as $step) {
                $this->assertMatchesRegularExpression('/^\[data-(tour|region)="[a-z-]+"\]$/', $step->get_targetvalue());
            }
        }
    }

    /**
     * Repeated installation keeps disabled, renamed, edited and completed tours untouched.
     */
    public function test_repeated_install_preserves_choices(): void {
        global $DB;
        tour_manager::install();
        $id = (int) get_config('local_nextpool', 'tour_installed_catalogue');
        $tour = tour::instance($id);
        $tour->set_name('Our catalogue help')->set_enabled(false)->persist();
        $steps = $tour->get_steps();
        $step = reset($steps);
        $step->set_content('Locally adjusted help')->persist();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference(tour::TOUR_LAST_COMPLETED_BY_USER . $id, 123456, $user);
        $count = $DB->count_records('tool_usertours_tours');
        tour_manager::install();
        $this->assertSame($count, $DB->count_records('tool_usertours_tours'));
        $this->assertEquals($id, get_config('local_nextpool', 'tour_installed_catalogue'));
        $tour = tour::instance($id);
        $this->assertFalse($tour->is_enabled());
        $this->assertSame('Our catalogue help', $tour->get_name());
        $steps = $tour->get_steps();
        $this->assertSame('Locally adjusted help', reset($steps)->get_content());
        $this->assertEquals(123456, get_user_preferences(tour::TOUR_LAST_COMPLETED_BY_USER . $id, null, $user));
    }

    /**
     * An administrator's deletion is retained on later upgrades.
     */
    public function test_deleted_tour_is_not_recreated(): void {
        global $DB;
        tour_manager::install();
        $id = get_config('local_nextpool', 'tour_installed_catalogue');
        tour::instance($id)->remove();
        tour_manager::install();
        $this->assertFalse($DB->record_exists('tool_usertours_tours', ['id' => $id]));
        $this->assertFalse($DB->record_exists('tool_usertours_tours', ['name' => 'tour_catalogue_name,local_nextpool']));
        $this->assertEquals($id, get_config('local_nextpool', 'tour_installed_catalogue'));
    }

    /**
     * An existing imported tour can be adopted without a duplicate or reset.
     */
    public function test_existing_import_is_adopted(): void {
        global $CFG, $DB;
        $json = file_get_contents($CFG->dirroot . '/local/nextpool/tours/catalogue.json');
        $tour = \tool_usertours\manager::import_tour_from_json($json);
        $tour->set_enabled(false)->persist();
        tour_manager::install();
        $this->assertEquals($tour->get_id(), get_config('local_nextpool', 'tour_installed_catalogue'));
        $this->assertFalse(tour::instance($tour->get_id())->is_enabled());
        $this->assertEquals(1, $DB->count_records('tool_usertours_tours', ['name' => $tour->get_name()]));
    }

    /**
     * The upgrade registers tours, while a retry leaves the saved settings intact.
     */
    public function test_upgrade_registers_once(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/nextpool/db/upgrade.php');
        set_config('version', 2026111590, 'local_nextpool');
        $this->assertTrue(xmldb_local_nextpool_upgrade(2026111590));
        $id = get_config('local_nextpool', 'tour_installed_catalogue');
        $this->assertNotFalse($id);
        tour::instance($id)->set_enabled(false)->persist();
        set_config('version', 2026111590, 'local_nextpool');
        $this->assertTrue(xmldb_local_nextpool_upgrade(2026111590));
        $this->assertEquals($id, get_config('local_nextpool', 'tour_installed_catalogue'));
        $this->assertFalse(tour::instance($id)->is_enabled());
    }
    /**
     * Uninstalling removes our tours but retains independently configured Core tours.
     */
    public function test_uninstall_leaves_unrelated_tours(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/nextpool/db/uninstall.php');
        tour_manager::install();
        $json = json_decode(file_get_contents($CFG->dirroot . '/local/nextpool/tours/catalogue.json'));
        $json->name = 'Independent tour';
        $independent = \tool_usertours\manager::import_tour_from_json(json_encode($json));
        $count = $DB->count_records('tool_usertours_tours');
        xmldb_local_nextpool_uninstall();
        $this->assertSame($count - 5, $DB->count_records('tool_usertours_tours'));
        $this->assertTrue($DB->record_exists('tool_usertours_tours', ['id' => $independent->get_id()]));
        foreach (tour_manager::TOURS as $key) {
            $this->assertFalse(get_config('local_nextpool', 'tour_installed_' . $key));
        }
    }
}
