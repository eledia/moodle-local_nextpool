@local @local_nextpool
Feature: Names of people show as text on the editorial pages
  In order to trust what the pages of the catalogue show
  As a member of the editorial team
  I need names with markup characters to appear as typed, never as markup (task140, bug122)

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname           |
      | teacher1 | Terry     | Markup             |
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
      | item               | course | user     | content  |
      | Fire safety basics | C1     | teacher1 | proposed |

  Scenario: The details page of a proposal names the proposer as text
    # The users generator strips tags from names, other channels do not.
    Given the last name of "teacher1" is stored as "<i>Markup</i> & Co"
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    When I click on "Edit label and changelog: Proposal by Terry <i>Markup</i> & Co" "link"
    Then I should see "Edit label and changelog of Proposal by Terry <i>Markup</i> & Co"
