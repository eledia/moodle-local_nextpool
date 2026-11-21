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

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use local_nextpool\local\tour_manager;
use tool_usertours\tour;

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Tour fixtures and direct target navigation (task177).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_nextpool_tours extends behat_base {
    /** @var bool Whether this scenario needs the ordinary workflow fixture. */
    private bool $disabletours = false;
    /** @var bool Whether the fixture has been applied after Core's scenario reset. */
    private bool $configured = false;

    /**
     * Record scenario tags without relying on ordering relative to Core's reset hook.
     *
     * @BeforeScenario
     * @param BeforeScenarioScope $scope Scenario.
     */
    public function prepare_tour_fixture(BeforeScenarioScope $scope): void {
        $tags = array_merge($scope->getFeature()->getTags(), $scope->getScenario()->getTags());
        $this->disabletours = !in_array('nextpool_tours', $tags)
            && (in_array('local_nextpool', $tags) || in_array('mod_nextpool', $tags));
        $this->configured = false;
    }

    /**
     * Existing workflow scenarios run with our optional tours disabled, as an administrator can configure.
     * Explicit tour scenarios exercise the installed enabled defaults instead. No unrelated tour is changed.
     *
     * @BeforeStep
     * @param BeforeStepScope $scope Step.
     */
    public function apply_tour_fixture(BeforeStepScope $scope): void {
        global $DB;
        if ($this->configured) {
            return;
        }
        $this->configured = true;
        if (!$this->disabletours) {
            return;
        }
        foreach (tour_manager::TOURS as $key) {
            $id = get_config('local_nextpool', 'tour_installed_' . $key);
            if ($id && $DB->record_exists('tool_usertours_tours', ['id' => $id])) {
                tour::instance($id)->set_enabled(false)->persist();
            }
        }
    }

    /**
     * Open a tour's real target: identifier is a course shortname, an item title, or unused for the item list.
     *
     * @When /^I open the Nextpool "(catalogue|placements|add|items|item)" tour for "([^"]*)"$/
     * @param string $key Tour key.
     * @param string $identifier Course shortname or item title.
     */
    public function open_tour_target(string $key, string $identifier): void {
        global $DB;
        if ($key === 'items') {
            $url = new moodle_url('/local/nextpool/items.php');
        } else if ($key === 'item') {
            $id = $DB->get_field('local_nextpool_item', 'id', ['title' => $identifier], MUST_EXIST);
            $url = new moodle_url('/local/nextpool/item.php', ['id' => $id]);
        } else {
            $id = $DB->get_field('course', 'id', ['shortname' => $identifier], MUST_EXIST);
            $url = $key === 'add'
                ? new moodle_url('/mod/nextpool/add.php', ['course' => $id, 'section' => 0])
                : new moodle_url('/local/nextpool/' . $key . '.php', ['courseid' => $id]);
        }
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Apply the same disabled flag as the administrator's Core tour settings page.
     *
     * @Given /^the Nextpool "(catalogue|placements|add|items|item)" tour is disabled$/
     * @param string $key Tour key.
     */
    public function disable_tour(string $key): void {
        $id = get_config('local_nextpool', 'tour_installed_' . $key);
        tour::instance($id)->set_enabled(false)->persist();
    }
}
