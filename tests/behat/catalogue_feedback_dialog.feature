@local @local_nextpool
Feature: Teachers give feedback from the catalogue browser without leaving it
  In order to comment on an item while I browse the catalogue
  As a teacher
  I need the feedback icon of a card to open a dialog with the latest comments and a form, and a badge with the open comments (task173)

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

  @javascript @accessibility
  Scenario: Give feedback from the catalogue: the dialog opens, the comment is posted and the badge counts it
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And "Feedback on Fire safety basics, 1 open" "link" should not exist
    When I click on "Feedback on Fire safety basics" "link"
    Then I should see "Fire safety basics" in the ".modal-title" "css_element"
    And I should see "No comments yet." in the ".modal-body" "css_element"
    And "Open all comments" "link" should exist in the ".modal-body" "css_element"
    And I should see "Comments are visible to everyone who can see this item" in the ".modal-body" "css_element"
    And the field "About" matches value "Version 1"
    And the page should meet accessibility standards
    When I set the field "New comment" to "Please add the emergency numbers."
    And I press "Post comment"
    Then I should see "Comment posted."
    And I should see "Please add the emergency numbers." in the ".modal-body" "css_element"
    When I press the escape key
    Then ".modal-body" "css_element" should not exist
    And the focused element is "Feedback on Fire safety basics, 1 open" "link"
    And I should see "Content catalogue" in the "#region-main" "css_element"
    And I should see "1" in the ".local-nextpool-feedbackcount" "css_element"
    And the page should meet accessibility standards
    When I click on "Feedback on Fire safety basics, 1 open" "link"
    Then I should see "Please add the emergency numbers." in the ".modal-body" "css_element"
    And I should see "Terry Teacher" in the ".modal-body" "css_element"
    And I should not see "Comments are visible to everyone who can see this item"
    When I press the escape key
    Then ".modal-body" "css_element" should not exist
    And the focused element is "Feedback on Fire safety basics, 1 open" "link"
    When I click on "Feedback on Fire safety basics, 1 open" "link"
    And I click on "Open all comments" "link" in the ".modal-body" "css_element"
    Then I should see "Feedback" in the "#local-nextpool-feedback-heading" "css_element"
    And I should see "Please add the emergency numbers." in the ".local-nextpool-feedback__threads" "css_element"

  @javascript
  Scenario: Without the catalogue capability the dialog refuses the feedback
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And the following "permission overrides" exist:
      | capability                   | permission | role           | contextlevel | reference |
      | local/nextpool:viewcatalogue | Prohibit   | editingteacher | Course       | C1        |
    When I click on "Feedback on Fire safety basics" "link"
    Then I should see "you do not currently have permissions"
    And "Post comment" "button" should not exist

  Scenario: Without JavaScript the feedback icon leads to the course view of the item
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I follow "Feedback on Fire safety basics"
    Then I should see "Feedback" in the "#local-nextpool-feedback-heading" "css_element"
    And I should see "No comments yet."
