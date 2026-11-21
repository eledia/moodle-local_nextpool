@local @local_nextpool @javascript @accessibility
Feature: Compact editorial item list (task166)
  In order to scan the catalogue items quickly
  As a content editor
  I need the content type as icon beside the title, short pills and a single action without a menu

  Background:
    Given the following "courses" exist:
      | fullname        | shortname |
      | Safety training | C1        |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
      | Uploads |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
    And the following "local_nextpool > items" exist:
      | title         | location | file               | published | type | course | cataloguecategory |
      | Fire handout  | Library  | Safety/handout.pdf | 1         | file |        | Safety            |
      | Course module | Uploads  |                    |           | xapi | C1     |                   |

  Scenario: The content type is an icon with its name beside the title, the origin course a linked pill
    Given I log in as "admin"
    When I visit "/local/nextpool/items.php"
    Then "//th[normalize-space(.)='Content type']" "xpath_element" should not exist
    And "//span[contains(@class, 'local-nextpool-typeicon')]/span[@class='visually-hidden' and .='File (video, PDF, document)']" "xpath_element" should exist in the "Fire handout" "table_row"
    And I should see "Published" in the "Fire handout" "table_row"
    And "//span[contains(@class, 'local-nextpool-statuspill')]//i[contains(@class, 'icon')]" "xpath_element" should exist in the "Fire handout" "table_row"
    And "//span[contains(@class, 'local-nextpool-categorypill') and normalize-space(.)='Safety']" "xpath_element" should exist in the "Fire handout" "table_row"
    And I should see "From course" in the "Course module" "table_row"
    And I should not see "Uploaded from course" in the "Course module" "table_row"
    And "//a[@aria-label='Uploaded from course: Safety training']" "xpath_element" should exist in the "Course module" "table_row"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I click on "//a[@aria-label='Uploaded from course: Safety training']" "xpath_element"
    Then I should see "Safety training"
    And I should not see "Fire handout"

  Scenario: A single row action is an icon link that asks before withdrawing, also with the keyboard
    Given I log in as "admin"
    When I visit "/local/nextpool/items.php"
    Then "More actions: Fire handout" "button" should not exist
    And "Withdraw item: Fire handout" "link" should exist in the "Fire handout" "table_row"
    And "//a[contains(@href, 'action=itemstatus') and contains(@href, 'sesskey=')]" "xpath_element" should not exist
    When I tab to "Withdraw item: Fire handout" "link" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "It can then no longer be chosen in any course catalogue."
    When I press "Cancel"
    Then I should see "Published" in the "Fire handout" "table_row"
    When I click on "Withdraw item: Fire handout" "link"
    And I press "Withdraw item"
    Then I should see "Status changed to \"Withdrawn\"."
    And I should see "Withdrawn" in the "Fire handout" "table_row"

  Scenario: Below 768 px the stacked list shows the type name beside its icon
    Given I log in as "admin"
    And I visit "/local/nextpool/items.php"
    When I change the viewport size to "375x800"
    Then I should see "File" in the "Fire handout" "table_row"
    And I should see "xAPI" in the "Course module" "table_row"
    And "table[role='table'] tbody th[scope='row'][role='rowheader']" "css_element" should exist
    And "table[role='table'] thead th[scope='col'][role='columnheader']" "css_element" should exist
    And the "Edit: Fire handout" "link" target should be at least 44 by 44 pixels
    And the page should not need horizontal scrolling
    And the page should meet accessibility standards with "wcag143" extra tests
