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
use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\path;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Import the current file of an item from its storage location as a new version (F06, CI push).
 *
 * A build or another system puts the new file into the storage location and calls this
 * function. The same rights as in the editorial pages apply: importing needs
 * local/nextpool:manageitems (item editors may import the file of their item only, bug102), each status change
 * the capability of that change. Like the
 * publish form, h5plibraries allows an H5P version to update libraries (q39, bug89); it needs no
 * other right than publishing, and the libraries are only installed if the publisher may do so. Calling it
 * again with unchanged content returns the latest version instead of failing.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_version extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemuuid' => new external_value(PARAM_ALPHANUMEXT, 'Identifier (UUID) of the catalogue item'),
            'path' => new external_value(PARAM_RAW, 'Path of the file in the storage location, relative to its base folder'),
            'label' => new external_value(PARAM_TEXT, 'Version label', VALUE_DEFAULT, ''),
            'changelog' => new external_value(PARAM_RAW, 'Changelog (HTML)', VALUE_DEFAULT, ''),
            'targetstatus' => new external_value(
                PARAM_ALPHA,
                'Status of the new version: draft, review or published',
                VALUE_DEFAULT,
                status::DRAFT
            ),
            'h5plibraries' => new external_value(
                PARAM_BOOL,
                'Allow the H5P package to install or update H5P libraries site-wide, as the option when publishing '
                    . 'in the editorial pages (q39). Only for H5P items with targetstatus published',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Import the file as a new version and move it to the target status.
     *
     * @param string $itemuuid Item UUID.
     * @param string $path Path in the storage location.
     * @param string $label Version label.
     * @param string $changelog Changelog (HTML).
     * @param string $targetstatus draft, review or published.
     * @param bool $h5plibraries Publish with H5P library updates allowed (q39, bug89).
     * @return array
     */
    public static function execute(
        string $itemuuid,
        string $path,
        string $label = '',
        string $changelog = '',
        string $targetstatus = status::DRAFT,
        bool $h5plibraries = false
    ): array {
        [
            'itemuuid' => $itemuuid,
            'path' => $path,
            'label' => $label,
            'changelog' => $changelog,
            'targetstatus' => $targetstatus,
            'h5plibraries' => $h5plibraries,
        ] = self::validate_parameters(self::execute_parameters(), [
            'itemuuid' => $itemuuid,
            'path' => $path,
            'label' => $label,
            'changelog' => $changelog,
            'targetstatus' => $targetstatus,
            'h5plibraries' => $h5plibraries,
        ]);

        $item = item_manager::get_by_uuid(strtolower($itemuuid));
        if (!$item) {
            throw new \moodle_exception('invalidrecord', 'error', '', item_manager::TABLE);
        }
        self::validate_context(access::get_context($item));
        access::require_edit($item);
        // Item editors import the file of their item only, not any file of the storage location (bug102).
        $path = path::normalise($path);
        access::require_import_path($item, $path);
        $steps = match ($targetstatus) {
            status::DRAFT => [],
            status::REVIEW => [status::REVIEW],
            status::PUBLISHED => [status::REVIEW, status::PUBLISHED],
            default => throw new \invalid_parameter_exception('targetstatus must be draft, review or published'),
        };
        // Same meaning as the option in the publish form, which only H5P versions get when they are published (bug89).
        if ($h5plibraries && ($item->type !== 'h5p' || $targetstatus !== status::PUBLISHED)) {
            throw new \invalid_parameter_exception('h5plibraries needs an H5P item and targetstatus published');
        }
        // Check the status rights before importing, so a refused call leaves no draft behind.
        $from = status::DRAFT;
        foreach ($steps as $to) {
            access::require_change_status($item, $from, $to);
            $from = $to;
        }

        try {
            $version = version_manager::create_from_source((int) $item->id, $path, [
                'label' => $label,
                'changelog' => $changelog !== '' ? $changelog : null,
                'changelogformat' => FORMAT_HTML,
            ]);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'error_unchanged') {
                throw $e;
            }
            return self::export(version_manager::get_latest((int) $item->id), true);
        }
        foreach ($steps as $to) {
            $version = version_manager::set_status((int) $version->id, $to, 0, $to === status::PUBLISHED && $h5plibraries);
        }
        return self::export($version, false);
    }

    /**
     * Return value of a version.
     *
     * @param \stdClass $version Version record.
     * @param bool $unchanged Whether the file was unchanged and no version was created.
     * @return array
     */
    private static function export(\stdClass $version, bool $unchanged): array {
        return [
            'versionid' => (int) $version->id,
            'versionnr' => (int) $version->versionnr,
            'status' => (string) $version->status,
            'contenthash' => (string) $version->contenthash,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'versionid' => new external_value(PARAM_INT, 'Version id'),
            'versionnr' => new external_value(PARAM_INT, 'Version number within the item'),
            'status' => new external_value(PARAM_ALPHA, 'Status of the version'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-1 of the content'),
            'unchanged' => new external_value(PARAM_BOOL, 'True if the file equals the latest version; nothing was imported'),
        ]);
    }
}
