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
 * Install the five optional Core guided tours once (task177, q136).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tour_manager {
    /** @var string[] Stable identities, independent of editable tour names. */
    public const TOURS = ['catalogue', 'placements', 'add', 'items', 'item'];

    /**
     * Import missing initial tours without resetting administrative or learner choices.
     *
     * A marker survives deletion: a tour deliberately removed by an administrator is not resurrected.
     *
     * @return void
     */
    public static function install(): void {
        global $CFG, $DB;
        $transaction = $DB->start_delegated_transaction();
        foreach (self::TOURS as $key) {
            $setting = 'tour_installed_' . $key;
            // Read the durable marker directly so a retried transaction never trusts a rolled-back cache value.
            if ($DB->record_exists('config_plugins', ['plugin' => 'local_nextpool', 'name' => $setting])) {
                continue;
            }
            $name = 'tour_' . $key . '_name,local_nextpool';
            $existing = $DB->get_field('tool_usertours_tours', 'id', ['name' => $name]);
            if ($existing) {
                set_config($setting, $existing, 'local_nextpool');
                continue;
            }
            $json = file_get_contents($CFG->dirroot . '/local/nextpool/tours/' . $key . '.json');
            $tour = \tool_usertours\manager::import_tour_from_json($json);
            set_config($setting, $tour->get_id(), 'local_nextpool');
        }
        $transaction->allow_commit();
    }

    /**
     * Remove only the registered plugin tours when the plugin itself is uninstalled.
     *
     * @return void
     */
    public static function uninstall(): void {
        global $DB;
        foreach (self::TOURS as $key) {
            $setting = 'tour_installed_' . $key;
            $id = get_config('local_nextpool', $setting);
            if ($id && $DB->record_exists('tool_usertours_tours', ['id' => $id])) {
                \tool_usertours\tour::instance($id)->remove();
            }
            unset_config($setting, 'local_nextpool');
        }
    }

    /**
     * Add capability checks to the Core tour filtering pipeline.
     *
     * @param \tool_usertours\hook\before_serverside_filter_fetch $hook Core hook.
     */
    public static function add_filter(\tool_usertours\hook\before_serverside_filter_fetch $hook): void {
        $hook->add_filter_by_classname(\local_nextpool\usertours\audience_filter::class);
    }
}
