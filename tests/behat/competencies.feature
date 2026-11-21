@local @local_nextpool
Feature: Catalogue items carry competencies that placing links with the activity
  In order to connect catalogue content with the competency model
  As a content editor and teacher
  I need to maintain competencies on an item and get them on the activity when I place it

  Background:
    Given the following "core_competency > frameworks" exist:
      | shortname      | idnumber |
      | Safety at work | SAFETY   |
    And the following "core_competency > competencies" exist:
      | shortname              | idnumber | competencyframework |
      | Recognise fire hazards | SAFE-01  | SAFETY              |
    And the following "courses" exist:
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
  Scenario: Editors maintain the competencies of an item and see which ones cannot be linked
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    # An empty block is not shown (task105).
    And ".local-nextpool-competencies" "css_element" should not exist
    When I click on "Edit" "link" in the "region-main" "region"
    And I expand all fieldsets
    And I set the field "Competency 1" to "SAFE-01"
    # The label comes from the Ajax search; a value typed without a suggestion shows only the idnumber (bug52).
    And I should see "Recognise fire hazards (SAFE-01) – Safety at work" in the "#fitem_id_competency_idnumber_0" "css_element"
    And I set the field "competency_level[0]" to "2 – Guided application"
    And I set the field "competency_function[0]" to "Explain"
    And I set the field "competency_objectives[0]" to "understand | Name the three sources of ignition"
    And I press "Add another competency"
    And I set the field "Competency 2" to "SAFE-99"
    And I set the field "competency_level[1]" to "3 – Independent application"
    And I set the field "competency_function[1]" to "Practice"
    And I press "Save changes"
    Then I should see "these competencies are not on this site or exist in several frameworks: SAFE-99."
    And I should see "Recognise fire hazards" in the ".local-nextpool-competencies" "css_element"
    And I should see "2 – Guided application · Explain" in the ".local-nextpool-competencies" "css_element"
    And I should see "Name the three sources of ignition" in the ".local-nextpool-competencies" "css_element"
    And I should see "No competency with this ID number exists on this site" in the ".local-nextpool-competencies" "css_element"
    And the page should meet accessibility standards

  Scenario: Placing an item links its competencies with the activity and reports unknown ones
    Given the following "local_nextpool > item competencies" exist:
      | item               | competency | level | function |
      | Fire safety basics | SAFE-01    | 2     | explain  |
      | Fire safety basics | SAFE-99    | 3     | practice |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I follow "Place in course"
    And I press "Place in course"
    Then I should see "was added to the course."
    And I should see "The competency \"SAFE-99\" does not exist on this site and was not linked with the activity."
    And I am on the "C1" "Course" page
    And I navigate to "Competencies" in current page administration
    Then I should see "Recognise fire hazards" in the "region-main" "region"
    And I should see "Fire safety basics" in the "region-main" "region"
