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

use local_nextpool\local\policy;

/**
 * Consistent placement-policy icons and an accessible Core action menu (task175, q135a).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_policy {
    /** @var array Policy => verified Core icon. */
    public const ICONS = [policy::AUTO => 'i/reload', policy::NOTIFY => 'i/notifications', policy::PINNED => 't/lock'];

    /**
     * Render a policy menu with working server-side links and optional AJAX enhancement.
     *
     * @param \stdClass $placement Placement.
     * @param string $rowname Plain activity name.
     * @param \moodle_url $pageurl Placement page.
     * @return string HTML.
     */
    public static function render(\stdClass $placement, string $rowname, \moodle_url $pageurl): string {
        global $OUTPUT;
        $menu = new \action_menu();
        $label = table_actions::name(policy::get_name($placement->policy), $rowname);
        $trigger = \html_writer::span(
            $OUTPUT->pix_icon(self::ICONS[$placement->policy], ''),
            '',
            ['data-region' => 'policy-current-icon']
        ) . \html_writer::span(
            s($label),
            'visually-hidden',
            ['data-region' => 'policy-current-label']
        );
        $menu->set_menu_trigger($trigger, 'btn btn-icon local-nextpool-iconaction');
        $menu->set_action_label($label);
        $menu->triggerattributes['data-region'] = 'policy-trigger';
        foreach (policy::all() as $value) {
            $url = new \moodle_url($pageurl, ['action' => 'policy', 'placementid' => $placement->id,
                'policy' => $value, 'sesskey' => sesskey()]);
            $current = $value === $placement->policy;
            $menu->add(new \action_menu_link_secondary($url, new \pix_icon(self::ICONS[$value], ''), policy::get_name($value), [
                'data-policy' => $value, 'aria-current' => $current ? 'true' : 'false',
                'class' => $current ? 'active' : '',
            ]));
        }
        $selectid = 'placement-policy-' . $placement->id;
        $fallback = \html_writer::label(
            s(table_actions::name(get_string('policy', 'local_nextpool'), $rowname)),
            $selectid
        );
        $params = $pageurl->params() + ['action' => 'policy', 'placementid' => $placement->id, 'sesskey' => sesskey()];
        foreach ($params as $key => $value) {
            $fallback .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        $fallback .= \html_writer::select(policy::get_menu(), 'policy', $placement->policy, false, ['id' => $selectid]);
        $fallback .= \html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
        $fallback = \html_writer::tag('noscript', \html_writer::tag('form', $fallback, [
            'method' => 'post', 'action' => $pageurl->out_omit_querystring(), 'class' => 'd-flex flex-wrap gap-2',
        ]));
        return \html_writer::div($OUTPUT->render($menu) . $fallback, 'local-nextpool-policycontrol', [
            'data-region' => 'policy-control', 'data-placementid' => $placement->id,
        ]);
    }
}
