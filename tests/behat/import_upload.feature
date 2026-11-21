@local @local_nextpool @javascript @_file_upload
Feature: Uploaded files become importable draft versions
  In order to update a learning item from my computer
  As a content editor
  I need an uploaded file to be stored and imported as the next draft

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And I log in as "admin"

  Scenario: Upload a new file beside the current source file
    Given the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
    And the following "local_nextpool > items" exist:
      | title   | location | file               |
      | Handout | Library  | Safety/handout.pdf |
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Handout"
    And I press "Import new version"
    When I upload "lib/tests/fixtures/empty.txt" file to "Upload file into this folder" filemanager
    And I press "Upload"
    Then I should see "New draft version imported:"
    And I should see "Version 2"
    When I press "Import new version"
    Then "Import as new version" "button" should exist in the "empty.txt" "table_row"
    And "Import as new version" "button" should exist in the "handout.pdf" "table_row"

  Scenario: Upload replaces the item's own source file and imports another version
    Given the following "local_nextpool > storage files" exist:
      | location | path             | content     |
      | Library  | Safety/empty.txt | first draft |
    And the following "local_nextpool > items" exist:
      | title   | location | file             |
      | Handout | Library  | Safety/empty.txt |
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Handout"
    And I press "Import new version"
    When I upload "lib/tests/fixtures/empty.txt" file to "Upload file into this folder" filemanager
    And I press "Upload"
    Then I should see "New draft version imported:"
    And I should see "Version 2"
