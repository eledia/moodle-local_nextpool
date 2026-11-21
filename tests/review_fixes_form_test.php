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
use local_nextpool\form\source_form;
use local_nextpool\local\item_types;

/**
 * Tests for the form fixes from the live test on demo.eledia.ai (bug26, bug27, bug28).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_types::class)]
final class review_fixes_form_test extends \advanced_testcase {
    /**
     * A Nextcloud storage location needs a base folder; the Moodle file storage does not (bug26).
     */
    public function test_basefolder_required_for_nextcloud(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $nextcloud = new source_form(null, [
            'plugin' => 'nextcloud',
            'class' => \nextpoolsource_nextcloud\source::class,
            'existing' => [],
        ]);
        $errors = $nextcloud->validation(['name' => 'Cloud', 'basefolder' => ' / '], []);
        $this->assertSame(get_string('error_basefolderrequired', 'local_nextpool'), $errors['basefolder']);
        $errors = $nextcloud->validation(['name' => 'Cloud', 'basefolder' => 'nextpool'], []);
        $this->assertArrayNotHasKey('basefolder', $errors);

        $moodle = new source_form(null, [
            'plugin' => 'moodle',
            'class' => \nextpoolsource_moodle\source::class,
            'existing' => [],
        ]);
        $this->assertArrayNotHasKey('basefolder', $moodle->validation(['name' => 'Moodle', 'basefolder' => ''], []));
    }

    /**
     * New items propose a placeable type; types without handler say they cannot be placed yet (bug27).
     */
    public function test_type_menu(): void {
        $this->assertTrue(item_types::is_placeable(item_types::get_default()));
        $menu = item_types::get_menu();
        $suffix = get_string('type_noplacement', 'local_nextpool', '');
        foreach (item_types::all() as $type) {
            $this->assertSame(!item_types::is_placeable($type), str_contains($menu[$type], trim($suffix)), $type);
        }
    }

    /**
     * The heading of each author block carries its number (bug28).
     */
    public function test_author_heading_numbered(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/edit.php');
        $form = new item_form(null, ['id' => 0, 'locked' => false, 'sourceid' => 0, 'authorcount' => 2, 'authorusers' => []]);
        $html = $form->render();
        $this->assertStringContainsString(get_string('authorno', 'local_nextpool', 1), $html);
        $this->assertStringContainsString(get_string('authorno', 'local_nextpool', 2), $html);
        $this->assertStringNotContainsString('{no}', $html);
    }

    /**
     * Every field of a repeated row names its row, and each row has a remove button (bug65, bug71).
     */
    public function test_repeated_rows_named_and_removable(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/edit.php');
        $form = new item_form(null, ['id' => 0, 'locked' => false, 'sourceid' => 0, 'authorcount' => 2, 'authorusers' => [],
            'competencycount' => 2]);
        $html = $form->render();
        $keys = ['authoruserno', 'authornameno', 'authorroleno', 'authorremove', 'competencyno', 'competencylevelno',
            'competencyfunctionno', 'competencyobjectivesno', 'competencyremove'];
        foreach ([1, 2] as $no) {
            foreach ($keys as $key) {
                $this->assertStringContainsString(s(get_string($key, 'local_nextpool', $no)), $html, $key . ' ' . $no);
            }
        }
        $this->assertStringNotContainsString('{no}', $html);
    }

    /**
     * A removed author row is left out of the form, the other rows stay.
     */
    public function test_removed_row_left_out(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/edit.php');
        item_form::mock_submit(['author_repeats' => 2, 'author_name' => ['Ann', 'Bob'], 'author_delete-hidden' => [1 => 1]]);
        $form = new item_form(null, ['id' => 0, 'locked' => false, 'sourceid' => 0, 'authorcount' => 2, 'authorusers' => []]);
        $html = $form->render();
        $this->assertStringContainsString('name="author_name[0]"', $html);
        $this->assertStringNotContainsString('name="author_name[1]"', $html);
    }

    /**
     * Licences with a dot in their shortname survive the form (bug82, demo.eledia.ai).
     */
    public function test_license_with_dot(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/edit.php');
        item_form::mock_submit(['license' => 'cc-4.0']);
        $form = new item_form(null, ['id' => 0, 'locked' => false, 'sourceid' => 0, 'authorcount' => 1, 'authorusers' => []]);
        $this->assertSame('cc-4.0', $form->get_submitted_data()->license);
    }
}
