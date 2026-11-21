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
 * Where editors can see the content of a catalogue item ("View" in the editorial lists).
 *
 * The catalogue itself has no player: packages run in the course module they are placed in,
 * and snapshots are only served as downloads. So a link item opens its address, and every
 * other item opens the newest placement the user can see. Without one there is nothing to show.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_viewer {
    /**
     * Page that shows the content of an item to the current user.
     *
     * @param \stdClass $item Item record.
     * @return \moodle_url|null Null if there is nothing the user can open.
     */
    public static function get_url(\stdClass $item): ?\moodle_url {
        global $DB;
        if ($item->type === 'url') {
            return self::get_link_target($item);
        }
        $placements = $DB->get_records(placement_manager::TABLE, ['itemid' => $item->id], 'timeplaced DESC, id DESC');
        foreach ($placements as $placement) {
            $cm = placement_manager::get_cm($placement);
            if ($cm && $cm->url && $cm->uservisible && can_access_course($cm->get_course(), null, '', true)) {
                return $cm->url;
            }
        }
        return null;
    }

    /**
     * Address of a link item, from its current version or else its newest one.
     *
     * @param \stdClass $item Item record of type url.
     * @return \moodle_url|null
     */
    private static function get_link_target(\stdClass $item): ?\moodle_url {
        $versionid = (int) $item->currentversionid;
        if (!$versionid) {
            $versions = version_manager::get_versions((int) $item->id);
            $versionid = $versions ? (int) reset($versions)->id : 0;
        }
        $file = $versionid ? version_manager::get_file($versionid) : null;
        if (!$file) {
            return null;
        }
        try {
            return new \moodle_url(content_type\url::extract_url($file->get_content()));
        } catch (\moodle_exception $e) {
            return null;
        }
    }
}
