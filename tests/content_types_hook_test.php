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

use local_nextpool\fixtures\test_content_type;
use local_nextpool\form\place_form;
use local_nextpool\hook\collect_content_types;
use local_nextpool\local\content_type\file;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/test_content_type.php');

/**
 * Tests for content type handlers of other plugins and placement options (task45).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(collect_content_types::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_types::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(placement_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(place_form::class)]
final class content_types_hook_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        item_types::reset_cache();
        test_content_type::$placeoptions = [];
    }

    #[\Override]
    protected function tearDown(): void {
        item_types::reset_cache();
        parent::tearDown();
    }

    /**
     * Register handlers through the hook for this test.
     *
     * @param array $handlers Handler class by type.
     */
    private function register(array $handlers): void {
        $this->redirectHook(collect_content_types::class, function (collect_content_types $hook) use ($handlers): void {
            foreach ($handlers as $type => $class) {
                $hook->add($type, $class);
            }
        });
        item_types::reset_cache();
    }

    /**
     * Without a plugin handler, xapi cannot be placed; with one, it can, and the menu says so.
     */
    public function test_plugin_handler_makes_type_placeable(): void {
        $this->resetAfterTest();
        $this->register([]);
        $this->assertFalse(item_types::is_placeable('xapi'));
        $this->assertNull(item_types::get_handler('xapi'));
        $name = get_string('type_xapi', 'local_nextpool');
        $this->assertSame(get_string('type_noplacement', 'local_nextpool', $name), item_types::get_menu()['xapi']);

        $this->register(['xapi' => test_content_type::class]);
        $this->assertTrue(item_types::is_placeable('xapi'));
        $this->assertInstanceOf(test_content_type::class, item_types::get_handler('xapi'));
        $this->assertSame($name, item_types::get_menu()['xapi']);
        // Built-in handlers stay as they are.
        $this->assertInstanceOf(file::class, item_types::get_handler('file'));
    }

    /**
     * The default type is 'file' and can be placed, also when plugins add handlers (bug27, bug61).
     */
    public function test_default_type_is_file(): void {
        $this->resetAfterTest();
        $this->register([]);
        $this->assertSame('file', item_types::get_default());
        $this->assertTrue(item_types::is_placeable(item_types::get_default()));
        $this->register(['quiz' => test_content_type::class]);
        $this->assertSame('file', item_types::get_default());
    }

    /**
     * Unknown types, built-in types, classes that are no handler and second registrations are ignored.
     */
    public function test_invalid_registrations_are_ignored(): void {
        $this->resetAfterTest();
        $hook = new collect_content_types();
        $hook->add('nonsense', test_content_type::class);
        $this->assertDebuggingCalled();
        $hook->add('file', test_content_type::class);
        $this->assertDebuggingCalled();
        $hook->add('xapi', \stdClass::class);
        $this->assertDebuggingCalled();
        $hook->add('xapi', '\\local_nextpool\\does_not_exist');
        $this->assertDebuggingCalled();
        $hook->add('xapi', test_content_type::class);
        $hook->add('xapi', file::class);
        $this->assertDebuggingCalled();
        $this->assertSame(['xapi' => test_content_type::class], $hook->get_handlers());

        // A plugin cannot replace a built-in handler.
        $this->register(['file' => test_content_type::class]);
        $handler = item_types::get_handler('file');
        $this->assertDebuggingNotCalled();
        $this->assertInstanceOf(file::class, $handler);
        $this->assertNotInstanceOf(test_content_type::class, $handler);
    }

    /**
     * The hook runs once per request until the cache is reset.
     */
    public function test_handlers_are_collected_once(): void {
        $this->resetAfterTest();
        $calls = 0;
        $this->redirectHook(collect_content_types::class, function (collect_content_types $hook) use (&$calls): void {
            $calls++;
            $hook->add('xapi', test_content_type::class);
        });
        item_types::reset_cache();
        item_types::is_placeable('xapi');
        item_types::get_handler('xapi');
        item_types::get_menu();
        $this->assertSame(1, $calls);
        // Built-in types do not need the hook at all.
        item_types::reset_cache();
        item_types::get_handler('file');
        $this->assertSame(1, $calls);
        item_types::get_handler('xapi');
        $this->assertSame(2, $calls);
    }

    /**
     * Fields of the handler appear in the placement form, and their values reach the handler's place().
     */
    public function test_place_options_reach_handler(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->register(['xapi' => test_content_type::class]);
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $course = $this->getDataGenerator()->create_course();
        $item = $generator->create_published_item(['type' => 'xapi'], "https://example.com/package\n", 'link.txt');
        $handler = item_types::get_handler('xapi');

        $customdata = ['courseid' => $course->id, 'itemid' => $item->id, 'sections' => [0 => 'General'], 'handler' => $handler];
        place_form::mock_submit([
            'courseid' => $course->id,
            'itemid' => $item->id,
            'section' => 0,
            'policy' => policy::NOTIFY,
            'testdisplay' => 'popup',
        ]);
        $form = new place_form(null, $customdata);
        $this->assertStringContainsString('name="testdisplay"', $form->render());
        $data = $form->get_data();
        $this->assertNotNull($data);
        $options = $handler->get_place_options($data);
        $this->assertSame(['display' => 'popup'], $options);

        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY, 0, $options);
        $this->assertSame([['display' => 'popup']], test_content_type::$placeoptions);
        $this->assertSame('url', get_fast_modinfo($course)->get_cm((int) $placement->cmid)->modname);

        // Without options the handler gets none; built-in handlers add no form fields.
        placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $this->assertSame([], test_content_type::$placeoptions[1]);
        $plain = new place_form(null, ['courseid' => $course->id, 'itemid' => $item->id, 'sections' => [0 => 'General'],
            'handler' => item_types::get_handler('file')]);
        $this->assertStringNotContainsString('testdisplay', $plain->render());
        $this->assertSame([], item_types::get_handler('file')->get_place_options((object) ['testdisplay' => 'popup']));
    }

    /**
     * A placement can be found by its course module.
     */
    public function test_get_by_cmid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $course = $this->getDataGenerator()->create_course();
        $item = $generator->create_published_item(['type' => 'url'], "https://example.com/\n", 'link.txt');
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);

        $found = placement_manager::get_by_cmid((int) $placement->cmid);
        $this->assertNotNull($found);
        $this->assertEquals($placement->id, $found->id);

        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->assertNull(placement_manager::get_by_cmid((int) $other->cmid));
        $this->assertNull(placement_manager::get_by_cmid(0));
    }
}
