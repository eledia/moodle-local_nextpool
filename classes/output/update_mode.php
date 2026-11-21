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

namespace local_nextpool\output;

use local_nextpool\local\item_types;

/**
 * How updates of an item reach the courses, with the one icon per mode (task106, task165).
 *
 * The icon of each mode is set here only, so the catalogue cards, the item page and the placements table (task175)
 * show the same icon for the same mode.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_mode {
    /** @var string The activity is updated in place; learner progress is kept. */
    public const INPLACE = 'inplace';

    /** @var string A new activity replaces the old one, which is hidden. */
    public const SOFTREPLACE = 'softreplace';

    /** @var array<string,string> Core icon of each mode: "keeps running" and "new copy". */
    public const ICONS = [
        self::INPLACE => 'i/reload',
        self::SOFTREPLACE => 't/copy',
    ];

    /**
     * Mode of a content type.
     *
     * @param string $type Item type.
     * @return string One of the constants.
     */
    public static function for_type(string $type): string {
        return item_types::supports_in_place($type) ? self::INPLACE : self::SOFTREPLACE;
    }

    /**
     * Decorative icon of a mode; the text comes from {@see self::label()} or {@see self::description()}.
     *
     * @param string $mode Mode.
     * @param \renderer_base $output Renderer.
     * @return string HTML
     */
    public static function icon(string $mode, \renderer_base $output): string {
        return $output->pix_icon(self::ICONS[$mode] ?? self::ICONS[self::SOFTREPLACE], '');
    }

    /**
     * Short name of a mode ("Direct", "Replace").
     *
     * @param string $mode Mode.
     * @return string
     */
    public static function short_name(string $mode): string {
        return get_string('updatemodeshort_' . self::valid($mode), 'local_nextpool');
    }

    /**
     * Accessible name with the heading: "Updates in courses: Direct".
     *
     * @param string $mode Mode.
     * @return string
     */
    public static function label(string $mode): string {
        return get_string('labelvalue', 'local_nextpool', [
            'label' => get_string('updatemode', 'local_nextpool'),
            'value' => self::short_name($mode),
        ]);
    }

    /**
     * Full sentence with the heading for the tooltip: "Updates in courses: In place (learner progress is kept)".
     *
     * @param string $mode Mode.
     * @return string
     */
    public static function description(string $mode): string {
        return get_string('labelvalue', 'local_nextpool', [
            'label' => get_string('updatemode', 'local_nextpool'),
            'value' => get_string('updatemode_' . self::valid($mode), 'local_nextpool'),
        ]);
    }

    /**
     * A known mode; unknown values count as "Replace", the cautious mode.
     *
     * @param string $mode Mode.
     * @return string
     */
    private static function valid(string $mode): string {
        return isset(self::ICONS[$mode]) ? $mode : self::SOFTREPLACE;
    }
}
