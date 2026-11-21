@local @local_nextpool @javascript
Feature: Storage setup guidance describes the next required action
  In order to enable uploads from courses
  As an administrator
  I need guidance that distinguishes missing storage from a missing selection

  Scenario: An existing writable external location only needs selecting and saving
    Given the following "local_nextpool > storage locations" exist:
      | name  | plugin    |
      | Cloud | nextcloud |
    And I log in as "admin"
    When I visit "/local/nextpool/sources.php"
    Then I should see "Select a storage location for direct uploads below and save your changes."
    And the field "Storage location for direct uploads" matches value "Cloud"
    When I press "Save changes"
    Then I should see "Storage location for direct uploads saved."
    And I should see "Direct uploads" in the "Cloud" "table_row"

  Scenario: With no storage location the administrator is asked to add one
    Given I log in as "admin"
    When I visit "/local/nextpool/sources.php"
    Then I should see "Add a Moodle file storage location or another storage location that can be written to"
