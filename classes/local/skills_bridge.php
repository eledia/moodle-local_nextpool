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
 * Optional hand-over of the level per course module to LernHive Skills (F14, Skills feat28).
 *
 * Moodle core has no place for the level an activity leads to; LernHive Skills keeps it.
 * Nextpool works without Skills (P6): when the Skills plugin offers its public write API
 * (\local_lernhive_skills\api::propose_unit_level(), feat28, Skills 0.8.1), each competency
 * linked while placing is proposed there with its level, didactic function and the item's
 * uuid, with the source 'nextpool' (q34). Without that API nothing is called, and Skills can
 * read the same data from other['competencies'] of the placement events.
 *
 * Arguments are passed by name, so a changed parameter order in Skills cannot mix them up;
 * any failure is caught and reported, placing never fails because of Skills. A Skills release
 * that does not know the source 'nextpool' yet refuses each proposal (tag_error_source).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skills_bridge {
    /** @var string Public API class of LernHive Skills. */
    public const API = '\\local_lernhive_skills\\api';

    /** @var string Method that proposes a level for a course module and competency. */
    public const METHOD = 'propose_unit_level';

    /**
     * Whether LernHive Skills offers the public write API.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists(self::API) && method_exists(self::API, self::METHOD);
    }

    /**
     * Propose the level of every linked competency to Skills.
     *
     * Skills stores the proposals with status "proposed"; teachers confirm them in Skills.
     *
     * @param int $cmid Course module id.
     * @param string $itemuuid Item uuid.
     * @param array[] $results Results of item_competencies::link_to_cm().
     * @return int Number of levels proposed, or -1 if Skills does not offer the API.
     */
    public static function propose_levels(int $cmid, string $itemuuid, array $results): int {
        if (!self::is_available()) {
            return -1;
        }
        $callable = [self::API, self::METHOD];
        $count = 0;
        foreach ($results as $result) {
            if ($result['result'] !== item_competencies::LINKED) {
                continue;
            }
            try {
                $callable(...[
                    'cmid' => $cmid,
                    'idnumber' => (string) $result['competency'],
                    'level' => (int) $result['level'],
                    'source' => 'nextpool',
                    'itemuuid' => $itemuuid,
                    'function' => (string) $result['function'],
                ]);
                $count++;
            } catch (\Throwable $e) {
                debugging('local_nextpool: Skills level for ' . $result['competency'] . ' on cm ' . $cmid . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $count;
    }
}
