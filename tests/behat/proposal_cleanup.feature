@local @local_nextpool
Feature: The editorial team deletes rejected and cancelled proposals
  In order to keep only proposals that still matter
  As a member of the editorial team
  I need to delete a rejected proposal before the retention period of 12 months ends (F07, q67, task86, test60)

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
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
    And the following "local_nextpool > items" exist:
      | title              | location | file               | published |
      | Fire safety basics | Library  | Safety/handout.pdf | 1         |
    And the following "local_nextpool > proposals" exist:
      | item               | course | user     | content  | status   | reason    |
      | Fire safety basics | C1     | teacher1 | proposed | rejected | Too long. |

  Scenario: Delete a rejected proposal from its review page
    Given I log in as "admin"
    And I visit "/local/nextpool/reviews.php"
    And I follow "Completed reviews"
    And I should see "Rejected" in the "Proposal by Terry Teacher" "table_row"
    When I follow "Proposal by Terry Teacher"
    Then I should see "Too long."
    When I press "Delete proposal"
    Then I should see "with its file and history? This cannot be undone."
    When I press "Delete proposal"
    Then I should see "was deleted."
    And I should see "Fire safety basics"
    And I visit "/local/nextpool/reviews.php"
    And I follow "Completed reviews"
    And I should not see "Proposal by Terry Teacher"

  Scenario: The proposing person cannot delete the rejected proposal
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Content catalogue" in current page administration
    When I follow "My proposals"
    And I follow "Proposal by Terry Teacher"
    Then I should see "Too long."
    And "Delete proposal" "button" should not exist
