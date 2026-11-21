@local @local_nextpool @nextpool_tours @javascript
Feature: Optional Core guided tours introduce the five Nextpool work areas
  In order to discover the catalogue and editorial workflows
  As a teacher or editor
  I need short translated tours that I can end or skip

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
      | item               | course |
      | Fire safety basics | C1     |

  @accessibility
  Scenario: A teacher completes the catalogue tour using the Core controls (task177, q136)
    Given I log in as "teacher1"
    When I open the Nextpool "catalogue" tour for "C1"
    Then I should see "Find suitable content" in the "Find suitable content" "dialogue"
    When I press tab
    Then the focused element is "Next (1/4)" "button" in the "Find suitable content" "dialogue"
    When I press "Next (1/4)"
    Then I should see "Read the content card" in the "Read the content card" "dialogue"
    When I press "Next (2/4)"
    Then I should see "Preview and give feedback" in the "Preview and give feedback" "dialogue"
    When I press "Next (3/4)"
    Then I should see "Place content in the course" in the "Place content in the course" "dialogue"
    When I press "End tour"
    Then "Place content in the course" "dialogue" should not exist
    And the page should meet accessibility standards
    When I reload the page
    Then "Find suitable content" "dialogue" should not exist

  Scenario: A teacher can skip the course placements tour (task177, q136)
    Given I log in as "teacher1"
    When I open the Nextpool "placements" tour for "C1"
    Then I should see "Keep track of placed content" in the "Keep track of placed content" "dialogue"
    When I press "Skip tour"
    Then "Keep track of placed content" "dialogue" should not exist
    When I reload the page
    Then "Keep track of placed content" "dialogue" should not exist

  Scenario: A teacher can skip the activity source tour (task177, q136)
    Given I log in as "teacher1"
    When I open the Nextpool "add" tour for "C1"
    Then I should see "Choose the content source" in the "Choose the content source" "dialogue"
    When I press "Skip tour"
    Then "Choose the content source" "dialogue" should not exist

  Scenario: An editor can skip the catalogue table tour (task177, q136)
    Given I log in as "admin"
    When I open the Nextpool "items" tour for "unused"
    Then I should see "Find editorial work" in the "Find editorial work" "dialogue"
    When I press "Skip tour"
    Then "Find editorial work" "dialogue" should not exist

  Scenario: An editor can skip the item tour (task177, q136)
    Given I log in as "admin"
    When I open the Nextpool "item" tour for "Fire safety basics"
    Then I should see "Review the catalogue item" in the "Review the catalogue item" "dialogue"
    When I press "Skip tour"
    Then "Review the catalogue item" "dialogue" should not exist

  Scenario: A tour disabled by an administrator is not displayed (task177, q136)
    Given the Nextpool "catalogue" tour is disabled
    And I log in as "teacher1"
    When I open the Nextpool "catalogue" tour for "C1"
    Then "Find suitable content" "dialogue" should not exist
    And "Place in course: Fire safety basics" "link" should exist
