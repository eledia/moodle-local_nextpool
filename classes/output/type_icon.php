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
 * Content type of an item as the activity icon of the module it becomes in a course (task166).
 *
 * Same picture as on the item page: the monologo of the module, coloured by its purpose like
 * Moodle's activity icons. The type name is always there as text for assistive technology and
 * as tooltip; on touch devices and in the stacked list the short name is shown beside the icon.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class type_icon {
    /**
     * Module whose icon stands for a content type, '' if none is installed.
     *
     * @param string $type Item type.
     * @return string Module name without prefix.
     */
    public static function get_modname(string $type): string {
        $modname = item_types::get_handler($type)?->get_modname() ?? ($type === 'xapi' ? 'nextpool' : '');
        if ($modname === '' || !\core_component::get_component_directory('mod_' . $modname)) {
            return '';
        }
        return $modname;
    }

    /**
     * Localised name of a content type.
     *
     * @param string $type Item type.
     * @param bool $short Short name (e.g. "File") instead of the full one (e.g. "File (video, PDF, document)").
     * @return string Plain text.
     */
    public static function get_name(string $type, bool $short = false): string {
        if (!item_types::is_valid($type)) {
            return $type;
        }
        return get_string(($short ? 'typeshort_' : 'type_') . $type, 'local_nextpool');
    }

    /**
     * The content type as a small activity icon with tooltip and hidden name, to put before a title.
     *
     * @param string $type Item type.
     * @return string HTML
     */
    public static function render(string $type): string {
        global $OUTPUT;
        $modname = self::get_modname($type);
        $purpose = $modname !== '' ? (string) plugin_supports('mod', $modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER) : '';
        $icon = $modname !== ''
            ? $OUTPUT->pix_icon('monologo', '', 'mod_' . $modname, ['class' => 'activityicon'])
            : $OUTPUT->pix_icon('i/backup', '', 'moodle', ['class' => 'activityicon']);
        $name = self::get_name($type);
        $container = \html_writer::span($icon, 'activityiconcontainer smaller ' . $purpose, ['aria-hidden' => 'true']);
        return \html_writer::span(
            $container .
            \html_writer::span(s(self::get_name($type, true)), 'local-nextpool-typelabel small', ['aria-hidden' => 'true']) .
            \html_writer::span(s($name), 'visually-hidden'),
            'local-nextpool-typeicon',
            ['title' => $name, 'data-bs-toggle' => 'tooltip', 'data-type' => $type]
        );
    }
}
