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

use local_nextpool\form\item_form;
use local_nextpool\form\proposal_form;
use local_nextpool\local\license_info;

/**
 * Tests for the licence fallback "All rights reserved" (task169).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(license_info::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_form::class)]
final class license_info_test extends \advanced_testcase {
    /**
     * Stored licences and the licence that applies.
     *
     * @return array
     */
    public static function effective_provider(): array {
        return [
            'empty' => ['', 'allrightsreserved', true],
            'null' => [null, 'allrightsreserved', true],
            'blank' => ['  ', 'allrightsreserved', true],
            'licence not specified' => ['unknown', 'allrightsreserved', true],
            'all rights reserved' => ['allrightsreserved', 'allrightsreserved', true],
            'creative commons' => ['cc-4.0', 'cc-4.0', false],
            'public domain' => ['public', 'public', false],
        ];
    }

    /**
     * A missing licence and "unknown" count as "All rights reserved"; other licences stay.
     *
     * @param string|null $stored Stored short name.
     * @param string $expected Effective short name.
     * @param bool $allrights Whether it is "All rights reserved".
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('effective_provider')]
    public function test_effective(?string $stored, string $expected, bool $allrights): void {
        $this->assertSame($expected, license_info::effective($stored));
        $this->assertSame($allrights, license_info::is_all_rights_reserved($stored));
    }

    /**
     * Names come from the core licences: Creative Commons shortened, others in full, the fallback named.
     */
    public function test_names(): void {
        $this->resetAfterTest();
        $this->assertSame(get_string('allrightsreserved', 'core_license'), license_info::full_name('unknown'));
        $this->assertSame(get_string('allrightsreserved', 'core_license'), license_info::short_name(''));
        $this->assertSame(get_string('cc-4.0', 'core_license'), license_info::full_name('cc-4.0'));
        $this->assertSame('CC BY 4.0', license_info::short_name('cc-4.0'));
        $this->assertSame('CC BY-NC-SA 4.0', license_info::short_name('cc-nc-sa-4.0'));
        $this->assertSame(get_string('public', 'core_license'), license_info::short_name('public'));
        // A licence the site does not know keeps its short name.
        $this->assertSame('custom-1', license_info::full_name('custom-1'));
    }

    /**
     * Site default licences and the preset for new items.
     *
     * @return array
     */
    public static function default_provider(): array {
        return [
            'no site default' => [null, 'allrightsreserved'],
            'empty site default' => ['', 'allrightsreserved'],
            'licence not specified' => ['unknown', 'allrightsreserved'],
            'creative commons' => ['cc-4.0', 'cc-4.0'],
        ];
    }

    /**
     * New items get the site default, or "All rights reserved" when there is none or it is "unknown" (task169).
     *
     * @param string|null $sitedefault Value of sitedefaultlicense, null when it is not set.
     * @param string $expected Preset licence.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('default_provider')]
    public function test_default_for_new(?string $sitedefault, string $expected): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        if ($sitedefault === null) {
            unset($CFG->sitedefaultlicense);
        } else {
            set_config('sitedefaultlicense', $sitedefault);
        }
        $this->assertSame($expected, license_info::default_for_new());

        // The item form and the proposal of a new item preset it.
        $PAGE->set_url('/local/nextpool/edit.php');
        $itemform = new item_form(null, ['id' => 0, 'locked' => false, 'sourceid' => 0, 'authorcount' => 1, 'authorusers' => []]);
        $this->assertSame([$expected], $this->license_value($itemform));
        $proposalform = new proposal_form(null, [
            'mode' => proposal_form::MODE_ITEM,
            'type' => 'xapi',
            'itemid' => 0,
            'comments' => [],
            'params' => [],
            'cm' => null,
        ]);
        $this->assertSame([$expected], $this->license_value($proposalform));
    }

    /**
     * Value of the licence menu of a form.
     *
     * @param \moodleform $form Form.
     * @return array Selected values.
     */
    private function license_value(\moodleform $form): array {
        $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        return (array) $mform->getElement('license')->getValue();
    }
}
