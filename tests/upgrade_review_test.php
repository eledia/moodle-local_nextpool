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

namespace local_nextpool;

use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\version_manager;

/**
 * Upgrade to the review and collaboration schema on existing data (F07, F08, task79, test60).
 *
 * The test rolls the schema back to the state before task79, runs the real upgrade step and checks the
 * result. Afterwards the schema is the same as after installation again.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_nextpool_upgrade')]
final class upgrade_review_test extends \advanced_testcase {
    /** @var int Plugin version before task79. */
    private const BEFORE = 2026111412;

    /** @var string[] Tables added by task79, in the order they can be dropped. */
    private const NEW_TABLES = [
        'local_nextpool_comment_link',
        'local_nextpool_comment',
        'local_nextpool_version_log',
        'local_nextpool_subscription',
        'local_nextpool_item_editor',
    ];

    /**
     * Indexes for the review overview and the clean-up.
     *
     * @return \xmldb_index[]
     */
    private function review_indexes(): array {
        return [
            new \xmldb_index('status-assignedto', XMLDB_INDEX_NOTUNIQUE, ['status', 'assignedto']),
            new \xmldb_index('status-timedecided', XMLDB_INDEX_NOTUNIQUE, ['status', 'timedecided']),
        ];
    }

    /**
     * Put the version table back into its shape before task79 and drop the new tables.
     */
    private function roll_back_schema(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (self::NEW_TABLES as $name) {
            $dbman->drop_table(new \xmldb_table($name));
        }
        $table = new \xmldb_table('local_nextpool_version');
        foreach ($this->review_indexes() as $index) {
            $dbman->drop_index($table, $index);
        }
        $dbman->drop_key($table, new \xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']));
        $dbman->drop_key($table, new \xmldb_key('assignedto', XMLDB_KEY_FOREIGN, ['assignedto'], 'user', ['id']));
        foreach (['origin', 'courseid', 'assignedto', 'timesubmitted', 'timedecided', 'selfapproved'] as $name) {
            $dbman->drop_field($table, new \xmldb_field($name));
        }
        $index = new \xmldb_index('itemid-versionnr', XMLDB_INDEX_UNIQUE, ['itemid', 'versionnr']);
        $dbman->drop_index($table, $index);
        $dbman->change_field_notnull($table, new \xmldb_field('versionnr', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $dbman->add_index($table, $index);
    }

    /**
     * Existing versions get their origin, keep their numbers, and proposals can then be stored without a number.
     */
    public function test_upgrade_existing_versions(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/nextpool/db/upgrade.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');

        $item = $generator->create_published_item();
        $v1 = (int) $item->currentversionid;
        $generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', 'polled');
        $v2 = (int) version_manager::create_from_source((int) $item->id, 'handout.pdf', ['createdby' => 0])->id;

        $this->roll_back_schema();
        $dbman = $DB->get_manager();
        $this->assertFalse($dbman->field_exists('local_nextpool_version', 'origin'));
        $this->assertFalse($dbman->table_exists('local_nextpool_version_log'));
        set_config('version', self::BEFORE, 'local_nextpool');

        $this->assertTrue(xmldb_local_nextpool_upgrade(self::BEFORE));

        $this->assertGreaterThan(self::BEFORE, (int) get_config('local_nextpool', 'version'));
        foreach (self::NEW_TABLES as $name) {
            $this->assertTrue($dbman->table_exists($name), $name);
        }
        $versions = $DB->get_records('local_nextpool_version', ['itemid' => $item->id]);
        $this->assertSame(version_manager::ORIGIN_EDITORIAL, $versions[$v1]->origin);
        $this->assertSame(version_manager::ORIGIN_SOURCE, $versions[$v2]->origin);
        $this->assertSame(1, (int) $versions[$v1]->versionnr);
        $this->assertSame(2, (int) $versions[$v2]->versionnr);
        $this->assertSame(0, (int) $versions[$v1]->timedecided);
        $this->assertNull($versions[$v1]->assignedto);

        // The number is nullable now and still unique where set.
        $table = new \xmldb_table('local_nextpool_version');
        $unique = new \xmldb_index('itemid-versionnr', XMLDB_INDEX_UNIQUE, ['itemid', 'versionnr']);
        $this->assertTrue($dbman->index_exists($table, $unique));
        foreach ($this->review_indexes() as $index) {
            $this->assertTrue($dbman->index_exists($table, $index), $index->getName());
        }
        $generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', 'proposal one');
        $p1 = version_manager::create_from_source((int) $item->id, 'handout.pdf', ['origin' => version_manager::ORIGIN_PROPOSAL]);
        $generator->put_file(source_manager::get((int) $item->sourceid), 'handout.pdf', 'proposal two');
        $p2 = version_manager::create_from_source((int) $item->id, 'handout.pdf', ['origin' => version_manager::ORIGIN_PROPOSAL]);
        $this->assertNull($p1->versionnr);
        $this->assertNull($p2->versionnr);

        // Running the step again (a resumed upgrade) changes nothing.
        set_config('version', self::BEFORE, 'local_nextpool');
        $this->assertTrue(xmldb_local_nextpool_upgrade(self::BEFORE));
        $this->assertSame(version_manager::ORIGIN_SOURCE, $DB->get_field('local_nextpool_version', 'origin', ['id' => $v2]));
    }
}
