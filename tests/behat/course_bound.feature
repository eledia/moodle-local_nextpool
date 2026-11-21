@local @local_nextpool
Feature: Items uploaded from a course are available only in that course until the editors widen them
  In order to keep a teacher's own package within their course
  As a content editor
  I need to see items uploaded from courses and decide whether other courses may use them (M06, test90)

  Background:
    Given the following "categories" exist:
      | name       | category | idnumber |
      | Faculty A  | 0        | FA       |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | FA       |
      | Course 2 | C2        | FA       |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C2     | editingteacher |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Uploads |
    And the following "local_nextpool > storage files" exist:
      | location | path             | fixture                                             |
      | Uploads  | kurs/package.zip | mod/nextpool/tests/fixtures/upload/fire-safety.zip |
    And the following "local_nextpool > items" exist:
      | title             | location | type | file             | published | course |
      | Own course module | Uploads  | xapi | kurs/package.zip | 1         | C1     |

  Scenario: Only the own course finds the item in the catalogue
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I navigate to "Content catalogue" in current page administration
    Then I should see "Own course module"
    And I am on the "C2" "Course" page
    And I navigate to "Content catalogue" in current page administration
    And I should not see "Own course module"

  Scenario: Editors see the origin, filter by it and widen the item to the whole site
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    Then I should see "From course" in the "Own course module" "table_row"
    And I should see "Without placement" in the "Own course module" "table_row"
    And "//a[@aria-label='Uploaded from course: Course 1']" "xpath_element" should exist in the "Own course module" "table_row"
    When I set the field "Origin" to "Uploaded from a course"
    And I press "Filter"
    Then I should see "Own course module"
    When I follow "Own course module"
    Then "[title='Available in: Only course: Course 1']" "css_element" should exist in the ".local-nextpool-itemfacts" "css_element"
    When I click on "Edit" "link" in the ".local-nextpool-item__iconactions" "css_element"
    And I set the field "Available in" to "Whole site"
    And I press "Save changes"
    Then "[title='Available in: Whole site']" "css_element" should exist in the ".local-nextpool-itemfacts" "css_element"
    And I should see "Uploaded from course: Course 1"
    And I am on the "C2" "Course" page
    And I navigate to "Content catalogue" in current page administration
    And I should see "Own course module"

  Scenario: The item stays withdrawn in the category after its course is deleted (M06.AC11, test95)
    Given I log in as "admin"
    And I go to the courses management page
    And I click on category "Faculty A" in the management interface
    And I click on "delete" action for "Course 1" in management course listing
    And I press "Delete"
    And I should see "C1 has been completely deleted"
    When I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    Then "//span[@title='Uploaded from course: Deleted course']" "xpath_element" should exist in the "Own course module" "table_row"
    And "//a[contains(@class, 'local-nextpool-originpill')]" "xpath_element" should not exist in the "Own course module" "table_row"
    And I should see "Withdrawn" in the "Own course module" "table_row"
    And I should see "Without placement" in the "Own course module" "table_row"
    When I follow "Own course module"
    Then I should see "Faculty A"
    And I should see "Uploaded from course: Deleted course"
