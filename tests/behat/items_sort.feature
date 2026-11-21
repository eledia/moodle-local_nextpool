@local @local_nextpool @javascript @accessibility
Feature: Sort the editorial item list by its column headings (task168)
  In order to find catalogue items in a long list
  As a content editor
  I need to sort the list by title, status, category or last change, across all pages and with the filters

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > items" exist:
      | title   | location |
      | Item 01 | Library  |
      | Item 02 | Library  |
      | Item 03 | Library  |
      | Item 04 | Library  |
      | Item 05 | Library  |
      | Item 06 | Library  |
      | Item 07 | Library  |
      | Item 08 | Library  |
      | Item 09 | Library  |
      | Item 10 | Library  |
      | Item 11 | Library  |
      | Item 12 | Library  |
      | Item 13 | Library  |
      | Item 14 | Library  |
      | Item 15 | Library  |
      | Item 16 | Library  |
      | Item 17 | Library  |
      | Item 18 | Library  |
      | Item 19 | Library  |
      | Item 20 | Library  |
      | Item 21 | Library  |
      | Item 22 | Library  |
      | Item 23 | Library  |
      | Item 24 | Library  |
      | Item 25 | Library  |
      | Item 26 | Library  |
      | Item 27 | Library  |
      | Item 28 | Library  |
      | Item 29 | Library  |
      | Item 30 | Library  |
      | Item 31 | Library  |
      | Item 32 | Library  |
      | Item 33 | Library  |
      | Item 34 | Library  |
      | Item 35 | Library  |
      | Item 36 | Library  |
      | Item 37 | Library  |
      | Item 38 | Library  |
      | Item 39 | Library  |
      | Item 40 | Library  |
      | Item 41 | Library  |
      | Item 42 | Library  |
      | Item 43 | Library  |
      | Item 44 | Library  |
      | Item 45 | Library  |
      | Item 46 | Library  |
      | Item 47 | Library  |
      | Item 48 | Library  |
      | Item 49 | Library  |
      | Item 50 | Library  |
      | Item 51 | Library  |
      | Item 52 | Library  |
      | Item 53 | Library  |
      | Item 54 | Library  |
      | Item 55 | Library  |

  Scenario: The heading sorts the list with the keyboard, the sorting stays on page 2 and with a filter
    Given I log in as "admin"
    When I visit "/local/nextpool/items.php"
    Then "//th[@aria-sort='descending']//a[@aria-label='Sort by Last modified ascending']" "xpath_element" should exist
    And "//th[@aria-sort]//a[@aria-label='Sort by Title ascending']" "xpath_element" should not exist
    And I should see "Item 55"
    And I should not see "Item 01"
    # The heading link is named by its aria-label, which the "link" selector does not match.
    When I tab to "//th//a[@aria-label='Sort by Title ascending']" "xpath_element" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    Then "//th[@aria-sort='ascending']//a[@aria-label='Sort by Title descending']" "xpath_element" should exist
    And "//th[@aria-sort]//a[@aria-label='Sort by Last modified descending']" "xpath_element" should not exist
    And I should see "Item 01"
    And I should not see "Item 51"
    And "Item 01" "text" should appear before "Item 50" "text"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Item 51"
    And I should see "Item 55"
    And I should not see "Item 01"
    And "Item 51" "text" should appear before "Item 55" "text"
    And "//th[@aria-sort='ascending']//a[@aria-label='Sort by Title descending']" "xpath_element" should exist
    And the page should meet accessibility standards with "wcag143" extra tests
    When I set the field "local_nextpool_search" to "5"
    And I press "Filter"
    Then I should see "Item 05"
    And I should see "Item 55"
    And I should not see "Item 01"
    And "Item 05" "text" should appear before "Item 50" "text"
    And "//th[@aria-sort='ascending']//a[@aria-label='Sort by Title descending']" "xpath_element" should exist
    When I click on "//th//a[@aria-label='Sort by Title descending']" "xpath_element"
    Then "Item 55" "text" should appear before "Item 05" "text"
    And "//th[@aria-sort='descending']//a[@aria-label='Sort by Title ascending']" "xpath_element" should exist
    And the field "local_nextpool_search" matches value "5"

  Scenario: Below 768 px a menu sorts the stacked list, and the sorting stays on page 2
    Given I log in as "admin"
    And I visit "/local/nextpool/items.php"
    When I change the viewport size to "375x800"
    Then "//th//a[@aria-label='Sort by Title ascending']" "xpath_element" should not be visible
    When I press "Sort by: Last modified (newest first)"
    And I click on "Title (A to Z)" "link"
    Then I should see "Item 01"
    And I should not see "Item 51"
    And "Sort by: Title (A to Z)" "button" should exist
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Item 51"
    And I should not see "Item 01"
    And "Sort by: Title (A to Z)" "button" should exist
    And the page should not need horizontal scrolling
    And the page should meet accessibility standards with "wcag143" extra tests
