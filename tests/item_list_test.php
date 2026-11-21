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

use local_nextpool\local\item_manager;
use local_nextpool\local\version_manager;
use local_nextpool\output\item_list;
use local_nextpool\output\next_step;
use local_nextpool\output\table_actions;
use local_nextpool\output\type_icon;

/**
 * Compact cells and row actions of the editorial item list (task166, task167).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_list::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(type_icon::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(table_actions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(next_step::class)]
final class item_list_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/items.php');
        $PAGE->set_context(\context_system::instance());
    }

    /**
     * The type icon carries the type name as tooltip and hidden text, the short name for touch and narrow screens.
     */
    public function test_type_icon(): void {
        $html = type_icon::render('file');
        $this->assertStringContainsString('title="' . s(get_string('type_file', 'local_nextpool')) . '"', $html);
        $this->assertStringContainsString(
            '<span class="visually-hidden">' . s(get_string('type_file', 'local_nextpool')) . '</span>',
            $html
        );
        $this->assertStringContainsString(get_string('typeshort_file', 'local_nextpool'), $html);
        $this->assertStringContainsString('activityiconcontainer', $html);
        // The icon itself is decorative.
        $this->assertStringContainsString('class="activityiconcontainer smaller', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        // An unknown type keeps its identifier as name, escaped.
        $this->assertStringContainsString('&lt;b&gt;', type_icon::render('<b>'));
    }

    /**
     * The origin course is a pill linked to the course; a deleted course stays named, without a link.
     */
    public function test_origin_pill(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Fire & safety']);
        $item = (object) ['origincourseid' => $course->id];
        $courses = [$course->id => $course];

        $html = item_list::origin_pill($item, $courses);
        $this->assertStringContainsString('<a ', $html);
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $html);
        $this->assertStringContainsString('aria-label="Uploaded from course: Fire &amp; safety"', $html);
        $this->assertStringContainsString('rounded-pill', $html);
        $this->assertStringContainsString('>From course<', $html);

        // A user who cannot enter the course sees the pill without a link.
        $this->setUser($this->getDataGenerator()->create_user());
        $html = item_list::origin_pill($item, $courses);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('<span class="visually-hidden">Uploaded from course: Fire &amp; safety</span>', $html);

        // Deleted course (TD-409).
        $this->setAdminUser();
        delete_course($course, false);
        $html = item_list::origin_pill($item, []);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('Uploaded from course: Deleted course', $html);

        $this->assertSame('', item_list::origin_pill((object) ['origincourseid' => null], $courses));
    }

    /**
     * The category is a pill with the formatted path, not escaped a second time.
     */
    public function test_category_pill(): void {
        $html = item_list::category_pill('Safety / Fire &amp; smoke');
        $this->assertStringContainsString('rounded-pill', $html);
        $this->assertStringContainsString('Safety / Fire &amp; smoke</span>', $html);
    }

    /**
     * A single rare action is an icon link, a menu only from two on (task166, task167).
     */
    public function test_more(): void {
        $url = new \moodle_url('/local/nextpool/item.php', ['id' => 1, 'action' => 'itemstatus', 'to' => 'withdrawn']);
        $withdraw = new \action_menu_link_secondary($url, new \pix_icon('t/hide', ''), 'Withdraw item');
        $delete = new \action_menu_link_secondary(new \moodle_url('/x.php'), new \pix_icon('t/delete', ''), 'Delete');

        $single = table_actions::more([$withdraw], 'Fire handout');
        $this->assertStringNotContainsString('dropdown', $single);
        $this->assertStringContainsString('href="' . $url->out() . '"', $single);
        $this->assertStringContainsString('title="Withdraw item"', $single);
        $this->assertStringContainsString('<span class="visually-hidden">Withdraw item: Fire handout</span>', $single);
        $this->assertStringNotContainsString('sesskey', $single, 'The link opens the confirmation, which posts.');

        $menu = table_actions::more([$withdraw, $delete], 'Fire handout');
        $this->assertStringContainsString('dropdown', $menu);
        $this->assertStringContainsString(s(get_string('moreactions')), $menu);

        $this->assertSame('', table_actions::more([]));
    }

    /**
     * Submitting for review uses the send icon, not the upload icon of "Upload file" (bug232).
     */
    public function test_submit_icon(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $source = $generator->create_source();
        $generator->put_file($source, 'handout.pdf', 'one');
        $item = $generator->create_item(['sourceid' => $source->get_id()]);
        version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $step = next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id));
        $this->assertSame('t/sendmessage', $step->icon);
        $this->assertSame('t/sendmessage', next_step::for_item($generator->create_item(), null)->icon);
    }
}
