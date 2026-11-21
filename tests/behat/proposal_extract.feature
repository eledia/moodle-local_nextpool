@local @local_nextpool
Feature: Teachers take an activity over as the content of a proposal
  In order to give back what I changed in my course without exporting files
  As a teacher
  I need to propose the activity itself as new version or new item (F07, q64, task83, test66)

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
      | location | path                   | content                      |
      | Library  | Safety/evacuation.html | <p>Meet at the car park.</p> |
    And the following "local_nextpool > items" exist:
      | title           | location | file                   | type | published |
      | Evacuation plan | Library  | Safety/evacuation.html | page | 1         |
    And the following "local_nextpool > placements" exist:
      | item            | course |
      | Evacuation plan | C1     |
    And the following "activities" exist:
      | activity | course | name      | externalurl                  |
      | url      | C1     | Fire code | https://example.org/firecode |

  @javascript @accessibility
  Scenario: Propose the placed page as new version by taking it over (F07.AC01)
    Given I am on the "Evacuation plan" "page activity" page logged in as "teacher1"
    When I navigate to "Propose a new version" in current page administration
    Then I should see "Take over from the activity"
    And "File" "field" should not be visible
    And the page should meet accessibility standards
    When I set the field "Description of the change" to "Second meeting point added."
    And I press "Submit proposal"
    Then I should see "Your proposal was submitted for review."
    And I should see "In review" in the "Evacuation plan" "table_row"
    And I log out
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Evacuation plan"
    When I press "Review proposal"
    Then I should see "Second meeting point added."
    And I should see "evacuation.html"

  @javascript
  Scenario: Propose a link activity as new item by taking it over, or upload a file instead (F07.AC10)
    Given I am on the "Fire code" "url activity" page logged in as "teacher1"
    When I navigate to "Propose as new catalogue item" in current page administration
    And I set the field "Description" to "The fire code of the city."
    And I set the field "Description of the change" to "Link to the fire code."
    And I click on "Upload a file" "radio"
    And I press "Submit proposal"
    Then I should see "Required"
    When I click on "Take over from the activity" "radio"
    And I press "Submit proposal"
    Then I should see "Your proposal was submitted for review."
    And I should see "In review" in the "Fire code" "table_row"
