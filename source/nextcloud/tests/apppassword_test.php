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

namespace nextpoolsource_nextcloud;

use local_nextpool\form\source_form;
use local_nextpool\local\source\manager;
use local_nextpool\local\source\source_exception;
use nextpoolsource_nextcloud\local\webdav_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests for the app-password mode of the Nextcloud backend (task23, adr16).
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(manager::class)]
final class apppassword_test extends \advanced_testcase {
    /** @var array Complete app-password configuration. */
    private const CONFIG = [
        'authmode' => source::AUTH_APPPASSWORD,
        'serverurl' => 'https://cloud.example.org/',
        'username' => ' nextpool ',
        'apppassword' => 'app-secret-123',
    ];

    /**
     * The client built from an app-password configuration authenticates with exactly these credentials.
     */
    public function test_client_from_app_password_config(): void {
        $this->resetAfterTest();
        $source = new source((object) ['id' => 1, 'plugin' => 'nextcloud', 'name' => 'Cloud', 'enabled' => 1], self::CONFIG);

        $client = (new \ReflectionMethod(source::class, 'create_client'))->invoke($source);

        $this->assertInstanceOf(webdav_client::class, $client);
        $this->assertSame('https://cloud.example.org', $this->read_property($client, 'serverurl'));
        $this->assertSame('nextpool', $this->read_property($client, 'username'));
        $this->assertSame('app-secret-123', $this->read_property($client, 'password'));
        // A plain curl: the Basic Auth header comes from the client, not from an OAuth 2 token.
        $this->assertNotInstanceOf(\core\oauth2\client::class, $this->read_property($client, 'curl'));
    }

    /**
     * The OAuth 2 mode never uses a stored app password; without an issuer it is not configured.
     */
    public function test_oauth2_mode_ignores_app_password(): void {
        $this->resetAfterTest();
        $source = new source(
            (object) ['id' => 1, 'plugin' => 'nextcloud', 'name' => 'Cloud', 'enabled' => 1],
            ['authmode' => source::AUTH_OAUTH2, 'issuerid' => 0] + self::CONFIG
        );

        $this->expectException(source_exception::class);
        $this->expectExceptionMessage(get_string('error_sourcenotconfigured', 'local_nextpool'));
        (new \ReflectionMethod(source::class, 'create_client'))->invoke($source);
    }

    /**
     * Server URL, user, password and warning show only for authmode=apppassword, the issuer only for OAuth 2.
     */
    public function test_fields_depend_on_auth_mode(): void {
        $mform = new \MoodleQuickForm('sourceform', 'post', '');
        source::add_config_elements($mform);

        $hideifs = $this->read_property($mform, '_hideifs');
        $this->assertEqualsCanonicalizing(
            ['apppasswordwarning', 'serverurl', 'username', 'apppassword'],
            $hideifs['authmode']['neq'][source::AUTH_APPPASSWORD]
        );
        $this->assertSame(['issuerid'], $hideifs['authmode']['neq'][source::AUTH_OAUTH2]);
        $this->assertSame('passwordunmask', $mform->getElement('apppassword')->getType());
    }

    /**
     * The edit form never shows the stored app password; saving it empty keeps it.
     */
    public function test_edit_form_hides_stored_password(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/sources.php');
        $instance = manager::create('nextcloud', 'Cloud', self::CONFIG);
        $id = $instance->get_id();

        // As in sources.php.
        $form = new source_form(null, [
            'plugin' => 'nextcloud',
            'class' => source::class,
            'existing' => manager::get_stored_config($id),
        ]);
        $form->set_data(['id' => $id, 'plugin' => 'nextcloud', 'name' => 'Cloud', 'enabled' => 1]
            + manager::get_form_config($id));
        $html = $form->render();

        $this->assertStringContainsString('name="apppassword"', $html);
        $this->assertStringNotContainsString('app-secret-123', $html);
        $this->assertStringContainsString('cloud.example.org', $html);

        manager::update($id, 'Cloud', ['apppassword' => ''] + self::CONFIG, true);
        $this->assertSame('app-secret-123', manager::get_stored_config($id)['apppassword']);
    }

    /**
     * Value of a non-public property.
     *
     * @param object $object Object.
     * @param string $name Property name.
     * @return mixed
     */
    private function read_property(object $object, string $name): mixed {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
