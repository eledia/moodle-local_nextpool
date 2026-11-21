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

use local_nextpool\local\policy;
use local_nextpool\output\update_policy;

/**
 * Visible policy selection and a usable no-JavaScript fallback (task175, q135a).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(update_policy::class)]
final class update_policy_test extends \advanced_testcase {
    /**
     * Policy menus mark exactly the current rule and include a CSRF-protected POST form.
     */
    public function test_selected_rule_and_fallback(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $url = new \moodle_url('/local/nextpool/placements.php', ['courseid' => 42]);
        $PAGE->set_url($url);
        foreach (policy::all() as $value) {
            $html = update_policy::render((object) ['id' => 12, 'policy' => $value], 'Safety & evacuation', $url);
            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            $xpath = new \DOMXPath($document);
            $selected = $xpath->query('//a[@data-policy and @aria-current="true"]');
            $this->assertCount(1, $selected);
            $this->assertSame($value, $selected->item(0)->getAttribute('data-policy'));
            $this->assertContains('active', explode(' ', $selected->item(0)->getAttribute('class')));
            $this->assertCount(3, $xpath->query('//a[@data-policy]'));
            $trigger = $xpath->query('//a[@data-region="policy-trigger"]')->item(0);
            $this->assertSame(policy::get_name($value) . ': Safety & evacuation', $trigger->getAttribute('aria-label'));
            $forms = $xpath->query('//noscript/form[@method="post"]');
            $this->assertCount(1, $forms);
            $this->assertStringEndsWith('/local/nextpool/placements.php', $forms->item(0)->getAttribute('action'));
            $this->assertSame(sesskey(), $xpath->query('//noscript/form/input[@name="sesskey"]')->item(0)->getAttribute('value'));
            $this->assertSame('42', $xpath->query('//noscript/form/input[@name="courseid"]')->item(0)->getAttribute('value'));
            $this->assertSame('12', $xpath->query('//noscript/form/input[@name="placementid"]')->item(0)->getAttribute('value'));
            $this->assertSame($value, $xpath->query('//noscript/form/select/option[@selected]')->item(0)->getAttribute('value'));
        }
    }
}
