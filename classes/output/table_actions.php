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

/**
 * Row headers and row actions for the admin and course tables.
 *
 * Actions that change something are POST buttons with sesskey; every action names its row
 * for assistive technology, so "Edit", "Delete" and so on are unique on the page (bug66, bug67).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class table_actions {
    /**
     * First cell of a row as row header.
     *
     * @param string $html Cell content (HTML).
     * @return \html_table_cell
     */
    public static function row_header(string $html): \html_table_cell {
        $cell = new \html_table_cell($html);
        $cell->header = true;
        $cell->scope = 'row';
        return $cell;
    }

    /**
     * Accessible name of an action in a row: the visible label first (WCAG 2.5.3), then the row.
     *
     * @param string $label Visible label.
     * @param string $rowname Name of the row (plain text).
     * @return string
     */
    public static function name(string $label, string $rowname): string {
        return get_string('actionfor', 'local_nextpool', (object) ['action' => $label, 'name' => $rowname]);
    }

    /**
     * Link to a page (no change on click).
     *
     * @param \moodle_url $url Target.
     * @param string $label Visible label.
     * @param string $rowname Name of the row (plain text).
     * @return string HTML
     */
    public static function link(\moodle_url $url, string $label, string $rowname): string {
        return \html_writer::link($url, $label, ['aria-label' => self::name($label, $rowname)]);
    }

    /**
     * POST button that changes something; the sesskey is added by single_button.
     *
     * @param \moodle_url $url Target with the action parameters.
     * @param string $label Visible label.
     * @param string $rowname Name of the row (plain text).
     * @param string $type Button type, e.g. \single_button::BUTTON_DANGER.
     * @return string HTML
     */
    public static function button(
        \moodle_url $url,
        string $label,
        string $rowname,
        string $type = \single_button::BUTTON_SECONDARY
    ): string {
        global $OUTPUT;
        $button = new \single_button($url, $label, 'post', $type, ['aria-label' => self::name($label, $rowname)]);
        $button->class = 'singlebutton d-inline-block me-1 mb-1';
        return $OUTPUT->render($button);
    }
    /**
     * Icon-only link to a page (no change on click), with a tooltip and a hidden text as its accessible name.
     *
     * @param \moodle_url $url Target.
     * @param string $icon Core pix icon, e.g. 't/edit'.
     * @param string $label Short label, shown as tooltip.
     * @param string $rowname Name of the row (plain text), or '' outside a table.
     * @param array $attributes Extra attributes, e.g. target.
     * @return string HTML
     */
    public static function icon_link(
        \moodle_url $url,
        string $icon,
        string $label,
        string $rowname = '',
        array $attributes = []
    ): string {
        global $OUTPUT;
        $name = $rowname !== '' ? self::name($label, $rowname) : $label;
        if (($attributes['target'] ?? '') === '_blank') {
            // Say that the page opens elsewhere; the tooltip stays short.
            $name = get_string('opensinnewwindow', 'local_nextpool', $name);
            $attributes['rel'] = 'noopener';
        }
        $content = $OUTPUT->pix_icon($icon, '') . \html_writer::span(s($name), 'visually-hidden');
        return \html_writer::link($url, $content, $attributes + [
            'class' => 'btn btn-icon local-nextpool-iconaction',
            'title' => $label,
            'data-bs-toggle' => 'tooltip',
        ]);
    }

    /**
     * Icon-only button: a POST that changes something (carries the sesskey), or a GET that opens a page.
     *
     * @param \moodle_url $url Target with the action parameters.
     * @param string $icon Core pix icon, e.g. 't/up'.
     * @param string $label Short label, shown as tooltip.
     * @param string $rowname Name of the row (plain text), or '' outside a table.
     * @param string $method 'post' for a change (with sesskey), 'get' for a button that only opens a page.
     * @return string HTML
     */
    public static function icon_button(
        \moodle_url $url,
        string $icon,
        string $label,
        string $rowname = '',
        string $method = 'post'
    ): string {
        global $OUTPUT;
        $fields = '';
        $params = $method === 'post' ? $url->params() + ['sesskey' => sesskey()] : $url->params();
        foreach ($params as $name => $value) {
            $fields .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        $name = $rowname !== '' ? self::name($label, $rowname) : $label;
        $content = $OUTPUT->pix_icon($icon, '') . \html_writer::span(s($name), 'visually-hidden');
        $button = \html_writer::tag('button', $content, [
            'type' => 'submit',
            'class' => 'btn btn-icon local-nextpool-iconaction',
            'title' => $label,
            'data-bs-toggle' => 'tooltip',
        ]);
        return \html_writer::tag('form', $fields . $button, [
            'method' => $method,
            'action' => $url->out_omit_querystring(),
            'class' => 'd-inline',
        ]);
    }

    /**
     * Kebab menu with the rarer actions of a row or page.
     *
     * @param \action_menu_link[] $links Menu entries; destructive ones last.
     * @param string $rowname Name of the row (plain text), or '' outside a table.
     * @return string HTML, '' without entries.
     */
    public static function menu(array $links, string $rowname = ''): string {
        global $OUTPUT;
        if (!$links) {
            return '';
        }
        $label = get_string('moreactions');
        $menu = new \action_menu($links);
        $menu->set_kebab_trigger($rowname !== '' ? self::name($label, $rowname) : $label);
        $menu->set_additional_classes('local-nextpool-rowmenu');
        $menu->attributes['class'] .= ' d-inline-block';
        return $OUTPUT->render($menu);
    }

    /**
     * The rarer actions of a row or page: a single one as icon link, a kebab menu only from two on (task166, task167).
     *
     * Use links that lead to a page or a confirmation (GET); a consequential action asks there first.
     *
     * @param \action_menu_link[] $links Entries; destructive ones last.
     * @param string $rowname Name of the row (plain text), or '' outside a table.
     * @return string HTML, '' without entries.
     */
    public static function more(array $links, string $rowname = ''): string {
        $links = array_values($links);
        if (count($links) !== 1) {
            return self::menu($links, $rowname);
        }
        $link = $links[0];
        $icon = $link->icon instanceof \pix_icon && in_array($link->icon->component, ['', 'moodle', 'core'], true)
            ? $link->icon->pix : 't/right';
        return self::icon_link($link->url, $icon, (string) $link->text, $rowname);
    }

    /**
     * Wrap the actions of a row so they sit on one line.
     *
     * @param string[] $actions Rendered actions.
     * @param string $label Accessible name of the group.
     * @return string HTML
     */
    public static function group(array $actions, string $label): string {
        $classes = 'local-nextpool-actions d-flex flex-wrap flex-md-nowrap align-items-center';
        return \html_writer::div(implode('', array_filter($actions)), $classes, [
            'role' => 'group',
            'aria-label' => $label,
        ]);
    }

    /**
     * Empty slot of an icon action, so the icons of all rows line up.
     *
     * @return string HTML
     */
    public static function spacer(): string {
        return \html_writer::span('', 'local-nextpool-iconaction local-nextpool-iconaction--empty', ['aria-hidden' => 'true']);
    }
}
