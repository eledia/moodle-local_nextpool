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

namespace nextpoolsource_nextcloud\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for nextpoolsource_nextcloud.
 *
 * Content files of catalogue items are exchanged with Nextcloud through a
 * system account. The metadata declares this external location, so it shows
 * in the data registry. The plugin stores no personal data in Moodle and sends
 * none of the acting user, so there is nothing to find, export or delete: the
 * request methods are deliberately empty (bug84). The people behind an item
 * (creator, authors, publisher) are covered by local_nextpool.
 *
 * Moodle only accepts a provider that declares metadata if it also implements
 * a request provider; a metadata provider alone fails the core privacy check.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('nextcloud', [
            'content' => 'privacy:metadata:nextcloud:content',
        ], 'privacy:metadata:nextcloud');
        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
    }
}
