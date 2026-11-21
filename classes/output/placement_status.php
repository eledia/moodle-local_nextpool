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
use local_nextpool\local\placement_manager;
use local_nextpool\local\status;

/**
 * One placement's current update status, shared by the page and its AJAX response (task175).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class placement_status {
    /**
     * Render the current status and permitted update actions.
     *
     * @param \stdClass $placement Overview record including version numbers and item type.
     * @param string $rowname Activity name.
     * @param \moodle_url $pageurl Course placement page.
     * @param bool $canmanage Whether update actions are permitted.
     * @return string HTML.
     */
    public static function render(\stdClass $placement, string $rowname, \moodle_url $pageurl, bool $canmanage): string {
        $applied = (int) $placement->appliedversionnr;
        $current = (int) $placement->currentversionnr;
        $button = static function (array $params, string $label, string $name) use ($pageurl): string {
            return table_actions::button(new \moodle_url($pageurl, $params), $label, $name);
        };
        $statuscell = '';
        if ($placement->pendingversionid) {
            $pending = (int) $placement->pendingversionnr;
            $statuscell .= \html_writer::div(
                status::text_badge(get_string('updateoffered', 'local_nextpool', $pending), 'bg-warning text-dark'),
                'mb-1'
            );
            $reason = placement_manager::get_downgrade_reason($placement);
            if ($reason !== null) {
                $statuscell .= \html_writer::div(
                    get_string('updatedowngraded', 'local_nextpool', s($reason)),
                    'small text-warning-emphasis mb-1'
                );
            }
            if ($canmanage) {
                $applyparams = ['action' => 'apply', 'placementid' => $placement->id, 'versionid' => $placement->pendingversionid];
                $skipparams = ['action' => 'skip', 'placementid' => $placement->id];
                // A version that cannot be written into the activity can become a new activity next to it (q29).
                $alongside = '';
                if ($reason !== null && item_types::get_handler((string) $placement->itemtype)?->can_place_alongside()) {
                    $alongsideparams = ['action' => 'alongside', 'placementid' => $placement->id,
                        'versionid' => $placement->pendingversionid];
                    $alongside = $button($alongsideparams, get_string('placealongside', 'local_nextpool', $pending), $rowname);
                }
                $statuscell .= \html_writer::div(
                    $button($applyparams, get_string('applyversion', 'local_nextpool', $pending), $rowname) .
                    $alongside .
                    $button($skipparams, get_string('skipversion', 'local_nextpool', $pending), $rowname),
                    'd-flex flex-wrap gap-2'
                );
            }
        } else if ($current > $applied) {
            $statuscell .= \html_writer::div(
                status::text_badge(get_string('neweravailable', 'local_nextpool', $current), 'bg-light text-dark border'),
                'mb-1'
            );
            if ($canmanage) {
                $statuscell .= $button(
                    ['action' => 'apply', 'placementid' => $placement->id, 'versionid' => $placement->currentversionid],
                    get_string('applyversion', 'local_nextpool', $current),
                    $rowname
                );
            }
        } else {
            $statuscell = status::text_badge(get_string('uptodate', 'local_nextpool'), 'bg-success');
        }
        return $statuscell;
    }
}
