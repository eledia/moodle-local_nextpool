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

namespace local_lernhive_skills;

/**
 * Stand-in for the announced public API of LernHive Skills (feat28), for skills_bridge_test only.
 *
 * Loaded only when LernHive Skills is not installed.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var array[] Calls received, by argument name. */
    public static array $calls = [];

    /** @var string|null Competency whose call throws. */
    public static ?string $fail = null;

    /** @var string[] Sources Skills accepts; Skills refuses any other one (tag_error_source). */
    public static array $sources = ['manual', 'ai', 'contenthub', 'nextpool'];

    /**
     * Record a proposed level.
     *
     * @param int $cmid Course module id.
     * @param string $idnumber Competency idnumber.
     * @param int $level Level 1-5.
     * @param string $source manual, ai, contenthub or nextpool (q34).
     * @param string|null $itemuuid Nextpool item uuid.
     * @param string|null $function Didactic function.
     * @return array
     */
    public static function propose_unit_level(
        int $cmid,
        string $idnumber,
        int $level,
        string $source = 'manual',
        ?string $itemuuid = null,
        ?string $function = null
    ): array {
        if (!in_array($source, self::$sources, true)) {
            throw new \moodle_exception('tag_error_source', 'local_lernhive_skills');
        }
        if ($idnumber === self::$fail) {
            throw new \moodle_exception('nopermissions', 'error', '', 'tagcontent');
        }
        self::$calls[] = compact('cmid', 'idnumber', 'level', 'source', 'itemuuid', 'function');
        return [];
    }
}
