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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/nextpool/lib.php');

use core\check\result;
use local_nextpool\check\sources;

/**
 * Tests for the storage location status check.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sources::class)]
final class check_test extends \advanced_testcase {
    /**
     * No location → not applicable; reachable locations → OK; an unreachable one → error.
     */
    public function test_sources_check(): void {
        $this->resetAfterTest();
        $this->assertSame(result::NA, (new sources())->get_result()->get_status());

        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $generator->create_source('moodle');
        $result = (new sources())->get_result();
        $this->assertSame(result::OK, $result->get_status());
        $this->assertSame(get_string('checksources_ok_one', 'local_nextpool'), $result->get_summary());

        // A Nextcloud location without server configuration cannot be reached.
        $generator->create_source('nextcloud', ['authmode' => 'apppassword', 'serverurl' => '', 'username' => '']);
        $result = (new sources())->get_result();
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('Source 2', $result->get_details());
        $this->assertSame(get_string('checksources_failed_one', 'local_nextpool'), $result->get_summary());

        $this->assertContainsOnlyInstancesOf(\core\check\check::class, local_nextpool_status_checks());
    }
}
