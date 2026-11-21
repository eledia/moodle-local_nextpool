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

/**
 * Tests for storage backend instance management (F03).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(manager::class)]
final class source_manager_test extends \advanced_testcase {
    /**
     * Both bundled backends are discovered.
     */
    public function test_available_plugins(): void {
        $plugins = manager::get_available_plugins();
        $this->assertArrayHasKey('moodle', $plugins);
        $this->assertArrayHasKey('nextcloud', $plugins);
        $this->assertNull(manager::get_class('../evil'));
        $this->assertNull(manager::get_class('doesnotexist'));
    }

    /**
     * Configuration is stored encrypted, filtered to declared keys and the base folder normalised.
     */
    public function test_create_encrypts_and_filters_config(): void {
        global $DB;
        $this->resetAfterTest();
        $source = manager::create('nextcloud', 'Cloud', [
            'authmode' => 'apppassword',
            'serverurl' => 'https://cloud.example.org',
            'username' => 'nextpool',
            'apppassword' => 'secret-app-password',
            'basefolder' => '/Catalogue//Shared/',
            'unexpected' => 'dropped',
        ]);

        $raw = $DB->get_field(manager::TABLE, 'config', ['id' => $source->get_id()]);
        $this->assertStringNotContainsString('secret-app-password', $raw);
        $this->assertStringNotContainsString('nextpool', $raw);

        $loaded = manager::get($source->get_id());
        $this->assertInstanceOf(\nextpoolsource_nextcloud\source::class, $loaded);
        $this->assertSame('Catalogue/Shared', $loaded->get_basefolder());
        $this->assertSame('secret-app-password', $loaded->get_config('apppassword'));
        $this->assertNull($loaded->get_config('unexpected'));

        // Secrets never go back into forms.
        $this->assertArrayNotHasKey('apppassword', manager::get_form_config($source->get_id()));
    }

    /**
     * An empty secret on update keeps the stored one; other values change.
     */
    public function test_update_keeps_secret(): void {
        $this->resetAfterTest();
        $source = manager::create('nextcloud', 'Cloud', [
            'authmode' => 'apppassword', 'serverurl' => 'https://a.example', 'username' => 'u', 'apppassword' => 'old',
        ]);
        manager::update($source->get_id(), 'Renamed', [
            'authmode' => 'apppassword', 'serverurl' => 'https://a.example', 'username' => 'v', 'apppassword' => '',
        ], true);

        $loaded = manager::get($source->get_id());
        $this->assertSame('Renamed', $loaded->get_name());
        $this->assertSame('v', $loaded->get_config('username'));
        $this->assertSame('old', $loaded->get_config('apppassword'));

        // The secret belongs to the server: a new server URL drops it (see nextpoolsource_nextcloud tests).
        manager::update($source->get_id(), 'Renamed', [
            'authmode' => 'apppassword', 'serverurl' => 'https://b.example', 'username' => 'v', 'apppassword' => '',
        ], true);
        $loaded = manager::get($source->get_id());
        $this->assertSame('https://b.example', $loaded->get_config('serverurl'));
        $this->assertSame('', (string) $loaded->get_config('apppassword', ''));
    }

    /**
     * Disabled instances can only be loaded explicitly.
     */
    public function test_disabled_source(): void {
        $this->resetAfterTest();
        $source = manager::create('moodle', 'Local', []);
        manager::set_enabled($source->get_id(), false);

        $this->assertFalse(manager::get($source->get_id(), false)->is_enabled());
        $this->expectException(source_exception::class);
        manager::get($source->get_id());
    }

    /**
     * Instances used by items cannot be deleted; unused ones can, including their Moodle files.
     */
    public function test_delete(): void {
        $this->resetAfterTest();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');

        $used = $generator->create_source();
        $generator->create_item(['sourceid' => $used->get_id()]);
        $this->assertFalse(manager::can_delete($used->get_id()));

        $unused = $generator->create_source();
        $generator->put_file($unused, 'a.txt', 'content');
        manager::delete($unused->get_id());
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'nextpoolsource_moodle',
            'content',
            $unused->get_id(),
            'id',
            false
        );
        $this->assertEmpty($files);

        $this->expectException(\moodle_exception::class);
        manager::delete($used->get_id());
    }
}
