@local @local_nextpool @javascript @accessibility
Feature: Compact catalogue category table (task167)
  In order to maintain the category tree quickly
  As an administrator
  I need the row actions as icons without a one-entry menu and the item count as a link to the filtered list

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > items" exist:
      | title        | location | cataloguecategory |
      | Fire handout | Library  | Safety            |

  Scenario: Row actions are icons, deleting asks first, and the count opens the filtered item list
    Given I log in as "admin"
    And I visit "/local/nextpool/categories.php"
    And I press "Add category"
    And I set the field "Category name" to "Health"
    And I press "Save changes"
    Then "//button[contains(@class, 'dropdown-toggle')]" "xpath_element" should not exist in the "region-main" "region"
    And "Add subcategory: Safety" "link" should exist in the "Safety" "table_row"
    # A category with items cannot be deleted, so its row has no delete action.
    And "Delete: Safety" "link" should not exist
    And "Catalogue items in Health: 0" "link" should not exist
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "Delete: Health" "link" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "Delete the catalogue category \"Health\"?"
    And "button.btn-danger" "css_element" should exist
    When I press "Cancel"
    Then I should see "Health" in the "Health" "table_row"
    When I click on "Catalogue items in Safety: 1" "link"
    Then I should see "Fire handout"
    And the field "Catalogue category" matches value "Safety"
