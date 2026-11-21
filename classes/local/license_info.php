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
 * Licence of a catalogue item as shown to people (task169).
 *
 * A missing licence always means "All rights reserved", never "Licence not specified" (PO, 2026-11-15). The
 * fallback is display only (q133): stored records keep their value. Item page, catalogue cards and mod_nextpool
 * read the licence through this class so that the rule lives in one place.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class license_info {
    /** @var string Core short name of "All rights reserved". */
    public const ALL_RIGHTS_RESERVED = 'allrightsreserved';

    /** @var string Core short name of "Licence not specified", treated like a missing licence. */
    private const UNKNOWN = 'unknown';

    /**
     * Short name of the licence that applies: "All rights reserved" when none is set or it is "unknown".
     *
     * @param string|null $shortname Stored licence short name.
     * @return string
     */
    public static function effective(?string $shortname): string {
        $shortname = trim((string) $shortname);
        return $shortname === '' || $shortname === self::UNKNOWN ? self::ALL_RIGHTS_RESERVED : $shortname;
    }

    /**
     * Licence preset for a new item: the site default, or "All rights reserved" when it is not set or "unknown".
     *
     * Only for new records; stored items keep their value (q133).
     *
     * @return string Licence short name.
     */
    public static function default_for_new(): string {
        global $CFG;
        return self::effective($CFG->sitedefaultlicense ?? '');
    }

    /**
     * Whether the licence that applies is "All rights reserved" (shown as © icon only).
     *
     * @param string|null $shortname Stored licence short name.
     * @return bool
     */
    public static function is_all_rights_reserved(?string $shortname): bool {
        return self::effective($shortname) === self::ALL_RIGHTS_RESERVED;
    }

    /**
     * Full name of the licence that applies, as plain text.
     *
     * @param string|null $shortname Stored licence short name.
     * @return string
     */
    public static function full_name(?string $shortname): string {
        global $CFG;
        require_once($CFG->libdir . '/licenselib.php');
        $effective = self::effective($shortname);
        $license = \license_manager::get_license_by_shortname($effective);
        return $license ? html_entity_decode($license->fullname, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $effective;
    }

    /**
     * Short name for a pill: "CC BY-SA 4.0" for Creative Commons licences, the full name otherwise.
     *
     * @param string|null $shortname Stored licence short name.
     * @return string
     */
    public static function short_name(?string $shortname): string {
        $effective = self::effective($shortname);
        if (preg_match('/^cc-(?:(nc-nd|nc-sa|nc|nd|sa)-)?(\d\.\d)$/', $effective, $m)) {
            return 'CC BY' . ($m[1] !== '' ? '-' . strtoupper($m[1]) : '') . ' ' . $m[2];
        }
        return self::full_name($shortname);
    }
}
