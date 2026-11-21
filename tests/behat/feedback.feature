@local @local_nextpool
Feature: Teachers and editors give feedback on catalogue items
  In order to improve the content of the catalogue together
  As a teacher or editor
  I need to comment on items and their versions, reply, mark comments as done and follow an item (F08, task81)

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
      | teacher2 | Tina      | Tutor    |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
    And the following "local_nextpool > items" exist:
      | title              | location | file               | published |
      | Fire safety basics | Library  | Safety/handout.pdf | 1         |
    And the following "local_nextpool > placements" exist:
      | item               | course |
      | Fire safety basics | C1     |

  @javascript @accessibility
  Scenario: Teachers comment from their course, reply and mark a comment as done without leaving the page
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Catalogue content in this course (1)"
    And "//td/a[contains(@href, '/local/nextpool/feedback.php')]" "xpath_element" should exist
    And I follow "Content catalogue"
    When I click on "Feedback on Fire safety basics" "link"
    And I click on "Open all comments" "link" in the ".modal-body" "css_element"
    Then I should see "Feedback" in the "#local-nextpool-feedback-heading" "css_element"
    And I should see "Comments are visible to everyone who can see this item"
    And I should see "No comments yet."
    And the field "About" matches value "Version 1"
    And the page should meet accessibility standards
    When I set the field "New comment" to "Please add the emergency numbers."
    And I press "Post comment"
    Then I should see "Please add the emergency numbers." in the ".local-nextpool-feedback__threads" "css_element"
    And I should see "Terry Teacher" in the ".local-nextpool-feedback__threads" "css_element"
    And I should see "Version 1" in the ".local-nextpool-feedback__threads" "css_element"
    And I should not see "Comments are visible to everyone who can see this item"
    And "Unsubscribe" "button" should exist in the "#local-nextpool-feedback" "css_element"
    And I log out
    Given I am on the "C1" "Course" page logged in as "teacher2"
    And I navigate to "Content catalogue" in current page administration
    And I click on "Feedback on Fire safety basics" "link"
    And I click on "Open all comments" "link" in the ".modal-body" "css_element"
    When I click on "Reply" "text" in the ".local-nextpool-feedback__threads" "css_element"
    And I set the field "Your reply" to "The address is missing too."
    And I press "Post reply"
    Then I should see "The address is missing too." in the ".local-nextpool-feedback__replies" "css_element"
    And "Mark as done" "button" should not exist in the "#local-nextpool-feedback" "css_element"
    And I log out
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I click on "Feedback on Fire safety basics" "link"
    And I click on "Open all comments" "link" in the ".modal-body" "css_element"
    When I click on "Mark as done" "button" in the "#local-nextpool-feedback" "css_element"
    Then I should see "Done (1)" in the "#local-nextpool-feedback" "css_element"
    And I should see "marked as done by Terry Teacher" in the "#local-nextpool-feedback" "css_element"
    And I should not see "The address is missing too."
    And the page should meet accessibility standards
    When I click on ".local-nextpool-feedback__done summary" "css_element"
    And I click on "Open again" "button" in the "#local-nextpool-feedback" "css_element"
    Then I should see "The address is missing too."
    And I should not see "Done (1)"
    And I log out
    And I am on the "C1" "Course" page logged in as "student1"
    And I should not see "Please add the emergency numbers."
    And "Content catalogue" "link" should not exist

  Scenario: Editors comment on the item page, edit their comment and remove a comment with replies
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    When I set the field "New comment" to "Check the fire exits."
    And I set the field "About" to "Item in general"
    And I press "Post comment"
    Then I should see "Comment posted."
    And I should see "Check the fire exits." in the ".local-nextpool-feedback__threads" "css_element"
    And I should not see "Version 1" in the ".local-nextpool-feedback__threads" "css_element"
    When I set the field "Your reply" to "Done in the new handout."
    And I press "Post reply"
    Then I should see "Reply posted."
    When I click on "Edit comment" "link" in the ".local-nextpool-feedback__threads" "css_element"
    And I set the field "Comment" to "Check the fire exits on floor 2."
    And I press "Save changes"
    Then I should see "Check the fire exits on floor 2."
    And I should see "edited" in the ".local-nextpool-feedback__threads" "css_element"
    When I click on "Remove comment" "button" in the ".local-nextpool-feedback__threads" "css_element"
    And I press "Remove comment"
    Then I should see "Comment removed" in the ".local-nextpool-feedback__threads" "css_element"
    And I should not see "Check the fire exits on floor 2."
    And I should see "Done in the new handout." in the ".local-nextpool-feedback__replies" "css_element"
