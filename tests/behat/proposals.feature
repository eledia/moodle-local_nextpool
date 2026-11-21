@local @local_nextpool @_file_upload
Feature: Teachers propose new versions and new items from their course
  In order to give improvements back to the catalogue and follow what becomes of them
  As a teacher
  I need to propose a new version or a new item from an activity and see my proposals (F07, task82, test65)

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
    And the following "local_nextpool > placements" exist:
      | item               | course |
      | Fire safety basics | C1     |
    And the following "activities" exist:
      | activity | course | name       | intro       | content            |
      | page     | C1     | Evacuation | Leave calm. | Meet at car park.  |

  @javascript @accessibility
  Scenario: Propose a new version from the activity, see it under My proposals and cancel it (F07.AC01, AC02, AC11)
    Given I am on the "Fire safety basics" "resource activity editing" page logged in as "teacher1"
    When I navigate to "Propose a new version" in current page administration
    Then I should see "Propose a new version: Fire safety basics"
    And the page should meet accessibility standards
    When I upload "lib/tests/fixtures/empty.txt" file to "File" filemanager
    And I press "Submit proposal"
    Then I should see "Describe the change."
    When I set the field "Description of the change" to "Emergency numbers added."
    And I press "Submit proposal"
    Then I should see "Your proposal was submitted for review."
    And I should see "My proposals" in the ".local-nextpool-pagehead" "css_element"
    And I should see "In review" in the "Fire safety basics" "table_row"
    And the page should meet accessibility standards
    And I am on "Course 1" course homepage
    And I navigate to "Content catalogue" in current page administration
    And I follow "Catalogue content in this course (1)"
    And I should see "Version 1" in the "Fire safety basics" "table_row"
    And "Propose a new version" "link" should exist in the "Fire safety basics" "table_row"
    When I follow "My proposals"
    And I click on "Cancel proposal" "link" in the "Fire safety basics" "table_row"
    And I press "Cancel proposal"
    Then I should see "Proposal cancelled."
    And I should see "Cancelled by proposer" in the "Fire safety basics" "table_row"
    And "Cancel proposal" "link" should not exist in the "Fire safety basics" "table_row"

  @javascript
  Scenario: Propose an activity as new item; it reaches the course catalogue once a reviewer publishes it (F07.AC10)
    Given I am on the "Evacuation" "page activity" page logged in as "teacher1"
    When I navigate to "Propose as new catalogue item" in current page administration
    Then the field "Title" matches value "Evacuation"
    And the field "Author" matches value "Terry Teacher"
    When I set the field "Description" to "What to do in an emergency."
    And I click on "Upload a file" "radio"
    And I upload "lib/tests/fixtures/empty.txt" file to "File" filemanager
    And I set the field "Description of the change" to "Our evacuation plan."
    And I press "Submit proposal"
    Then I should see "Your proposal was submitted for review."
    And I should see "In review" in the "Evacuation" "table_row"
    And I am on "Course 1" course homepage
    And I navigate to "Content catalogue" in current page administration
    And I should not see "Evacuation" in the "region-main" "region"
    And I log out
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Evacuation"
    When I press "Review proposal"
    Then I should see "Our evacuation plan."
    When I press "Publish proposal"
    Then I should see "was published"
    And I log out
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I navigate to "Content catalogue" in current page administration
    Then I should see "Evacuation" in the "region-main" "region"

  @javascript
  Scenario: From the activity the proposal page keeps the activity context and has one heading (task174)
    Given I am on the "Fire safety basics" "resource activity editing" page logged in as "teacher1"
    When I navigate to "Propose a new version" in current page administration
    # The activity header shows the page title as its h2 in place of the activity name (core/activity_header).
    Then "//h2[normalize-space(.)='Propose a new version: Fire safety basics']" "xpath_element" should exist
    And "(//h2[normalize-space(.)='Propose a new version: Fire safety basics'])[2]" "xpath_element" should not exist
    And "//h2[normalize-space(.)='Fire safety basics']" "xpath_element" should not exist
    And ".local-nextpool-pagehead" "css_element" should not exist
    And ".local-nextpool-areanav" "css_element" should not exist
    And I should not see "My proposals" in the "region-main" "region"
    And "//*[@aria-current='true'][contains(normalize-space(.), 'Propose a new version')]" "xpath_element" should exist
    And I should see "C1" in the ".breadcrumb" "css_element"
    And I should see "Fire safety basics" in the ".breadcrumb" "css_element"
    And I should see "Propose a new version" in the "//ol[contains(@class, 'breadcrumb')]/li[last()]" "xpath_element"
    And I should not see "Content catalogue" in the ".breadcrumb" "css_element"
    And I should not see "My proposals" in the ".breadcrumb" "css_element"

  @javascript
  Scenario: From the course catalogue the proposal page keeps the catalogue path and tab row (task174)
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I click on "Feedback on Fire safety basics" "link"
    And I click on "Open all comments" "link" in the ".modal-body" "css_element"
    When I press "Propose a new version"
    Then I should see "Propose a new version: Fire safety basics" in the ".local-nextpool-pagehead" "css_element"
    And ".local-nextpool-areanav" "css_element" should exist
    # Boost drops "Content catalogue" from the breadcrumb, because the course navigation already has it (boostnavbar).
    And "My proposals" "link" should exist in the ".breadcrumb" "css_element"
    And I should see "Propose a new version: Fire safety basics" in the "//ol[contains(@class, 'breadcrumb')]/li[last()]" "xpath_element"
    And ".activity-header" "css_element" should not exist
