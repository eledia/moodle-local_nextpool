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

use local_nextpool\local\status;

/**
 * Tests for the status badges of the catalogue (U-26: Moodle core badges).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(status::class)]
final class status_badge_test extends \advanced_testcase {
    /**
     * Every editorial status is a core badge that carries its name as text, with readable colours.
     */
    public function test_badge(): void {
        global $PAGE;
        $PAGE->set_url('/local/nextpool/items.php');
        foreach (status::all() as $value) {
            $html = status::badge($value);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
            $span = $dom->getElementsByTagName('span')->item(0);
            $classes = explode(' ', $span->getAttribute('class'));
            $this->assertContains('badge', $classes, $value);
            $this->assertContains('text-wrap', $classes, "$value: long texts wrap (reflow).");
            $this->assertSame(status::get_name($value), $span->textContent, 'The status name is the text, not only a colour.');
            // A pill with its own decorative icon (task166).
            $this->assertContains('rounded-pill', $classes, $value);
            $icon = $span->getElementsByTagName('i')->item(0);
            $this->assertNotNull($icon, "$value: the pill carries an icon.");
            $this->assertSame('true', $icon->getAttribute('aria-hidden'), $value);
            // Light backgrounds need dark text; white text only on the dark Bootstrap colours.
            $light = array_intersect($classes, ['bg-light', 'bg-info', 'bg-warning']);
            if ($light) {
                $this->assertContains('text-dark', $classes, $value);
            } else {
                $this->assertNotEmpty(array_intersect($classes, ['bg-success', 'bg-secondary', 'bg-primary', 'bg-danger']), $value);
            }
        }
    }

    /**
     * Every status has its own icon (task166).
     */
    public function test_icons_differ(): void {
        $icons = array_map([status::class, 'get_icon'], status::all());
        $this->assertSame(count($icons), count(array_unique($icons)));
        $this->assertSame('t/check', status::get_icon(status::PUBLISHED));
    }

    /**
     * Free status texts (placements) become core badges too, escaped.
     */
    public function test_text_badge(): void {
        $this->assertSame(
            '<span class="badge text-wrap text-start bg-warning text-dark">Version 2 &lt;b&gt;waits&lt;/b&gt;</span>',
            status::text_badge('Version 2 <b>waits</b>', 'bg-warning text-dark')
        );
    }
}
