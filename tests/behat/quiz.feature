@local @local_nextpool @mod_quiz
Feature: Teachers place quizzes from the catalogue and keep attempts when a new version comes
  In order to use maintained quizzes without losing attempts or grades
  As a teacher
  I need to place a catalogue quiz and decide what happens when a new version changes its questions

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
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
      | location | path             | fixturezip                        |
      | Library  | Quizzes/quiz.zip | local/nextpool/tests/fixtures/quiz |
    And the following "local_nextpool > items" exist:
      | title       | location | type | file             | published |
      | Safety quiz | Library  | quiz | Quizzes/quiz.zip | 1         |

  @javascript @accessibility
  Scenario: A new version with another question becomes a new quiz next to the old one
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Place in course"
    And I set the field "Update policy" to "Ask me first"
    And I press "Place in course"
    And I should see "was added to the course."
    And the quiz "Safety quiz" has overall feedback, completion conditions and grade items
    And user "student1" has attempted "Safety quiz" with responses:
      | slot | response |
      | 1    | True     |
      | 2    | Paris    |
    And the following "local_nextpool > published versions" exist:
      | item        | fixturezip                            |
      | Safety quiz | local/nextpool/tests/fixtures/quiz-v2 |
    And I run all adhoc tasks
    And I am on the "C1" "Course" page
    And I follow "Review updates"
    When I press "Apply version 2"
    Then I should see "questions, their order, pages or marks change, and the quiz has attempts."
    And I should see "Version 2 is waiting for your decision." in the "Safety quiz" "table_row"
    And the page should meet accessibility standards
    When I press "Place version 2 as a new quiz"
    Then I should see "Version 2 was placed as a new activity."
    And I should see "Up to date" in the "Safety quiz" "table_row"
    And the page should meet accessibility standards
    # The placement now points to the new quiz, which has no attempts yet; the old one is hidden.
    When I click on "Safety quiz" "link" in the "Safety quiz" "table_row"
    Then I should not see "Attempts: 1"
    # Completion, grade items and overall feedback of the old quiz carry over (bug91).
    And I should see "Receive a passing grade"
    And I should see "Make attempts: 2"
    And I navigate to "Questions" in current page administration
    And I set the field "Quiz setup navigation" to "Grade items setup"
    And the field "Earth is round" matches value "Knowledge"
    And the field "Capital of France" matches value "[none]"
    And the field "Primary colours" matches value "Skills"
    And the page should meet accessibility standards
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And the field "id_feedbackboundaries_0" matches value "50%"
    And the field "Grade to pass" matches value "5.00"
    And I am on the "C1" "Course" page
    And I should see "Hidden from students" in the "Safety quiz" "activity"

