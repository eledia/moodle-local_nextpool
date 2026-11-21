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
 * Allowlisted catalogue filters carried through preview and feedback (F16 p176-l).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalogue_return {
    /** @var array Filter name => cleaning type. */
    public const FILTERS = [
        'search' => PARAM_TEXT, 'category' => PARAM_INT, 'type' => PARAM_ALPHA,
        'tag' => PARAM_TAG, 'page' => PARAM_INT, 'section' => PARAM_INT,
    ];

    /**
     * Read only supported filters, never a return URL.
     *
     * @param array|null $values Fragment data, null to read request parameters.
     * @return array
     */
    public static function read(?array $values = null): array {
        $filters = [];
        foreach (self::FILTERS as $name => $type) {
            if ($values !== null && !array_key_exists($name, $values)) {
                continue;
            }
            $value = $values === null ? optional_param($name, '', $type) : clean_param($values[$name] ?? '', $type);
            if ($value !== '' && $value !== null) {
                $filters[$name] = $value;
            }
        }
        return $filters;
    }

    /**
     * URL back to an item's catalogue card.
     *
     * @param int $courseid Course id.
     * @param int $itemid Item id, 0 for no anchor.
     * @param array $filters Filters.
     * @return \moodle_url
     */
    public static function url(int $courseid, int $itemid = 0, array $filters = []): \moodle_url {
        $url = new \moodle_url('/local/nextpool/catalogue.php', ['courseid' => $courseid] + self::read($filters));
        if ($itemid) {
            $url->set_anchor('local-nextpool-item-' . $itemid);
        }
        return $url;
    }
}
