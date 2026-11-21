@local @local_nextpool @javascript @accessibility @nextpool_h5p_preview
Feature: Core H5P previews start and deliver media without learning records
  In order to assess real interactive content before placement
  As a teacher
  I need the H5P runtime, safe media and nested keyboard controls to work in the catalogue

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

  Scenario Outline: The greeting card runs with media and respects pause and Escape (F16.AC01, AC14, AC15)
    Given a published H5P greeting card using "<embedtype>" embedding exists
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    When I click on "H5P greeting" "link"
    And I enter the running H5P greeting card using "<embedtype>" embedding
    Then I should see "Hello world!"
    And the greeting card image and audio should be loaded
    When I press "Play card audio"
    Then I should see "Playing card audio"
    And the H5P greeting audio should be "playing"
    When I switch to the main frame
    And I click on "Feedback" "button" in the ".modal-body" "css_element"
    Then the H5P greeting audio should be "paused"
    When I click on "Preview" "button" in the ".modal-body" "css_element"
    And I enter the running H5P greeting card using "<embedtype>" embedding
    And I press the escape key
    And I switch to the main frame
    Then ".modal-body" "css_element" should not exist
    And the focused element is "H5P greeting" "link"
    When I click on "H5P greeting" "link"
    And I remove embedded frames from the catalogue preview
    Then the page should meet accessibility standards

    Examples:
      | embedtype |
      | div       |
      | iframe    |
