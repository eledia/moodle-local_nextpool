@local @local_nextpool @local_nextpool_placement_policy
Feature: Teachers change a placement's update rule from its icon menu
  In order to control updates without leaving the course overview
  As a teacher
  I need the current rule marked, a confirmation after saving and a fallback without JavaScript

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path        | content |
      | Library  | handout.pdf | draft   |
    And the following "local_nextpool > items" exist:
      | title              | location | file        | published |
      | Fire safety basics | Library  | handout.pdf | 1         |
    And the following "local_nextpool > placements" exist:
      | item               | course | policy |
      | Fire safety basics | C1     | auto   |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Catalogue content in this course (1)"

  @javascript @accessibility
  Scenario: Saving via the menu updates its visible selection and persists it (task175, q135a)
    When I click on "Update automatically: Fire safety basics" "button"
    Then "[data-policy='auto'].active[aria-current='true']" "css_element" should exist in the "Fire safety basics" "table_row"
    Given the following "local_nextpool > published versions" exist:
      | item               | content        |
      | Fire safety basics | second edition |
    When I click on "Ask me first" "link" in the "Fire safety basics" "table_row"
    Then I should see "Update policy changed."
    And "Ask me first: Fire safety basics" "button" should exist
    And I should see "Version 2 is waiting for your decision." in the "Fire safety basics" "table_row"
    When I click on "Ask me first: Fire safety basics" "button"
    Then "[data-policy='notify'].active[aria-current='true']" "css_element" should exist in the "Fire safety basics" "table_row"
    And "[data-policy='auto'].active" "css_element" should not exist in the "Fire safety basics" "table_row"
    When I click on "Keep this version" "link" in the "Fire safety basics" "table_row"
    Then "Keep this version: Fire safety basics" "button" should exist
    And I should not see "Version 2 is waiting for your decision." in the "Fire safety basics" "table_row"
    And the page should meet accessibility standards
    When I reload the page
    Then "Keep this version: Fire safety basics" "button" should exist
    And "Propose a new version: Fire safety basics" "link" should exist in the "Fire safety basics" "table_row"
    When I click on "Propose a new version: Fire safety basics" "link"
    Then I should see "Propose a new version: Fire safety basics"

  Scenario: The rule can be saved without JavaScript (task175, q135a)
    When I set the field "Update policy: Fire safety basics" to "Keep this version"
    And I press "Save changes"
    Then I should see "Update policy changed."
    And the field "Update policy: Fire safety basics" matches value "pinned"
    When I reload the page
    Then the field "Update policy: Fire safety basics" matches value "pinned"
