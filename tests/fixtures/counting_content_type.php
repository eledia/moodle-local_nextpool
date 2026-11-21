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

namespace local_nextpool\fixtures;

/**
 * URL handler that counts update checks and can make the placement row fail, for update_engine_test only.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class counting_content_type extends \local_nextpool\local\content_type\url {
    /** @var int Calls of check_update(). */
    public static int $checks = 0;
    /** @var bool Whether place() leaves a placement row for its course module, so the catalogue's own row fails. */
    public static bool $conflict = false;

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $DB;
        $cmid = parent::place($item, $version, $course, $section, $options);
        if (self::$conflict) {
            $DB->insert_record(\local_nextpool\local\placement_manager::TABLE, (object) [
                'itemid' => $item->id, 'courseid' => $course->id, 'cmid' => $cmid, 'policy' => 'notify',
                'appliedversionid' => $version->id, 'placedby' => 0, 'timeplaced' => 0, 'timeupdated' => 0,
            ]);
        }
        return $cmid;
    }

    #[\Override]
    public function check_update(\cm_info $cm, \stdClass $item, \stdClass $version): ?string {
        self::$checks++;
        return parent::check_update($cm, $item, $version);
    }
}
