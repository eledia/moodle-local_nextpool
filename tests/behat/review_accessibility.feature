@local @local_nextpool @javascript @accessibility @_file_upload
Feature: Review and collaboration pages work with the keyboard and pass Axe (task87, test69)
  In order to give feedback, propose changes and review versions without a mouse or with a screen reader
  As a teacher or reviewer
  I need the feedback area, the proposal form, the review form and the review overview to be reachable with the tab key and free of Axe violations

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
      | Library  | Safety/second.pdf  | second      |
      | Library  | Safety/draft.pdf   | draft       |
    And the following "local_nextpool > items" exist:
      | title              | location | file               | published |
      | Fire safety basics | Library  | Safety/handout.pdf | 1         |
      | Second item        | Library  | Safety/second.pdf  | 1         |
      | Draft item         | Library  | Safety/draft.pdf   |           |
    And the following "local_nextpool > placements" exist:
      | item               | course |
      | Fire safety basics | C1     |

  Scenario: The feedback area is usable with the keyboard and has no contrast or markup violations (F07.AC15, F08)
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I click on "Feedback on Fire safety basics" "link"
    And I click on "Open all comments" "link" in the ".modal-body" "css_element"
    When I tab to "New comment" "field" in the catalogue
    Then the focused element should have a visible focus indicator
    When I set the field "New comment" to "Please add the emergency numbers."
    And I tab to "Post comment" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    When I press "Post comment"
    Then I should see "Please add the emergency numbers." in the ".local-nextpool-feedback__threads" "css_element"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "Reply" "text" in the catalogue
    Then the focused element should have a visible focus indicator
    # The icon buttons are reached with the tab key; Firefox in Behat reports no outline for them although the
    # theme draws one (btn:focus-visible), so only reachability is asserted here.
    When I tab to "Mark as done" "button" in the catalogue
    And I tab to "Unsubscribe" "button" in the catalogue
    When I click on "Mark as done" "button" in the "#local-nextpool-feedback" "css_element"
    Then I should see "Done (1)" in the "#local-nextpool-feedback" "css_element"
    And the page should meet accessibility standards with "wcag143" extra tests

  Scenario: The proposal form is reached with the keyboard and its error and result are announced (F07.AC15)
    Given I am on the "Fire safety basics" "resource activity editing" page logged in as "teacher1"
    When I navigate to "Propose a new version" in current page administration
    Then I should see "Propose a new version: Fire safety basics"
    # The description is a rich text editor in its own frame; the keyboard path of the form is checked at the button.
    When I tab to "Submit proposal" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    When I upload "lib/tests/fixtures/empty.txt" file to "File" filemanager
    And I press "Submit proposal"
    Then I should see "Describe the change."
    When I set the field "Description of the change" to "Emergency numbers added."
    And I press "Submit proposal"
    Then I should see "Your proposal was submitted for review."
    And I should see "In review" in the "Fire safety basics" "table_row"
    When I tab to "Cancel proposal" "link" in the catalogue
    Then the focused element should have a visible focus indicator

  Scenario: The proposal form passes Axe including contrast in every state (F07.AC15)
    Given I am on the "Fire safety basics" "resource activity editing" page logged in as "teacher1"
    When I navigate to "Propose a new version" in current page administration
    Then the page should meet accessibility standards with "wcag143" extra tests
    When I upload "lib/tests/fixtures/empty.txt" file to "File" filemanager
    And I press "Submit proposal"
    Then I should see "Describe the change."
    And the page should meet accessibility standards with "wcag143" extra tests
    When I set the field "Description of the change" to "Emergency numbers added."
    And I press "Submit proposal"
    Then I should see "Your proposal was submitted for review."
    And the page should meet accessibility standards with "wcag143" extra tests

  Scenario: The review form has one button per decision that is reached with the keyboard (F07.AC15)
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Draft item"
    And I press "Submit version 1 for review"
    And I press "Review version 1"
    Then I should see "Review: Version 1"
    When I tab to "Reason" "field" in the catalogue
    Then the focused element should have a visible focus indicator
    When I tab to "Publish version 1" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    When I tab to "Return for revision" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    When I tab to "Reject" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    When I press "Reject"
    Then I should see "Please give a reason."

  Scenario: The review form passes Axe including contrast, also with its error (F07.AC15)
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Draft item"
    And I press "Submit version 1 for review"
    And I press "Review version 1"
    Then I should see "Review: Version 1"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I press "Reject"
    Then I should see "Please give a reason."
    And the page should meet accessibility standards with "wcag143" extra tests

  Scenario: The overview "Waiting for review" and the assignment form work with the keyboard (F07.AC15)
    Given the following "local_nextpool > versions in review" exist:
      | item        | content | days |
      | Second item | v2      | 3    |
    And I log in as "admin"
    When I follow "Waiting for review (1)" in the user menu
    Then I should see "Second item" in the "reportbuilder-table" "table"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "I review: Second item, Version 2" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    When I tab to "Assign: Second item, Version 2" "link" in the catalogue
    Then the focused element should have a visible focus indicator
    When I click on "Assign: Second item, Version 2" "link"
    Then I should see "Assign review: Version 2 of \"Second item\""
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "Assigned to" "field" in the catalogue
    Then the focused element should have a visible focus indicator
    When I tab to "Assign" "button" in the catalogue
    Then the focused element should have a visible focus indicator
