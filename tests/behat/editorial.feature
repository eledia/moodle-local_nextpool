@local @local_nextpool
Feature: Content editors maintain catalogue items, versions and categories
  In order to offer reviewed content to teachers
  As a content editor
  I need to create items, import versions from a storage location and publish them

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |

  Scenario: Create an item, import a version and publish both
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I press "Add item"
    And I set the following fields to these values:
      | Title                         | Fire safety basics |
      | Name of author 1 (without Moodle account) | Jane Doe           |
      | Role of author 1                          | Author             |
    And I press "Save changes"
    Then I should see "Fire safety basics" in the "region-main" "region"
    And I should see "Draft" in the ".local-nextpool-item__status" "css_element"
    And I should see "Jane Doe (Author)"
    And I should see "No version yet"
    When I press "Import new version"
    And I follow "Safety"
    And I click on "Import as new version" "button" in the "handout.pdf" "table_row"
    Then I should see "New draft version imported:"
    # The label is built from the month and the file name; no changelog is asked for (task103).
    And I should see "_handout"
    When I click on "Edit label and changelog: Version 1" "link"
    And I set the field "Version label" to "First edition"
    And I press "Save changes"
    Then I should see "Label and changelog saved."
    And I should see "First edition"
    And I should not see "_handout"
    When I press "Submit version 1 for review"
    And I press "Review version 1"
    And I press "Publish version 1"
    Then I should see "Version 1 was published."
    And I should see "Current version"
    # Publishing the version publishes the draft item with it (task104).
    And I should see "Published" in the ".local-nextpool-item__status" "css_element"
    And "Delete" "link" should not exist in the "region-main" "region"
    When I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    Then I should see "Published" in the "Fire safety basics" "table_row"
    And I should see "Version 1" in the "Fire safety basics" "table_row"

  Scenario: A never published item can be deleted
    Given the following "local_nextpool > items" exist:
      | title          | location |
      | Draft handout  | Library  |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I follow "Draft handout"
    And I follow "Delete"
    Then "button.btn-danger" "css_element" should exist
    When I press "Delete"
    Then I should see "Item deleted."
    And I should not see "Draft handout"

  Scenario: Status changes from a menu carry no sesskey and are posted from the confirmation (bug112)
    Given the following "local_nextpool > items" exist:
      | title          | location | file               | published |
      | Fire handout   | Library  | Safety/handout.pdf | 1         |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    Then "//a[contains(@href, 'sesskey=')]" "xpath_element" should not exist in the "Fire handout" "table_row"
    When I follow "Fire handout"
    Then "//a[contains(@href, 'action=itemstatus') and contains(@href, 'sesskey=')]" "xpath_element" should not exist
    And "//a[contains(@href, 'action=versionstatus') and contains(@href, 'sesskey=')]" "xpath_element" should not exist
    When I follow "Withdraw item"
    Then I should see "It can then no longer be chosen in any course catalogue."
    When I press "Withdraw item"
    Then I should see "Status changed to \"Withdrawn\"."
    And "//a[contains(@href, 'action=itemstatus') and contains(@href, 'sesskey=')]" "xpath_element" should not exist
    And "//a[contains(@href, 'action=delete') and contains(@href, 'sesskey=')]" "xpath_element" should not exist

  Scenario: Manage the catalogue category tree
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Catalogue categories"
    When I press "Add category"
    And I set the field "Category name" to "Safety"
    And I press "Save changes"
    And I click on "Add subcategory" "link" in the "Safety" "table_row"
    And I set the field "Category name" to "Fire"
    And I press "Save changes"
    Then I should see "Safety / Fire"
    When I press "Add category"
    And I set the field "Category name" to "Health"
    And I press "Save changes"
    And I click on "Move up" "button" in the "Health" "table_row"
    Then I should see "The category \"Health\" was moved."
    When I click on "Delete" "link" in the "Safety / Fire" "table_row"
    And I press "Delete"
    Then I should see "Category deleted."
    And I should not see "Safety / Fire"

  @javascript @accessibility
  Scenario: The editorial pages meet accessibility standards
    Given the following "local_nextpool > items" exist:
      | title              | location |
      | Fire safety basics | Library  |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    Then the page should meet accessibility standards
    When I follow "Fire safety basics"
    Then the page should meet accessibility standards
    When I press "Import new version"
    And I follow "Safety"
    Then the page should meet accessibility standards

  @javascript @_file_upload
  Scenario: Upload a new file into the storage location and import it as a version
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    When I press "Import new version"
    And I upload "lib/tests/fixtures/empty.txt" file to "Upload file into this folder" filemanager
    And I press "Upload"
    Then I should see "New draft version imported:"
    And I should see "Version 2"
    And I should see "_empty"

  Scenario: A version imported meanwhile is not overwritten unnoticed
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    And I press "Import new version"
    # Another version arrives while the file list is open.
    And the following "local_nextpool > published versions" exist:
      | item               | content      |
      | Fire safety basics | second draft |
    When I click on "Import as new version" "button" in the "handout.pdf" "table_row"
    Then I should see "imported version 2 after you opened this page"
    And I should not see "New draft version imported:"

  @javascript @accessibility
  Scenario: Change label and changelog of a version afterwards
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    Then I should see "_handout"
    When I click on "Edit label and changelog: Version 1" "link"
    Then the page should meet accessibility standards
    When I set the following fields to these values:
      | Version label | Spring edition  |
      | Changelog     | Typos corrected |
    And I press "Save changes"
    Then I should see "Label and changelog saved."
    And I should see "Spring edition"
    And I should see "Typos corrected"
    When I press "Submit version 1 for review"
    And I click on "Edit label and changelog: Version 1" "link"
    And I set the field "Version label" to "Reviewed edition"
    And I press "Save changes"
    Then I should see "Reviewed edition"
    When I click on "Edit label and changelog: Version 1" "link"
    And I press "Cancel"
    Then I should see "Reviewed edition"

  @javascript @_file_upload
  Scenario: Upload the file for version 1 while creating an item
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I press "Add item"
    And I set the following fields to these values:
      | Title                                     | Evacuation plan |
      | Name of author 1 (without Moodle account) | Jane Doe        |
    And I upload "lib/tests/fixtures/empty.txt" file to "File for version 1" filemanager
    And I press "Save changes"
    Then I should see "Item created with version 1 as a draft."
    And I should see "Evacuation plan" in the "region-main" "region"
    And I should see "Version 1"
    And I should not see "No version yet"
    When I press "Import new version"
    Then I should see "empty.txt"

  @javascript @accessibility
  Scenario: Compare a version with the one before it
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And the following "local_nextpool > published versions" exist:
      | item               | content      |
      | Fire safety basics | second draft |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    When I follow "Compare with version 1"
    Then I should see "Compare version 1 with version 2"
    And I should see "The content of the two versions differs."
    And I should see "Changes since version 1"
    And "th[scope='row']" "css_element" should exist in the "File name" "table_row"
    And the page should meet accessibility standards

  Scenario: The editorial pages share one breadcrumb trail
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And I log in as "admin"
    When I visit "/local/nextpool/items.php"
    Then I should see "Catalogue items" in the ".breadcrumb" "css_element"
    When I follow "Fire safety basics"
    Then "Catalogue items" "link" should exist in the ".breadcrumb" "css_element"
    And I should see "Fire safety basics" in the ".breadcrumb" "css_element"
    When I click on "Edit" "link" in the "region-main" "region"
    Then "Fire safety basics" "link" should exist in the ".breadcrumb" "css_element"
    And I should see "Edit" in the ".breadcrumb li:last-child" "css_element"
    When I follow "Fire safety basics"
    And I press "Import new version"
    Then "Fire safety basics" "link" should exist in the ".breadcrumb" "css_element"
    And I should see "Import new version" in the ".breadcrumb li:last-child" "css_element"

  @javascript
  Scenario: Editors without site administration rights find the catalogue in the user menu
    Given the following "categories" exist:
      | name   | category | idnumber |
      | Safety | 0        | SAFE     |
    And the following "roles" exist:
      | shortname       | name             |
      | catalogueeditor | Catalogue editor |
    And the following "role capability" exists:
      | role                       | catalogueeditor |
      | local/nextpool:manageitems | allow           |
    And the following "users" exist:
      | username | firstname | lastname |
      | editor1  | Edna      | Editor   |
      | learner1 | Lea       | Learner  |
    And the following "role assigns" exist:
      | user    | role            | contextlevel | reference |
      | editor1 | catalogueeditor | Category     | SAFE      |
    When I log in as "editor1"
    And I follow "Nextpool" in the user menu
    Then I should see "Catalogue items" in the "region-main" "region"
    And "Add item" "button" should exist
    And I log out
    When I log in as "learner1"
    Then "Nextpool" "link" should not exist in the user menu
