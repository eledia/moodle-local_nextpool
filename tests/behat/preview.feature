@local @local_nextpool
Feature: Teachers preview catalogue content without saving learning records
  In order to choose content before placing it in a course
  As a teacher
  I need one catalogue dialog containing preview, information, placement and feedback

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path         | content                     |
      | Library  | safety.html | <p>Safety instructions.</p> |
    And the following "local_nextpool > items" exist:
      | title        | location | type | file        | published |
      | Safety guide | Library  | page | safety.html | 1         |

  @javascript @accessibility
  Scenario: Preview, feedback and keyboard close share one dialog (F16.AC01, AC05, AC14)
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I click on "Safety guide" "link"
    Then I should see "Safety instructions." in the ".modal-body" "css_element"
    And I should see "learning progress, attempts, completion and grades are not saved"
    And "Place in course" "link" should exist in the ".modal-body" "css_element"
    And the page should meet accessibility standards
    When I click on "Feedback" "button" in the ".modal-body" "css_element"
    And I set the field "New comment" to "Clear introduction."
    And I press "Post comment"
    Then I should see "Clear introduction." in the ".modal-body" "css_element"
    When I click on "Preview" "button" in the ".modal-body" "css_element"
    Then I should see "Safety instructions." in the ".modal-body" "css_element"
    When I press the escape key
    Then ".modal-body" "css_element" should not exist
    And the focused element is "Safety guide" "link"

  Scenario: A title link works without JavaScript and returns to the filtered catalogue (F16.AC07)
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I set the field "Search" to "Safety"
    And I press "Filter"
    When I follow "Safety guide"
    Then I should see "Safety instructions."
    And I should see "learning progress, attempts, completion and grades are not saved"
    And "Download" "link" should not exist
    When I follow "Back to catalogue"
    Then the field "Search" matches value "Safety"

  @javascript
  Scenario: Preview access is checked again when catalogue permission is removed (F16.AC08)
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And the following "permission overrides" exist:
      | capability                  | permission | role           | contextlevel | reference |
      | local/nextpool:viewcatalogue | Prohibit   | editingteacher | Course       | C1        |
    When I click on "Safety guide" "link"
    Then I should not see "Safety instructions."
    And I should see "you do not currently have permissions"

  @javascript @accessibility
  Scenario: The xAPI preview has a titled sandbox frame and can be closed (F16.AC01, AC15)
    Given the following "mod_nextpool > xapi items" exist:
      | title       | author     |
      | xAPI lesson | Ada Author |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I click on "xAPI lesson" "link"
    Then "iframe[title='Preview: xAPI lesson']" "css_element" should exist
    And I should see "Ada Author" in the ".modal-body" "css_element"
    When I remove embedded frames from the catalogue preview
    Then the page should meet accessibility standards
    When I press the escape key
    Then the focused element is "xAPI lesson" "link"

  @javascript @accessibility
  Scenario: Quiz preview shows question text without answers or attempts (F16.AC11)
    Given the following "local_nextpool > storage files" exist:
      | location | path     | fixturezip                        |
      | Library  | quiz.zip | local/nextpool/tests/fixtures/quiz |
    And the following "local_nextpool > items" exist:
      | title       | location | type | file     | published |
      | Safety quiz | Library  | quiz | quiz.zip | 1         |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I click on "Safety quiz" "link"
    Then I should see "What is the capital of France?" in the ".modal-body" "css_element"
    And I should not see "Paris" in the ".modal-body" "css_element"
    And I should see "3 questions" in the ".modal-body" "css_element"
    And the page should meet accessibility standards
