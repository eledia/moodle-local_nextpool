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
use local_nextpool\local\item_manager;

/**
 * Tests for the mapping between the item form and item_manager.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_form::class)]
final class item_form_test extends \advanced_testcase {
    /**
     * Stored items and authors survive a round trip through the form data.
     */
    public function test_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_nextpool_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $user = $this->getDataGenerator()->create_user();
        $item = $generator->create_item(
            ['title' => 'Handout', 'description' => '<p>Text</p>', 'audience' => 'New staff', 'duration' => 900],
            [['userid' => $user->id, 'role' => 'Author'], ['name' => 'Jane Doe']]
        );

        $data = item_form::prepare_data($item, item_manager::get_authors($item->id));
        $this->assertSame(['text' => '<p>Text</p>', 'format' => (int) FORMAT_HTML], $data->description_editor);
        $this->assertSame('', $data->categoryid);
        $this->assertSame([(int) $user->id, ''], $data->author_userid);
        $this->assertSame(['', 'Jane Doe'], $data->author_name);

        [$fields, $authors] = item_form::extract_data($data);
        $this->assertSame('<p>Text</p>', $fields->description);
        $this->assertSame(900, $fields->duration);
        $this->assertNull($fields->categoryid);
        $this->assertSame(
            [['userid' => (int) $user->id, 'name' => '', 'role' => 'Author'], ['userid' => 0, 'name' => 'Jane Doe', 'role' => '']],
            $authors
        );

        $updated = item_manager::update($item->id, $fields, $authors);
        $this->assertSame('Handout', $updated->title);
        $this->assertCount(2, item_manager::get_authors($item->id));
    }

    /**
     * Empty selects may be missing from submitted data; they map to "not set".
     */
    public function test_extract_data_with_missing_selects(): void {
        [$fields, $authors] = item_form::extract_data((object) [
            'title' => 'Handout',
            'contextid' => 1,
            'author_name' => ['Jane Doe'],
        ]);
        $this->assertNull($fields->categoryid);
        $this->assertSame('', $fields->language);
        $this->assertSame(0, $fields->duration);
        $this->assertObjectNotHasProperty('type', $fields);
        $this->assertSame([['userid' => 0, 'name' => 'Jane Doe', 'role' => '']], $authors);
    }
}
