@local @local_nextpool
Feature: Teachers place catalogue content in courses and receive updates
  In order to use maintained content without losing learner progress
  As a teacher
  I need to place catalogue items in my course and get new versions according to an update policy

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
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
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
    And the following "local_nextpool > items" exist:
      | title              | location | file               | published |
      | Fire safety basics | Library  | Safety/handout.pdf | 1         |

  @javascript @accessibility
  Scenario: The catalogue card is compact and the place action is an icon with the item in its name
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I navigate to "Content catalogue" in current page administration
    Then I should see "Fire safety basics" in the ".local-nextpool-catalogue" "css_element"
    And I should see "Direct" in the ".local-nextpool-catalogue" "css_element"
    And I should not see "License not specified" in the ".local-nextpool-catalogue" "css_element"
    And ".local-nextpool-catalogue dt" "css_element" should not exist
    And "Place in course: Fire safety basics" "link" should exist
    And the page should meet accessibility standards
    And I follow "Place in course: Fire safety basics"
    And I should see "Update policy"

  Scenario: Placed content is updated automatically in the same activity
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I should see "Fire safety basics"
    When I follow "Place in course"
    And I set the field "Update policy" to "Update automatically"
    And I press "Place in course"
    Then I should see "was added to the course."
    And I should see "Fire safety basics" in the "region-main" "region"
    And the following "local_nextpool > published versions" exist:
      | item               | content        |
      | Fire safety basics | second edition |
    And I run all adhoc tasks
    When I am on the "C1" "Course" page
    Then I should not see "an update waiting for you"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Catalogue content in this course (1)"
    And I should see "Version 2" in the "Fire safety basics" "table_row"
    And I should see "Up to date" in the "Fire safety basics" "table_row"

  Scenario: With "Ask me first" the teacher decides when to update
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Place in course"
    And I set the field "Update policy" to "Ask me first"
    And I press "Place in course"
    And the following "local_nextpool > published versions" exist:
      | item               | content        |
      | Fire safety basics | second edition |
    And I run all adhoc tasks
    When I am on the "C1" "Course" page
    Then I should see "One activity from the content catalogue has an update waiting for you."
    When I follow "Review updates"
    Then I should see "Version 2 is waiting for your decision." in the "Fire safety basics" "table_row"
    And ".badge.bg-warning" "css_element" should exist in the "Fire safety basics" "table_row"
    And I should see "Version 1" in the "Fire safety basics" "table_row"
    When I press "Apply version 2"
    Then I should see "The activity now uses version 2."
    And I should see "Up to date" in the "Fire safety basics" "table_row"
    When I am on the "C1" "Course" page
    Then I should not see "an update waiting for you"

  @javascript @accessibility
  Scenario: The teacher pages meet accessibility standards
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    Then the page should meet accessibility standards
    When I follow "Place in course"
    Then the page should meet accessibility standards
    When I press "Place in course"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Catalogue content in this course (1)"
    Then the page should meet accessibility standards

  @javascript @accessibility
  Scenario: The status badges of the placements stay readable
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Place in course"
    And I set the field "Update policy" to "Ask me first"
    And I press "Place in course"
    And the following "local_nextpool > published versions" exist:
      | item               | content        |
      | Fire safety basics | second edition |
    And I run all adhoc tasks
    When I am on the "C1" "Course" page
    And I navigate to "Content catalogue" in current page administration
    And I follow "Catalogue content in this course (1)"
    Then I should see "Version 2 is waiting for your decision." in the ".badge.bg-warning" "css_element"
    And the page should meet accessibility standards with "wcag143" extra tests
