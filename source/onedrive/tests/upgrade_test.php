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

namespace nextpoolsource_onedrive;

/**
 * Remove pre-encryption bearer tokens from application caches (bug139).
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_nextpoolsource_onedrive_upgrade')]
final class upgrade_test extends \advanced_testcase {
    /**
     * Existing plaintext token values are removed without changing another cache.
     */
    public function test_upgrade_purges_legacy_tokens(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/nextpool/source/onedrive/db/upgrade.php');
        $tokens = \cache::make('nextpoolsource_onedrive', 'tokens');
        $tokens->set('legacy', ['token' => 'secret-bearer', 'expires' => time() + HOURSECS]);
        $backoff = \cache::make('nextpoolsource_onedrive', 'backoff');
        $backoff->set('tenant', time() + 60);
        set_config('version', 2026111403, 'nextpoolsource_onedrive');
        $this->assertTrue(xmldb_nextpoolsource_onedrive_upgrade(2026111403));
        $this->assertFalse($tokens->get('legacy'));
        $this->assertNotFalse($backoff->get('tenant'));
        $this->assertEquals(2026111550, get_config('nextpoolsource_onedrive', 'version'));
        $this->assertTrue(xmldb_nextpoolsource_onedrive_upgrade(2026111550));
    }
}
