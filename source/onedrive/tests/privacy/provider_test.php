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

namespace nextpoolsource_onedrive\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\manager;
use core_privacy\tests\provider_testcase;

/**
 * Tests for the privacy provider of nextpoolsource_onedrive (bug84).
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /**
     * The component passes the core privacy compliance check (metadata plus request provider).
     */
    public function test_component_is_compliant(): void {
        $manager = new manager();
        $this->assertTrue($manager->component_is_compliant('nextpoolsource_onedrive'));
        $this->assertTrue(is_subclass_of(provider::class, \core_privacy\local\request\core_userlist_provider::class));
    }

    /**
     * The external location stays declared, with defined strings.
     */
    public function test_metadata_declares_microsoft365(): void {
        $collection = provider::get_metadata(new collection('nextpoolsource_onedrive'));
        $items = $collection->get_collection();
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertInstanceOf(external_location::class, $item);
        $this->assertSame('microsoft365', $item->get_name());
        $this->assertSame(['content' => 'privacy:metadata:microsoft365:content'], $item->get_privacy_fields());
        $this->assertNotEmpty(get_string($item->get_summary(), 'nextpoolsource_onedrive'));
    }

    /**
     * No user data is found, exported or deleted, and nothing fails.
     */
    public function test_requests_find_nothing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->assertEmpty(provider::get_contexts_for_userid((int) $user->id)->get_contextids());

        $userlist = new userlist($context, 'nextpoolsource_onedrive');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids());

        $approved = new approved_contextlist($user, 'nextpoolsource_onedrive', [$context->id]);
        provider::export_user_data($approved);
        $this->assertFalse(writer::with_context($context)->has_any_data());

        provider::delete_data_for_user($approved);
        provider::delete_data_for_users(new approved_userlist($context, 'nextpoolsource_onedrive', [(int) $user->id]));
        provider::delete_data_for_all_users_in_context($context);
    }
}
