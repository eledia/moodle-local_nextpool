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

// NOTE: no MOODLE_INTERNAL test here, Behat may require this before /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Browser assertions for accessible action targets.
 *
 * @package    local_nextpool
 * @category   test
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_nextpool_accessibility extends behat_base {
    /**
     * A pointer target meets the approved minimum size, independently of the icon drawn inside it.
     *
     * @Then /^the "(?P<element_string>(?:[^\"]|\\\")*)" "(?P<selector_string>[^\"]*)" target should be at least 44 by 44 pixels$/
     * @param string $element Element locator.
     * @param string $selectortype Selector type.
     */
    public function target_is_large_enough(string $element, string $selectortype): void {
        $this->require_javascript();
        $node = $this->get_selected_node($selectortype, $element);
        $xpath = json_encode($node->getXpath());
        $size = $this->evaluate_script('(function() { var n = document.evaluate(' . $xpath . ', document, null, ' .
            'XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue; var r = n.getBoundingClientRect();' .
            'return {width:r.width,height:r.height}; })()');
        if ($size['width'] < 44 || $size['height'] < 44) {
            throw new \Behat\Mink\Exception\ExpectationException(
                $element . ' has a target of ' . $size['width'] . ' by ' . $size['height'] . ' pixels.',
                $this->getSession()
            );
        }
    }
}
