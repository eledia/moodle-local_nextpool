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

namespace local_nextpool\local;

/**
 * Update policy of a placement (F05, F06).
 *
 * - auto: a newly published version is applied to the course module right away.
 * - notify: the teacher is told and applies the update when ready.
 * - pinned: the placement stays on its version.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class policy {
    /** @var string Apply new versions automatically. */
    public const AUTO = 'auto';
    /** @var string Ask the teacher. */
    public const NOTIFY = 'notify';
    /** @var string Keep the current version. */
    public const PINNED = 'pinned';

    /**
     * All policies.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::AUTO, self::NOTIFY, self::PINNED];
    }

    /**
     * Whether a policy exists.
     *
     * @param string $policy Policy.
     * @return bool
     */
    public static function is_valid(string $policy): bool {
        return in_array($policy, self::all(), true);
    }

    /**
     * Localised name.
     *
     * @param string $policy Policy.
     * @return string
     */
    public static function get_name(string $policy): string {
        return get_string('policy_' . $policy, 'local_nextpool');
    }

    /**
     * Options for a select element.
     *
     * @return array<string,string>
     */
    public static function get_menu(): array {
        $menu = [];
        foreach (self::all() as $policy) {
            $menu[$policy] = self::get_name($policy);
        }
        return $menu;
    }
}
