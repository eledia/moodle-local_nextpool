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

use local_nextpool\local\access;
use local_nextpool\local\category_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\license_info;
use local_nextpool\local\version_manager;

/**
 * Shared metadata for catalogue cards, dialog headers and preview pages (F16 task200).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_summary {
    /**
     * Build one representation, accepting batched author/tag results from the catalogue list.
     *
     * @param \stdClass $item Item record.
     * @param \renderer_base $output Renderer.
     * @param int $placed Number of placements in the current course.
     * @param array|null $names Author names, null to load them.
     * @param array|null $tags Display names of tags, null to load them.
     * @param array|null $categories Category menu, null to load it.
     * @return array Template data.
     */
    public static function data(
        \stdClass $item,
        \renderer_base $output,
        int $placed = 0,
        ?array $names = null,
        ?array $tags = null,
        ?array $categories = null
    ): array {
        $context = access::get_context($item);
        $names ??= array_column(item_manager::get_author_names((int) $item->id), 'name');
        $tags ??= array_map(
            static fn($tag) => $tag->get_display_name(),
            \core_tag_tag::get_item_tags('local_nextpool', item_manager::TAG_ITEMTYPE, (int) $item->id)
        );
        $versionnr = (int) ($item->currentversionnr
            ?? (!empty($item->currentversionid) ? version_manager::get((int) $item->currentversionid)->versionnr : 0));
        $mode = update_mode::for_type($item->type);
        $description = format_text((string) $item->description, (int) $item->descriptionformat, ['context' => $context]);
        $short = shorten_text(content_to_text($description, false), 140);
        $categories ??= category_manager::get_menu();
        $category = !empty($item->categoryid) ? ($categories[(int) $item->categoryid] ?? '') : '';
        $duration = !empty($item->duration) ? format_time((int) $item->duration) : '';
        return [
            'id' => (int) $item->id,
            'contentlanguage' => str_replace('_', '-', (string) $item->language),
            'title' => format_string($item->title, true, ['context' => $context]),
            'description' => $short, 'descriptionfull' => $description, 'hasdescription' => trim($short) !== '',
            'type' => get_string('typeshort_' . $item->type, 'local_nextpool'),
            'typefull' => get_string('type_' . $item->type, 'local_nextpool'),
            'inplace' => item_types::supports_in_place($item->type),
            'version' => get_string('versionshort', 'local_nextpool', $versionnr),
            'versionfull' => get_string('versionnr', 'local_nextpool', $versionnr),
            'updatemodeicon' => update_mode::icon($mode, $output),
            'updatemodeshort' => update_mode::short_name($mode),
            'updatemodefull' => update_mode::description($mode),
            'license' => license_info::short_name($item->license),
            'licensefull' => license_info::full_name($item->license),
            'licensecopyright' => license_info::is_all_rights_reserved($item->license),
            'hasauthors' => (bool) $names,
            'author' => $names && count($names) > 1
                ? $names[0] . ' ' . get_string('authorsmore', 'local_nextpool', count($names) - 1) : ($names[0] ?? ''),
            'authorsmore' => count($names) > 1, 'authorsfull' => implode(', ', $names),
            'category' => $category, 'duration' => $duration,
            'facts' => implode(' · ', array_filter([$category, $duration])), 'tags' => implode(', ', $tags),
            'placed' => $placed > 0, 'placedtext' => get_string('placedcount', 'local_nextpool', $placed),
        ];
    }
}
