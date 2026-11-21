@local @local_nextpool
Feature: Item editors work on a catalogue item together
  In order to improve an item with people outside the editorial team
  As an editor or item editor
  I need to add item editors who can change and submit the item but not publish it (F08, task84, test67)

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | editor1  | Eda       | Editor   |
      | editor2  | Olga      | Other    |
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
      | Library  | Safety/update.pdf  | second      |
    And the following "local_nextpool > items" exist:
      | title       | location | file               |
      | Fire safety | Library  | Safety/handout.pdf |
      | First aid   | Library  |                    |

  @javascript @accessibility
  Scenario: The editorial team adds and removes item editors on the item page
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I follow "Fire safety"
    Then I should see "No item editors yet." in the ".local-nextpool-editors" "css_element"
    And "Open in storage location" "link" should not exist
    And the page should meet accessibility standards
    When I follow "Add item editor"
    And I set the field "Add item editor" to "Eda Editor"
    And I press "Add"
    Then I should see "Eda Editor is now an item editor."
    And I should see "Eda Editor" in the ".local-nextpool-editors" "css_element"
    When I press "Remove item editor: Eda Editor"
    Then I should see "Eda Editor is no longer an item editor."
    And I should see "No item editors yet." in the ".local-nextpool-editors" "css_element"

  Scenario: Item editors change and submit their item from "My items" but cannot publish it
    Given the following "local_nextpool > item editors" exist:
      | item        | user    |
      | Fire safety | editor1 |
    When I log in as "editor1"
    And I follow "My items" in the user menu
    Then I should see "Fire safety"
    And I should not see "First aid"
    When I follow "Fire safety"
    Then I should see "Eda Editor" in the ".local-nextpool-editors" "css_element"
    And "Add item editor" "link" should not exist
    And "Remove item editor: Eda Editor" "button" should not exist
    And "Delete" "link" should not exist
    When I follow "Edit"
    And I set the field "Title" to "Fire safety at work"
    And I press "Save changes"
    Then I should see "Changes saved"
    And I should see "Fire safety at work"
    When I press "Submit version 1 for review"
    Then I should see "In review"
    And "Review version 1" "button" should not exist
    And "Publish item" "button" should not exist
    # Item editors import their own file only and cannot browse the storage location (bug102).
    When I press "Import new version"
    Then I should see "As an item editor, you can import the file of this item again"
    And I should not see "update.pdf"
    And "Safety" "link" should not exist
    And "Import as new version" "button" should not exist
    And the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | third draft |
    When I press "Import again from Safety/handout.pdf"
    Then I should see "New draft version imported:"
    And I should see "My items" in the ".breadcrumb" "css_element"

  @javascript @_file_upload
  Scenario: Item editors upload a new file for their item only, not into other folders
    Given the following "local_nextpool > item editors" exist:
      | item        | user    |
      | Fire safety | editor1 |
    And I log in as "editor1"
    And I visit "/local/nextpool/myitems.php"
    And I follow "Fire safety"
    When I press "Import new version"
    Then I should not see "Upload file into this folder"
    When I upload "lib/tests/fixtures/empty.txt" file to "Upload a new file for this item" filemanager
    And I press "Upload"
    Then I should see "New draft version imported:"
    And I should see "Version 2"
    And I should see "handout"
    And I should not see "_empty"

  Scenario: Saving after someone else saved is refused with their name and keeps the entries
    Given the following "local_nextpool > item editors" exist:
      | item        | user    |
      | Fire safety | editor1 |
      | Fire safety | editor2 |
    And I log in as "editor1"
    And I visit "/local/nextpool/myitems.php"
    And I follow "Fire safety"
    And I follow "Edit"
    And the catalogue item "Fire safety" was saved meanwhile by "editor2"
    When I set the field "Title" to "Fire safety, my version"
    And I press "Save changes"
    Then I should see "Olga Other saved this item on"
    And the field "Title" matches value "Fire safety, my version"
    When I press "Save changes"
    Then I should see "Changes saved"
    And I should see "Fire safety, my version"
