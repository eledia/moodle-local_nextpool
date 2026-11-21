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

/**
 * Tests for the web service local_nextpool_search_competencies (F14, bug52).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(search_competencies::class)]
final class search_competencies_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $lp = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $lp->create_framework(['shortname' => 'Safety']);
        $lp->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'SAFE-01',
            'shortname' => 'Fire basics']);
    }

    /**
     * Call the function as the form does, including the return value cleaning.
     *
     * @param string $query Query.
     * @return array
     */
    private function call(string $query): array {
        $_POST['sesskey'] = sesskey();
        $result = external_api::call_external_function('local_nextpool_search_competencies', ['query' => $query], true);
        $this->assertFalse($result['error'], json_encode($result['exception'] ?? null));
        return $result['data'];
    }

    /**
     * An editor of a course category finds the competencies through the Ajax service.
     */
    public function test_editor_in_category_searches(): void {
        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/nextpool:manageitems', CAP_ALLOW, $roleid, \context_system::instance());
        $this->getDataGenerator()->role_assign($roleid, $user->id, \context_coursecat::instance($category->id));
        $this->setUser($user);

        $data = $this->call('fire');
        $this->assertSame(
            [['idnumber' => 'SAFE-01', 'label' => s('Fire basics (SAFE-01) – Safety')]],
            $data['competencies']
        );
    }

    /**
     * Users who may not edit items get no competencies.
     */
    public function test_requires_item_editing(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        search_competencies::execute('fire');
    }
}
