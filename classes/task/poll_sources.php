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

namespace local_nextpool\task;

use local_nextpool\local\item_manager;
use local_nextpool\local\rejected_source_revision;
use local_nextpool\local\source\base;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Check the storage locations for changed files and import them as draft versions (F06 polling).
 *
 * For every published item whose latest version is published, the task asks the
 * storage location whether the file changed (revision, e.g. ETag). A change becomes
 * a draft version that goes through review as usual; nothing reaches courses before
 * it is published. Items with an open draft or a version in review are skipped, so
 * the task never piles up drafts. A failing storage location does not stop the others.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll_sources extends \core\task\scheduled_task {
    /** @var base[] Storage locations of this run, keyed by id; each is loaded and decrypted once (bug56). */
    private array $sources = [];
    /** @var array<int,bool> Storage locations that failed as a whole in this run, keyed by id (bug163). */
    private array $failed = [];

    /**
     * Errors of a storage location as a whole: unreachable, not configured, no access, throttled. The other items
     * of that storage location wait for the next run instead of each waiting for the same failure (bug163).
     */
    private const SOURCE_ERRORS = [
        'error_basefoldernotfound',
        'error_credentials',
        'error_drivenotfound',
        'error_nextcloudauth',
        'error_sitenotfound',
        'error_sourcedisabled',
        'error_sourcemissing',
        'error_sourcenotconfigured',
        'error_sourceunavailable',
        'error_tenant',
        'error_throttled',
    ];

    #[\Override]
    public function get_name() {
        return get_string('task_pollsources', 'local_nextpool');
    }

    #[\Override]
    public function execute() {
        global $DB;
        $this->sources = [];
        $this->failed = [];
        $enabled = array_keys(source_manager::get_records(true));
        if (!$enabled) {
            mtrace('No enabled storage location.');
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($enabled, SQL_PARAMS_NAMED);
        $params['published'] = status::PUBLISHED;
        // Only items whose latest version is published: an open draft or review blocks further imports.
        $items = $DB->get_records_sql(
            'SELECT i.id, i.title, i.sourceid, v.id AS latestid, v.status AS lateststatus
               FROM {' . item_manager::TABLE . '} i
               JOIN {' . version_manager::TABLE . '} v ON v.itemid = i.id
              WHERE i.status = :published AND i.sourceid ' . $insql . '
                AND v.versionnr = (SELECT MAX(m.versionnr) FROM {' . version_manager::TABLE . '} m WHERE m.itemid = i.id)
           ORDER BY i.id',
            $params
        );
        $created = 0;
        $skipped = 0;
        foreach ($items as $item) {
            if ($item->lateststatus !== status::PUBLISHED) {
                continue;
            }
            if (isset($this->failed[(int) $item->sourceid])) {
                $skipped++;
                continue;
            }
            try {
                $source = $this->get_source((int) $item->sourceid);
                $entry = version_manager::check_for_update((int) $item->id, $source);
                if (!$entry) {
                    continue;
                }
                $record = item_manager::get((int) $item->id);
                $latest = version_manager::get((int) $item->latestid);
                if (rejected_source_revision::contains($record, $latest, $entry)) {
                    mtrace("Item {$item->id}: unchanged rejected source revision, download skipped.");
                    continue;
                }
                // Import from where the file is now: it may have been renamed (q46, q50).
                $version = version_manager::create_from_source((int) $item->id, $entry->path, [
                    'createdby' => 0,
                    'basedonversionid' => (int) $item->latestid,
                ], $source);
                $created++;
                mtrace("Item {$item->id}: draft version {$version->versionnr} imported from {$entry->path}.");
            } catch (\Throwable $e) {
                // Unchanged content behind a new revision (recorded, so it is not downloaded again), an unreachable
                // storage location or any other failure of one item: log it and go on with the others (bug60).
                mtrace("Item {$item->id}: " . $e->getMessage());
                if ($e instanceof source_exception && in_array($e->errorcode, self::SOURCE_ERRORS, true)) {
                    $this->failed[(int) $item->sourceid] = true;
                    mtrace("Storage location {$item->sourceid}: its other items are checked in the next run.");
                }
            }
        }
        if ($skipped) {
            mtrace("Skipped $skipped item(s) of failed storage locations.");
        }
        mtrace("Checked " . count($items) . " item(s), imported $created draft version(s).");
    }

    /**
     * A storage location, loaded once per run.
     *
     * @param int $sourceid Storage location id.
     * @return base
     */
    private function get_source(int $sourceid): base {
        return $this->sources[$sourceid] ??= $this->load_source($sourceid);
    }

    /**
     * Load a storage location with its decrypted configuration.
     *
     * @param int $sourceid Storage location id.
     * @return base
     */
    protected function load_source(int $sourceid): base {
        return source_manager::get($sourceid);
    }
}
