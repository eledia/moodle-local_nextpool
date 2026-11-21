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

namespace local_nextpool\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\output\table_actions;

/**
 * Change one placement policy with the same checks as the course page (task175).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_placement_policy extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'placementid' => new external_value(PARAM_INT, 'Placement id'),
            'policy' => new external_value(PARAM_ALPHA, 'auto, notify or pinned'),
        ]);
    }

    /**
     * Change the rule after course access and management permission have been checked.
     *
     * @param int $placementid Placement id.
     * @param string $policy Requested policy.
     * @return array Policy and accessible labels.
     */
    public static function execute(int $placementid, string $policy): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('placementid', 'policy'));
        $placement = placement_manager::get($params['placementid']);
        $context = \context_module::instance($placement->cmid);
        self::validate_context($context);
        require_capability('local/nextpool:viewcatalogue', $context->get_course_context());
        require_capability('moodle/course:manageactivities', $context->get_course_context());
        require_capability('moodle/course:manageactivities', $context);
        placement_manager::set_policy((int) $placement->id, $params['policy']);
        $cm = get_fast_modinfo($placement->courseid)->get_cm($placement->cmid);
        $label = policy::get_name($params['policy']);
        $rowname = html_entity_decode(strip_tags($cm->get_formatted_name()), ENT_QUOTES | ENT_HTML5);
        $overview = placement_manager::get_overview((int) $placement->courseid);
        $pageurl = new \moodle_url('/local/nextpool/placements.php', ['courseid' => $placement->courseid]);
        return [
            'policy' => $params['policy'], 'label' => $label,
            'name' => table_actions::name(
                $label,
                html_entity_decode(strip_tags($cm->get_formatted_name()), ENT_QUOTES | ENT_HTML5)
            ),
            'message' => get_string('policychanged', 'local_nextpool'),
            'statushtml' => \local_nextpool\output\placement_status::render($overview[$placement->id], $rowname, $pageurl, true),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'policy' => new external_value(PARAM_ALPHA, 'Applied policy'),
            'label' => new external_value(PARAM_TEXT, 'Policy label'),
            'name' => new external_value(PARAM_TEXT, 'Accessible control name'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
            'statushtml' => new external_value(PARAM_RAW, 'Rendered current update status and actions'),
        ]);
    }
}
