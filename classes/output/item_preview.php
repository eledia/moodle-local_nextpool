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
use local_nextpool\local\catalogue_return;
use local_nextpool\local\item_types;
use local_nextpool\local\placement_manager;
use local_nextpool\local\preview_result;
use local_nextpool\local\version_manager;

/**
 * Shared preview panel and header for the modal and no-JavaScript fallback page (F16).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_preview {
    /**
     * Header: facts, placement action and version difference.
     *
     * @param \stdClass $item Item.
     * @param int $courseid Course id.
     * @param \renderer_base $output Renderer.
     * @param array $filters Allowed catalogue filters.
     * @return array
     */
    public static function header(\stdClass $item, int $courseid, \renderer_base $output, array $filters = []): array {
        $placements = array_filter(
            placement_manager::get_for_course($courseid),
            static fn($p) => (int) $p->itemid === (int) $item->id
        );
        $data = item_summary::data($item, $output, count($placements));
        $params = ['itemid' => $item->id, 'courseid' => $courseid] + catalogue_return::read($filters);
        $data['previewurl'] = (new \moodle_url('/local/nextpool/preview.php', $params))->out(false);
        $data['feedbackurl'] = (new \moodle_url(
            '/local/nextpool/feedback.php',
            $params + ['versionid' => $item->currentversionid]
        ))->out(false);
        $data['returnurl'] = catalogue_return::url($courseid, (int) $item->id, $filters)->out(false);
        $handler = item_types::get_handler($item->type);
        $version = version_manager::get((int) $item->currentversionid);
        try {
            $reason = $handler?->check_place($item, $version);
        } catch (\moodle_exception $e) {
            $reason = get_string('previewunavailable', 'local_nextpool');
        }
        $coursecontext = \context_course::instance($courseid);
        $modname = $handler?->get_modname();
        $data['canplace'] = $handler && $reason === null
            && \core\plugininfo\mod::get_enabled_plugin($modname)
            && has_capability('moodle/course:manageactivities', $coursecontext)
            && has_capability('mod/' . $modname . ':addinstance', $coursecontext);
        $data['placereason'] = $reason ?? ($handler ? '' : get_string('notplaceableyet', 'local_nextpool'));
        $data['placeurl'] = (new \moodle_url('/local/nextpool/place.php', array_intersect_key(
            $params,
            array_flip(['courseid', 'itemid', 'section'])
        )))->out(false);
        $versions = [];
        foreach ($placements as $placement) {
            if ((int) $placement->appliedversionid !== (int) $item->currentversionid) {
                $versions[] = version_manager::get((int) $placement->appliedversionid)->versionnr;
            }
        }
        $data['versionnotice'] = $versions ? get_string('previewversiondifference', 'local_nextpool', (object) [
            'course' => implode(', ', array_unique($versions)), 'preview' => $version->versionnr,
        ]) : '';
        return $data;
    }

    /**
     * Preview data, preserving a usable header/feedback when a package is missing or malformed.
     *
     * @param \stdClass $item Item.
     * @param int $courseid Course id.
     * @param \renderer_base $output Renderer.
     * @param array $filters Allowed filters.
     * @param int $chapter Zero-based chapter, 0 for first.
     * @return array
     */
    public static function data(
        \stdClass $item,
        int $courseid,
        \renderer_base $output,
        array $filters = [],
        int $chapter = 0
    ): array {
        access::require_preview($item, $courseid);
        $data = self::header($item, $courseid, $output, $filters);
        $version = version_manager::get((int) $item->currentversionid);
        try {
            $result = item_types::get_handler($item->type)?->get_preview($item, $version, $courseid) ?? new preview_result();
        } catch (\moodle_exception $e) {
            $result = new preview_result(message: get_string('previewunavailable', 'local_nextpool'));
        }
        if ($result->kind === 'chapters' && $result->chapters) {
            $chapter = min(max(0, $chapter), count($result->chapters) - 1);
            foreach ($result->chapters as $index => $entry) {
                $result->chapters[$index]['index'] = $index;
                $result->chapters[$index]['current'] = $index === $chapter;
                $result->chapters[$index]['urlpage'] = (new \moodle_url($data['previewurl'], ['chapter' => $index]))->out(false);
            }
            $entry = $result->chapters[$chapter];
            $result->html = $entry['html'] ?? '';
            $result->url = $entry['url'] ?? '';
            $data['ischapterframe'] = !empty($entry['frame']);
        }
        $data += [
            'frame' => $result->kind === 'frame' || !empty($data['ischapterframe']),
            'html' => $result->html, 'hashtml' => $result->html !== '', 'contenturl' => $result->url,
            'image' => $result->kind === 'media' && str_starts_with($result->mimetype, 'image/'),
            'video' => $result->kind === 'media' && str_starts_with($result->mimetype, 'video/'),
            'audio' => $result->kind === 'media' && str_starts_with($result->mimetype, 'audio/'),
            'link' => $result->kind === 'link', 'chapters' => $result->chapters,
            'message' => $result->message ?: ($result->kind === 'unavailable'
                ? get_string('previewunavailable', 'local_nextpool') : ''),
            'frametitle' => get_string('previewtitle', 'local_nextpool', strip_tags($data['title'])),
            'sandboxed' => in_array($item->type, ['xapi', 'scorm'], true),
        ];
        return $data;
    }

    /**
     * Log a preview opening, never a course-module view or learning event.
     *
     * @param \stdClass $item Item.
     * @param int $courseid Course id.
     * @param string $via Page or dialog.
     */
    public static function log(\stdClass $item, int $courseid, string $via): void {
        $version = version_manager::get((int) $item->currentversionid);
        \local_nextpool\event\item_previewed::create([
            'context' => \context_course::instance($courseid), 'objectid' => $item->id,
            'other' => ['versionnr' => (int) $version->versionnr, 'type' => $item->type, 'via' => $via],
        ])->trigger();
    }
}
