@local @local_nextpool
Feature: Administrators choose the storage location for direct uploads from courses
  In order to keep packages that teachers upload in a known place
  As an administrator
  I need to choose an enabled storage location for direct uploads (M06, TD-404)

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
      | Uploads |

  @javascript @accessibility
  Scenario: The first Moodle file storage is the default, another one can be chosen and is protected
    Given I log in as "admin"
    When I visit "/local/nextpool/sources.php"
    Then I should see "Direct uploads" in the "Library" "table_row"
    And I should not see "Direct uploads" in the "Uploads" "table_row"
    And the field "Storage location for direct uploads" matches value "Library"
    And the page should meet accessibility standards
    When I set the field "Storage location for direct uploads" to "Uploads"
    And I press "Save changes"
    Then I should see "Storage location for direct uploads saved."
    And I should see "Direct uploads" in the "Uploads" "table_row"
    And "Delete" "button" should not exist in the "Uploads" "table_row"
    And "Delete" "button" should exist in the "Library" "table_row"
    When I click on "Disable" "button" in the "Uploads" "table_row"
    Then I should see "The storage location chosen for direct uploads is disabled or cannot be written to."

  @javascript
  Scenario: Deleting a storage location asks with a destructive action and cancellation keeps it
    Given I log in as "admin"
    And I visit "/local/nextpool/sources.php"
    When I click on "Delete" "button" in the "Uploads" "table_row"
    Then "button.btn-danger" "css_element" should exist
    And "Delete" "button" should exist
    When I press "Cancel"
    Then I should see "Uploads" in the "Uploads" "table_row"
    When I click on "Delete" "button" in the "Uploads" "table_row"
    And I press "Delete"
    Then I should see "Storage location deleted."
    And "Uploads" "table_row" should not exist
