@local @local_nextpool
Feature: Reviewers see what waits for review and share the work
  In order to know what waits and to share the reviews
  As a reviewer
  I need an overview "Waiting for review" with "I review" and "Assign" (F07, task85, test68)

  Background:
    Given the following "categories" exist:
      | name       | category | idnumber |
      | Category A | 0        | CATA     |
      | Category B | 0        | CATB     |
    And the following "users" exist:
      | username   | firstname | lastname |
      | reviewera  | Reviewer  | A        |
      | reviewera2 | Reviewer  | A2       |
      | reviewerb  | Reviewer  | B        |
    And the following "roles" exist:
      | shortname | name     | archetype |
      | reviewer  | Reviewer |           |
    And the following "permission overrides" exist:
      | capability                    | permission | role     | contextlevel | reference |
      | local/nextpool:reviewversions | Allow      | reviewer | System       |           |
    And the following "role assigns" exist:
      | user       | role     | contextlevel | reference |
      | reviewera  | reviewer | Category     | CATA      |
      | reviewera2 | reviewer | Category     | CATA      |
      | reviewerb  | reviewer | Category     | CATB      |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path   | content |
      | Library  | s.pdf  | site    |
      | Library  | a.pdf  | a       |
      | Library  | b.pdf  | b       |
    And the following "local_nextpool > items" exist:
      | title         | location | file  | published | category |
      | Safety basics | Library  | s.pdf | 1         |          |
      | Item A        | Library  | a.pdf | 1         | CATA     |
      | Item B        | Library  | b.pdf | 1         | CATB     |
    And the following "local_nextpool > versions in review" exist:
      | item          | content    | days |
      | Safety basics | site v2    | 5    |
      | Item A        | a v2       | 3    |
      | Item B        | b v2       | 1    |

  @javascript @accessibility
  Scenario: Reviewers of a category see its items and site-wide items, take and assign reviews
    Given I log in as "reviewera"
    When I follow "Waiting for review (2)" in the user menu
    Then I should see "Safety basics" in the "reportbuilder-table" "table"
    And I should see "Item A" in the "reportbuilder-table" "table"
    And I should not see "Item B" in the "reportbuilder-table" "table"
    And "Safety basics" "table_row" should appear before "Item A" "table_row"
    And I should see "Draft of the editorial team" in the "Item A" "table_row"
    And I should see "Not assigned" in the "Item A" "table_row"
    And the page should meet accessibility standards
    When I press "I review: Safety basics, Version 2"
    Then I should see "You review Version 2 of \"Safety basics\"."
    And I should see "Reviewer A" in the "Safety basics" "table_row"
    When I click on "Assign: Item A, Version 2" "link"
    Then I should see "Assign review: Version 2 of \"Item A\""
    And the "Assigned to" select box should contain "Reviewer A2"
    And the "Assigned to" select box should not contain "Reviewer B"
    And the page should meet accessibility standards
    When I set the field "Assigned to" to "Reviewer A2"
    And I press "Assign"
    Then I should see "Version 2 of \"Item A\" is now assigned to Reviewer A2."
    And I should see "Reviewer A2" in the "Item A" "table_row"
    When I click on "Filters" "button"
    And I set the following fields in the "Assignment" "core_reportbuilder > Filter" to these values:
      | Assignment operator | Is equal to    |
      | Assignment value    | Assigned to me |
    And I click on "Apply" "button" in the "[data-region='report-filters']" "css_element"
    Then I should see "Safety basics" in the "reportbuilder-table" "table"
    And I should not see "Item A" in the "reportbuilder-table" "table"

  Scenario: A reviewer of another category publishes a site-wide item and finds it under completed reviews
    Given I log in as "reviewerb"
    And I follow "Waiting for review (2)" in the user menu
    And I should see "Safety basics"
    And I should see "Item B"
    And I should not see "Item A"
    When I click on "Review: Safety basics, Version 2" "link"
    And I press "Publish version 2"
    Then I should see "Version 2 was published."
    When I follow "Waiting for review (1)" in the user menu
    Then I should not see "Safety basics"
    When I follow "Completed reviews"
    Then I should see "Safety basics"
    And I should see "Published" in the "Safety basics" "table_row"
    And I should see "Reviewer B" in the "Safety basics" "table_row"
