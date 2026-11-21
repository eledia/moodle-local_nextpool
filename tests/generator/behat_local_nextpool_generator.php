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

use local_nextpool\local\category_manager;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Behat data generator for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_nextpool_generator extends behat_generator_base {
    #[\Override]
    protected function get_creatable_entities(): array {
        return [
            'storage locations' => [
                'singular' => 'storage location',
                'datagenerator' => 'storage_location',
                'required' => ['name'],
            ],
            'storage files' => [
                'singular' => 'storage file',
                'datagenerator' => 'storage_file',
                'required' => ['location', 'path'],
            ],
            'items' => [
                'singular' => 'item',
                'datagenerator' => 'item',
                'required' => ['title', 'location'],
            ],
            'item competencies' => [
                'singular' => 'item competency',
                'datagenerator' => 'item_competency',
                'required' => ['item', 'competency'],
            ],
            'published versions' => [
                'singular' => 'published version',
                'datagenerator' => 'published_version',
                'required' => ['item'],
            ],
            'placements' => [
                'singular' => 'placement',
                'datagenerator' => 'placement',
                'required' => ['item', 'course'],
            ],
            'versions in review' => [
                'singular' => 'version in review',
                'datagenerator' => 'version_in_review',
                'required' => ['item'],
            ],
            'item editors' => [
                'singular' => 'item editor',
                'datagenerator' => 'item_editor',
                'required' => ['item', 'user'],
            ],
            'proposals' => [
                'singular' => 'proposal',
                'datagenerator' => 'proposal',
                'required' => ['item', 'course', 'user'],
            ],
            'item statistics' => [
                'singular' => 'item statistic',
                'datagenerator' => 'item_statistic',
                'required' => ['item', 'version'],
            ],
        ];
    }

    /**
     * Create a storage location. Fields: name, plugin (default moodle), basefolder.
     *
     * @param array $data Row data.
     */
    protected function process_storage_location(array $data): void {
        manager::create($data['plugin'] ?? 'moodle', $data['name'], ['basefolder' => $data['basefolder'] ?? '']);
    }

    /**
     * Put a file into a storage location. Fields: location (name), path, and either content (text), fixture
     * (a file below the Moodle code directory, e.g. h5p/tests/fixtures/greeting-card.h5p) or fixturezip (a folder
     * below the Moodle code directory whose files are zipped, e.g. local/nextpool/tests/fixtures/quiz).
     *
     * @param array $data Row data.
     */
    protected function process_storage_file(array $data): void {
        manager::get($this->get_location_id($data['location']))->write($data['path'], $this->make_upload($data));
    }

    /**
     * Local file with the content of a row: content, fixture or fixturezip, see process_storage_file().
     *
     * @param array $data Row data.
     * @return string Path of the file.
     */
    protected function make_upload(array $data): string {
        global $CFG;
        $tempfile = make_request_directory() . '/upload';
        if (!empty($data['fixture'])) {
            copy($CFG->dirroot . '/' . $data['fixture'], $tempfile);
        } else if (!empty($data['fixturezip'])) {
            $zip = new \ZipArchive();
            $zip->open($tempfile, \ZipArchive::CREATE);
            foreach (glob($CFG->dirroot . '/' . $data['fixturezip'] . '/*') as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        } else {
            file_put_contents($tempfile, $data['content'] ?? '');
        }
        return $tempfile;
    }

    /**
     * Create a catalogue item. Fields: title, location (name), type (default file), license (default cc-4.0), author,
     * file (path in the location: imports version 1), published (1: publishes version 1 and the item),
     * course (shortname: the item was uploaded from that course and is available only there, M06; type xapi),
     * category (idnumber: the item is available in that course category),
     * cataloguecategory (name of a top-level catalogue category, created if missing).
     *
     * @param array $data Row data.
     */
    protected function process_item(array $data): void {
        global $DB;
        $record = (object) [
            'title' => $data['title'],
            'type' => $data['type'] ?? 'file',
            'license' => $data['license'] ?? 'cc-4.0',
            'sourceid' => $this->get_location_id($data['location']),
        ];
        if (!empty($data['course'])) {
            $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $data['course']], MUST_EXIST);
            $record->contextid = \context_course::instance($courseid)->id;
            $record->origincourseid = $courseid;
        }
        if (!empty($data['category'])) {
            $categoryid = (int) $DB->get_field('course_categories', 'id', ['idnumber' => $data['category']], MUST_EXIST);
            $record->contextid = \context_coursecat::instance($categoryid)->id;
        }
        if (!empty($data['cataloguecategory'])) {
            $record->categoryid = (int) ($DB->get_field(category_manager::TABLE, 'id', ['name' => $data['cataloguecategory']])
                ?: category_manager::create($data['cataloguecategory'])->id);
        }
        $item = item_manager::create($record, [['name' => $data['author'] ?? 'Behat Author']]);
        if (!empty($data['file'])) {
            $version = version_manager::create_from_source((int) $item->id, $data['file']);
            if (!empty($data['published'])) {
                version_manager::set_status((int) $version->id, status::REVIEW);
                version_manager::set_status((int) $version->id, status::PUBLISHED);
                item_manager::set_status((int) $item->id, status::REVIEW);
                item_manager::set_status((int) $item->id, status::PUBLISHED);
            }
        }
    }

    /**
     * Change the file of an item in its location, import it and publish it. Fields: item (title), and content,
     * fixture or fixturezip as for storage files.
     *
     * @param array $data Row data.
     */
    protected function process_published_version(array $data): void {
        global $DB;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $item = item_manager::get($itemid);
        $path = version_manager::get_latest($itemid)->sourceref;
        manager::get((int) $item->sourceid)->write($path, $this->make_upload($data));
        $version = version_manager::create_from_source($itemid, $path);
        version_manager::set_status((int) $version->id, status::REVIEW);
        version_manager::set_status((int) $version->id, status::PUBLISHED);
    }

    /**
     * Add a competency to an item (F14). Fields: item (title), competency (idnumber), level (default 1),
     * function (default explain), objectives (optional, "bloom | text").
     *
     * @param array $data Row data.
     */
    protected function process_item_competency(array $data): void {
        global $DB;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $entries = array_map(static fn($c) => [
            'competency' => $c->competency,
            'level' => $c->skilllevel,
            'function' => $c->didacticfunction,
            'objectives' => $c->objectives,
        ], item_competencies::get($itemid));
        $entries[] = [
            'competency' => $data['competency'],
            'level' => (int) ($data['level'] ?? 1),
            'function' => $data['function'] ?? 'explain',
            'objectives' => \local_nextpool\form\item_form::text_to_objectives((string) ($data['objectives'] ?? '')),
        ];
        item_competencies::set($itemid, $entries);
    }

    /**
     * Change the file of an item in its location, import it and submit the draft for review (task85). Fields: item
     * (title), content, user (username of the submitting person, default admin), days (submitted that many days ago).
     *
     * @param array $data Row data.
     */
    protected function process_version_in_review(array $data): void {
        global $DB, $USER;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $item = item_manager::get($itemid);
        $path = version_manager::get_latest($itemid)->sourceref;
        manager::get((int) $item->sourceid)->write($path, $this->make_upload($data + ['content' => 'changed']));
        $previous = $USER;
        if (!empty($data['user'])) {
            \core\session\manager::set_user(\core_user::get_user_by_username($data['user'], '*', null, MUST_EXIST));
        }
        try {
            $version = version_manager::create_from_source($itemid, $path);
            version_manager::set_status((int) $version->id, status::REVIEW);
        } finally {
            \core\session\manager::set_user($previous);
        }
        if (!empty($data['days'])) {
            $DB->set_field(version_manager::TABLE, 'timesubmitted', time() - (int) $data['days'] * DAYSECS, ['id' => $version->id]);
        }
        \local_nextpool\local\review_queue::invalidate();
    }

    /**
     * Propose a new version from a course and decide it (task86). Fields: item (title), course (shortname), user
     * (username of the proposing person), content (default "proposal"), description (default "Proposed change."),
     * status (review, rejected or cancelled; default review), reason (default "Not needed."), days (decided that many
     * days ago). Rejected by admin.
     *
     * @param array $data Row data.
     */
    protected function process_proposal(array $data): void {
        global $DB;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $data['course']], MUST_EXIST);
        $userid = (int) \core_user::get_user_by_username($data['user'], 'id', null, MUST_EXIST)->id;
        $filename = version_manager::get_latest($itemid)->filename;
        $version = \local_nextpool\local\proposal_manager::propose_version(
            $itemid,
            $courseid,
            $this->make_upload($data + ['content' => 'proposal']),
            $filename,
            $data['description'] ?? 'Proposed change.',
            FORMAT_PLAIN,
            [],
            0,
            $userid
        );
        $status = $data['status'] ?? status::REVIEW;
        if ($status === status::REJECTED) {
            \local_nextpool\local\review_manager::reject(
                (int) $version->id,
                $data['reason'] ?? 'Not needed.',
                (int) get_admin()->id
            );
        } else if ($status === status::CANCELLED) {
            \local_nextpool\local\proposal_manager::cancel((int) $version->id, $userid);
        }
        if (!empty($data['days'])) {
            $DB->set_field(version_manager::TABLE, 'timedecided', time() - (int) $data['days'] * DAYSECS, ['id' => $version->id]);
        }
        \local_nextpool\local\review_queue::invalidate();
    }

    /**
     * Place a published item in a course as admin (task81). Fields: item (title), course (shortname), policy
     * (default notify), section (default 0).
     *
     * @param array $data Row data.
     */
    protected function process_placement(array $data): void {
        global $DB;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $data['course']], MUST_EXIST);
        \local_nextpool\local\placement_manager::place(
            $itemid,
            $courseid,
            (int) ($data['section'] ?? 0),
            $data['policy'] ?? 'notify',
            (int) get_admin()->id
        );
    }

    /**
     * Store one row of statistics as the scheduled task would (F15, task127). Fields: item (title), version (0 for
     * the total), the values of item_statistics::VALUES (empty: suppressed), flags (e.g. "passrate:none,scoremedian:
     * derived"; suppressed values without a flag count as small), coursecount (total only), hoursago (time of the
     * calculation, default 6 hours ago).
     *
     * @param array $data Row data.
     */
    protected function process_item_statistic(array $data): void {
        global $DB;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $row = (object) [
            'itemid' => $itemid,
            'versionnr' => (int) $data['version'],
            'coursecount' => isset($data['coursecount']) && $data['coursecount'] !== '' ? (int) $data['coursecount'] : null,
            'timecalculated' => time() - (int) ($data['hoursago'] ?? 6) * HOURSECS,
        ];
        $flags = [];
        foreach (array_filter(array_map('trim', explode(',', (string) ($data['flags'] ?? '')))) as $entry) {
            [$name, $flag] = array_map('trim', explode(':', $entry, 2));
            $flags[$name] = $flag;
        }
        $suppressed = [];
        foreach (\local_nextpool\local\item_statistics::VALUES as $name) {
            $value = $data[$name] ?? '';
            $row->$name = $value === '' ? null : $value;
            if ($value === '') {
                $suppressed[] = $name . ':' . ($flags[$name] ?? \local_nextpool\local\item_statistics::FLAG_SMALL);
            }
        }
        $row->suppressed = $suppressed ? implode(',', $suppressed) : null;
        $DB->insert_record(\local_nextpool\local\item_statistics::TABLE, $row);
    }

    /**
     * Add an item editor as admin (task84). Fields: item (title), user (username).
     *
     * @param array $data Row data.
     */
    protected function process_item_editor(array $data): void {
        global $DB;
        $itemid = (int) $DB->get_field(item_manager::TABLE, 'id', ['title' => $data['item']], MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $data['user']], MUST_EXIST);
        \local_nextpool\local\item_editor_manager::add($itemid, $userid, (int) get_admin()->id);
    }

    /**
     * Storage location id by name.
     *
     * @param string $name Location name.
     * @return int
     */
    protected function get_location_id(string $name): int {
        global $DB;
        return (int) $DB->get_field(manager::TABLE, 'id', ['name' => $name], MUST_EXIST);
    }
}
