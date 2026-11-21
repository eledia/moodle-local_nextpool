@local @local_nextpool
Feature: The import page and the item form say which file a content type expects
  In order to give an item the right file
  As a content editor
  I need to see which file the type expects and choose only files that fit (task157, bug205-bug207)

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content                  |
      | Library  | Links/glossary.url | URL=https://example.com/ |
      | Library  | Links/package.zip  | not a link file          |
    And the following "local_nextpool > items" exist:
      | title    | location | type |
      | Glossary | Library  | url  |

  @javascript @accessibility
  Scenario: Only files that fit the content type can be selected
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Glossary"
    When I press "Import new version"
    Then I should see "A link file (.url) or a text file (.txt) with an http or https address."
    And I should see "Other files are listed but cannot be selected."
    And I follow "Links"
    And "Import as new version" "button" should exist in the "glossary.url" "table_row"
    And "Import as new version" "button" should not exist in the "package.zip" "table_row"
    And "th[scope='row']" "css_element" should exist in the "glossary.url" "table_row"
    And I should see "Does not fit the content type" in the "package.zip" "table_row"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I click on "Import as new version" "button" in the "glossary.url" "table_row"
    Then I should see "New draft version imported:"

  @javascript @accessibility
  Scenario: The item form names the expected file and offers only types that can be placed
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    When I press "Add item"
    Then I should see "Any file, for example a video, a PDF or a document."
    And the "Content type" select box should not contain "Other activity (backup)"
    And the "Content type" select box should not contain "Assignment"
    When I set the field "Content type" to "H5P"
    Then I should see "An H5P file (.h5p)."
    And I should not see "Any file, for example a video, a PDF or a document."
    # Axe only on the fields of this scenario: the empty core autocomplete of the authors in the same section reports
    # aria-allowed-role and aria-required-children (core markup, see 05-quality.md).
    And the "#fitem_id_type" "css_element" should meet accessibility standards with "wcag143" extra tests
    And the "#fitem_id_expectedfile_h5p" "css_element" should meet accessibility standards with "wcag143" extra tests

  @javascript @_file_upload
  Scenario: An uploaded file of another type is refused and not written into the storage location
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Glossary"
    And I press "Import new version"
    And I follow "Links"
    # A ZIP package, as in bug205; a text file without an address gets its own message (error_invalidurlfile).
    When I upload "lib/filestorage/tests/fixtures/test_infozip_3.zip" file to "Upload file into this folder" filemanager
    And I press "Upload"
    Then I should see "The file does not fit the content type of this item."
    And "test_infozip_3.zip" "table_row" should not exist

  @javascript @_file_upload
  Scenario: An upload does not replace a file that is not the item's own (bug162)
    Given the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |
      | Library  | Safety/empty.txt   | other item  |
    And the following "local_nextpool > items" exist:
      | title   | location | file               |
      | Handout | Library  | Safety/handout.pdf |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Handout"
    And I press "Import new version"
    When I upload "lib/tests/fixtures/empty.txt" file to "Upload file into this folder" filemanager
    And I press "Upload"
    Then I should see "A file \"Safety/empty.txt\" already exists in the storage location."
    And "Import as new version" "button" should exist in the "empty.txt" "table_row"
