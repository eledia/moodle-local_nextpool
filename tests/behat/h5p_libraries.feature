@local @local_nextpool
Feature: H5P library updates only with an explicit option when publishing
  In order to keep the H5P libraries of the site under control
  As a content editor
  I need to see which libraries an H5P package brings and decide whether it may install them (q39)

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path           | fixture                              |
      | Library  | Cards/card.h5p | h5p/tests/fixtures/greeting-card.h5p |
    And the following "local_nextpool > items" exist:
      | title         | location | type | file           |
      | Greeting card | Library  | h5p  | Cards/card.h5p |

  @javascript @accessibility
  Scenario: The review names the libraries and publishing asks before it allows library updates
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I follow "Greeting card"
    Then I should see "This package brings new or changed H5P libraries: H5P.GreetingCard 1.0.6."
    When I press "Submit version 1 for review"
    And I press "Review version 1"
    Then I should see "H5P.GreetingCard 1.0.6" in the ".alert-warning" "css_element"
    And the field "Allow H5P library updates" matches value "0"
    And the page should meet accessibility standards
    When I press "Cancel"
    Then I should see "In review" in the ".local-nextpool-version .badge" "css_element"
    When I press "Review version 1"
    And I set the field "Allow H5P library updates" to "1"
    And I press "Publish version 1"
    Then I should see "Current version"
    And I should see "Published with H5P library updates allowed"

  Scenario: Publishing without the option keeps the library updates off
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Greeting card"
    When I press "Submit version 1 for review"
    And I press "Review version 1"
    And I press "Publish version 1"
    Then I should see "Current version"
    And I should not see "Published with H5P library updates allowed"
    And I should not see "This package brings new or changed H5P libraries"

  @javascript @accessibility
  Scenario: A version without the library option cannot be placed while the site lacks its libraries (bug88)
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "local_nextpool > storage files" exist:
      | location | path            | fixture                              |
      | Library  | Cards/card2.h5p | h5p/tests/fixtures/greeting-card.h5p |
    And the following "local_nextpool > items" exist:
      | title          | location | type | file            | published |
      | Published card | Library  | h5p  | Cards/card2.h5p | 1         |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I follow "Place in course"
    Then I should see "This item cannot be placed at the moment" in the ".alert-warning" "css_element"
    And I should see "H5P.GreetingCard 1.0" in the ".alert-warning" "css_element"
    And I should see "Please inform the editorial team of the catalogue."
    And "Update policy" "field" should not exist
    And the page should meet accessibility standards
    And I log out
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I follow "Published card"
    Then I should see "Courses cannot place this version" in the ".alert" "css_element"
    And I should see "H5P.GreetingCard 1.0" in the ".alert" "css_element"
    And the page should meet accessibility standards
