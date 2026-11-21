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

/**
 * Behat steps for the browser acceptance of the catalogue pages (keyboard, focus, reflow).
 *
 * @package    local_nextpool
 * @category   test
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps for keyboard use, visible focus and reflow (WCAG 2.1.1, 2.4.7, 1.4.10).
 *
 * @package    local_nextpool
 * @category   test
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_nextpool extends behat_base {
    /**
     * Pages of a catalogue item by its title: "Item" (item.php) and "Item statistics" (statistics.php, task127).
     *
     * @param string $type Page type.
     * @param string $identifier Item title.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;
        if (strtolower($type) === 'preview') {
            [$shortname, $title] = explode('|', $identifier, 2);
            $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
            $itemid = $DB->get_field('local_nextpool_item', 'id', ['title' => $title], MUST_EXIST);
            return new moodle_url('/local/nextpool/preview.php', ['courseid' => $courseid, 'itemid' => $itemid]);
        }
        $id = (int) $DB->get_field('local_nextpool_item', 'id', ['title' => $identifier], MUST_EXIST);
        switch (strtolower($type)) {
            case 'item':
                return new moodle_url('/local/nextpool/item.php', ['id' => $id]);
            case 'item statistics':
                return new moodle_url('/local/nextpool/statistics.php', ['id' => $id]);
            default:
                throw new Exception('Unrecognised local_nextpool page type "' . $type . '."');
        }
    }

    /**
     * Press the tab key from the top of the page until the given element has the focus.
     *
     * Fails after a bounded pass through the visible tab stops (at least 80 presses).
     *
     * @When /^I tab to "(?P<element_string>(?:[^"]|\\")*)" "(?P<selector_string>[^"]*)" in the catalogue$/
     * @param string $element Element locator.
     * @param string $selectortype Selector type.
     */
    public function i_tab_to(string $element, string $selectortype): void {
        $this->require_javascript();
        $node = $this->get_selected_node($selectortype, $element);
        $xpath = json_encode($node->getXpath());
        // Reset the sequential tab starting point; blurring and scrolling retain the previous position.
        // Only the temporary start anchor is focused directly. The target must be reached with real Tab keys.
        $this->evaluate_script(<<<'JS'
            (function() {
                var start = document.createElement('span');
                start.tabIndex = -1;
                document.body.prepend(start);
                start.focus();
                start.remove();
                window.scrollTo(0, 0);
            })()
        JS);
        $isfocused = "(function() { var n = document.evaluate($xpath, document, null, " .
            "XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue; " .
            "return document.hasFocus() && n !== null && n === document.activeElement; })()";
        // Preview, feedback and placement add separate tab stops to every catalogue card.
        $maxpresses = max(80, (int) $this->evaluate_script(<<<'JS'
            Array.from(document.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')).filter(function(e) {
                return e.tabIndex >= 0 && !e.disabled && e.getClientRects().length > 0 &&
                    getComputedStyle(e).visibility !== 'hidden';
            }).length
            JS
        ) + 1);
        for ($i = 0; $i < $maxpresses; $i++) {
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
            if ($this->evaluate_script($isfocused)) {
                return;
            }
        }
        throw new ExpectationException("\"$element\" \"$selectortype\" cannot be reached with the tab key.", $this->getSession());
    }

    /**
     * Check that the element with the keyboard focus shows a focus indicator (outline or box shadow).
     *
     * @Then /^the focused element should have a visible focus indicator$/
     */
    public function the_focused_element_should_have_a_visible_focus_indicator(): void {
        $this->require_javascript();
        $result = $this->evaluate_script(<<<'JS'
            (function() {
                var e = document.activeElement;
                if (!e || e === document.body) {
                    return 'nothing has the focus';
                }
                var s = window.getComputedStyle(e);
                var outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0
                    && s.outlineColor !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(s.outlineColor);
                var shadow = s.boxShadow && s.boxShadow !== 'none';
                return (outline || shadow) ? '' : (e.outerHTML.substring(0, 200) + ' has no focus indicator (outline: ' +
                    s.outline + ', box-shadow: ' + s.boxShadow + ')');
            })()
        JS);
        if ($result !== '') {
            throw new ExpectationException($result, $this->getSession());
        }
    }

    /**
     * Check that the page fits into the viewport width (reflow, WCAG 1.4.10).
     *
     * @Then /^the page should not need horizontal scrolling$/
     */
    public function the_page_should_not_need_horizontal_scrolling(): void {
        $this->require_javascript();
        $result = $this->evaluate_script(<<<'JS'
            (function() {
                var d = document.documentElement;
                if (d.scrollWidth <= d.clientWidth + 1) {
                    return '';
                }
                var wide = [];
                document.querySelectorAll('#region-main *').forEach(function(e) {
                    var r = e.getBoundingClientRect();
                    if (r.right > d.clientWidth + 1) {
                        wide.push({right: Math.round(r.right),
                            name: e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + '.' + e.className});
                    }
                });
                wide.sort(function(a, b) {
                    return b.right - a.right;
                });
                return 'page is ' + d.scrollWidth + ' px wide in a ' + d.clientWidth + ' px viewport: ' +
                    wide.slice(0, 5).map(function(w) {
                        return w.name + ' (' + w.right + ')';
                    }).join(', ');
            })()
        JS);
        if ($result !== '') {
            throw new ExpectationException($result, $this->getSession());
        }
    }

    /**
     * Give a quiz overall feedback in two bands, completion conditions and two grade items (bug91).
     *
     * Completion: view, passing grade 5 and two attempts. Grade items: "Knowledge" for the first slot, "Skills"
     * for the third. The course must have completion enabled.
     *
     * @Given /^the quiz "(?P<name_string>(?:[^"]|\\")*)" has overall feedback, completion conditions and grade items$/
     * @param string $name Quiz name.
     */
    public function the_quiz_has_feedback_completion_and_grade_items(string $name): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $quiz = $DB->get_record('quiz', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        $DB->update_record('course_modules', (object) ['id' => $cm->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1, 'completiongradeitemnumber' => 0, 'completionpassgrade' => 1]);
        $DB->update_record('quiz', (object) ['id' => $quiz->id, 'completionminattempts' => 2]);
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'itemnumber' => 0, 'courseid' => $quiz->course]);
        $gradeitem->gradepass = $quiz->grade / 2;
        $gradeitem->update();

        $DB->insert_record('quiz_feedback', (object) ['quizid' => $quiz->id, 'feedbacktext' => '<p>Well done</p>',
            'feedbacktextformat' => FORMAT_HTML, 'mingrade' => $quiz->grade / 2, 'maxgrade' => $quiz->grade + 1]);
        $DB->insert_record('quiz_feedback', (object) ['quizid' => $quiz->id, 'feedbacktext' => '<p>Try again</p>',
            'feedbacktextformat' => FORMAT_HTML, 'mingrade' => 0, 'maxgrade' => $quiz->grade / 2]);

        $structure = \mod_quiz\quiz_settings::create((int) $quiz->id)->get_structure();
        foreach ([1 => 'Knowledge', 3 => 'Skills'] as $slot => $itemname) {
            $item = (object) ['quizid' => $quiz->id, 'name' => $itemname];
            $structure->create_grade_item($item);
            $structure->update_slot_grade_item($structure->get_slot_by_number($slot), (int) $item->id);
        }
        rebuild_course_cache((int) $quiz->course, true);
    }

    /**
     * Someone else saves a catalogue item in the meantime, for the edit lock (F08.AC09, task84).
     *
     * Only the stored state changes (who saved it, a later time), as if the person had saved the form.
     *
     * @Given /^the catalogue item "(?P<title_string>(?:[^"]|\\")*)" was saved meanwhile by "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $title Item title.
     * @param string $username Username of the person who saved it.
     */
    public function the_catalogue_item_was_saved_meanwhile_by(string $title, string $username): void {
        global $DB;
        $item = $DB->get_record('local_nextpool_item', ['title' => $title], 'id, timemodified', MUST_EXIST);
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $DB->update_record('local_nextpool_item', (object) [
            'id' => $item->id,
            'usermodified' => $userid,
            'timemodified' => (int) $item->timemodified + 60,
        ]);
    }

    /**
     * Store a last name unchanged, as channels without Moodle's cleaning can, e.g. authentication plugins (task140).
     *
     * Moodle strips tags from names it saves itself, so markup in names cannot be set up with the users generator.
     *
     * @Given /^the last name of "(?P<username_string>(?:[^"]|\\")*)" is stored as "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $username Username.
     * @param string $lastname Last name, stored as given.
     */
    public function the_last_name_is_stored_as(string $username, string $lastname): void {
        global $DB;
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $DB->set_field('user', 'lastname', $lastname, ['id' => $userid]);
    }
    /**
     * The opaque package iframe cannot be traversed by Axe; assert its accessible title before removing it.
     *
     * @Given /^I remove embedded frames from the catalogue preview$/
     */
    public function i_remove_preview_frames(): void {
        $this->require_javascript();
        $untitled = $this->evaluate_script(
            "Array.from(document.querySelectorAll('.local-nextpool-preview iframe')).some(f => !f.title.trim())"
        );
        if ($untitled) {
            throw new ExpectationException('Every preview frame must have an accessible title.', $this->getSession());
        }
        $this->execute_script("document.querySelectorAll('.local-nextpool-preview iframe').forEach(f => f.remove());");
    }
}
