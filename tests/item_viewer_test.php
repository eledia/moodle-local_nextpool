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

use local_nextpool\local\item_viewer;
use local_nextpool\local\placement_manager;
use local_nextpool\local\policy;
use local_nextpool\local\version_manager;

/**
 * Tests for the "View" target of catalogue items in the editorial lists.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_viewer::class)]
final class item_viewer_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * A link item opens its address, even before a version is published.
     */
    public function test_link_item_opens_its_address(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'glossary.url', "[InternetShortcut]\nURL=https://example.com/glossary\n");
        $item = $this->generator->create_item(['sourceid' => $source->get_id(), 'type' => 'url']);
        $this->assertNull(item_viewer::get_url($item));

        version_manager::create_from_source((int) $item->id, 'glossary.url');
        $this->assertSame('https://example.com/glossary', item_viewer::get_url($item)->out(false));
    }

    /**
     * Other items open their placement, but only one the user can see.
     */
    public function test_item_opens_a_visible_placement(): void {
        $item = $this->generator->create_published_item();
        $this->assertNull(item_viewer::get_url($item), 'Without a placement there is nothing to open.');

        $course = $this->getDataGenerator()->create_course();
        $placement = placement_manager::place((int) $item->id, (int) $course->id, 0, policy::NOTIFY);
        $url = item_viewer::get_url($item);
        $this->assertNotNull($url);
        $this->assertSame((int) $placement->cmid, (int) $url->param('id'));

        // A user who cannot see the course gets no target.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull(item_viewer::get_url($item));
    }
}
