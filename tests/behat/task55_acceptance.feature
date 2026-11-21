@local @local_nextpool @javascript @accessibility
Feature: Browser acceptance of the UX pass (task55)
  In order to use the catalogue with keyboard, zoom and assistive technology
  As a content editor or teacher
  I need pages that I can reach with the tab key, that show where the focus is, fit into 320 px and pass Axe

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
    And the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content     |
      | Library  | Safety/handout.pdf | first draft |

  Scenario: Empty editorial list distinguishes "no items" from "filter does not match" (bug77)
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    Then I should see "There are no catalogue items yet."
    And I should not see "No items match the filter."
    And the page should meet accessibility standards with "wcag143" extra tests
    And the following "local_nextpool > items" exist:
      | title              | location |
      | Fire safety basics | Library  |
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Catalogue categories"
    And I press "Add category"
    And I set the field "Category name" to "Occupational safety and health protection in production halls"
    And I press "Save changes"
    When I visit "/local/nextpool/items.php?search=nomatch"
    Then I should see "No items match the filter."
    And I change the viewport size to "320x800"
    And the page should not need horizontal scrolling
    And I change the viewport size to "large"
    And I tab to "Clear filter" "link" in the catalogue
    And the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "Fire safety basics" in the "region-main" "region"

  Scenario: The item page and its "More actions" menu work with the keyboard (bug72)
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    And I should see "Fire safety basics" in the "h2" "css_element"
    When I tab to "More actions" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And "Delete" "link" should be visible
    And I press the escape key
    And "Delete" "link" should not be visible
    And the page should meet accessibility standards with "wcag143" extra tests
    # Best practice includes the heading order and one main heading.
    And the "region-main" "region" should meet "best-practice" accessibility standards
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling

  Scenario: The compact item page names its icons, hides empty blocks and offers the next step (task104, task105)
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
    And the following "local_nextpool > published versions" exist:
      | item               | content      |
      | Fire safety basics | second draft |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    # Version 2 is published, the item is still a draft: the notice carries the item step, the header does not repeat it.
    Then I should see "Version 2 is published, but the item is not"
    And "Submit item for review" "button" should exist in the ".local-nextpool-statusnotice" "css_element"
    And "Submit item for review" "button" should not exist in the ".local-nextpool-item__header" "css_element"
    And "Submit version 1 for review" "button" should exist
    And "Compare with version 1" "link" should exist
    And ".local-nextpool-competencies" "css_element" should not exist
    And I should not see "Identifier"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "Submit item for review" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "In review" in the ".local-nextpool-item__status" "css_element"
    When I tab to "Publish item" "button" in the catalogue
    And I press the enter key
    Then I should see "Published" in the ".local-nextpool-item__status" "css_element"
    And ".local-nextpool-statusnotice" "css_element" should not exist
    # Withdrawing sits in "More actions" and asks first (PO decisions 2 and 7).
    When I tab to "More actions" "button" in the catalogue
    And I press the enter key
    And I click on "Withdraw item" "link"
    Then I should see "It can then no longer be chosen in any course catalogue."
    When I press "Cancel"
    Then I should see "Published" in the ".local-nextpool-item__status" "css_element"
    When I click on "Technical details" "text"
    Then I should see "Identifier"
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling

  Scenario: The item facts sit in the card of the latest version as a named icon and pills (task165)
    Given the following "local_nextpool > items" exist:
      | title              | location | file               | license      |
      | Fire safety basics | Library  | Safety/handout.pdf | cc-nc-sa-4.0 |
    And the following "local_nextpool > published versions" exist:
      | item               | content      |
      | Fire safety basics | second draft |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    Then "#local-nextpool-details" "css_element" should not exist
    And ".local-nextpool-itemfacts" "css_element" should exist in the ".local-nextpool-version" "css_element"
    And I should see "Library" in the ".local-nextpool-itemfacts" "css_element"
    And I should see "Site" in the ".local-nextpool-itemfacts" "css_element"
    And I should see "CC BY-NC-SA 4.0" in the ".local-nextpool-itemfacts" "css_element"
    And "[title='Storage location: Library']" "css_element" should exist in the ".local-nextpool-itemfacts" "css_element"
    And "//span[contains(@class, 'local-nextpool-factsicon')]/span[@class='visually-hidden' and .='Updates in courses: Direct']" "xpath_element" should exist in the ".local-nextpool-itemfacts" "css_element"
    # Bootstrap moves the title into data-bs-original-title when it sets up the tooltip.
    And "//span[contains(@class, 'local-nextpool-factsicon')][@title='Updates in courses: In place (learner progress is kept)' or @data-bs-original-title='Updates in courses: In place (learner progress is kept)']" "xpath_element" should exist
    # The icon-only fact is reachable with the keyboard, so its tooltip opens without a mouse.
    When I tab to ".local-nextpool-factsicon" "css_element" in the catalogue
    Then the focused element should have a visible focus indicator
    And the page should meet accessibility standards with "wcag143" extra tests
    When I click on "Technical details" "text" in the ".local-nextpool-version" "css_element"
    Then I should see "Identifier" in the ".local-nextpool-version" "css_element"
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling

  Scenario: The item list offers the next step as a named icon and keeps the filter (task102)
    Given the following "local_nextpool > items" exist:
      | title              | location | file               |
      | Fire safety basics | Library  | Safety/handout.pdf |
      | Evacuation plan    | Library  | Safety/handout.pdf |
    And I log in as "admin"
    When I visit "/local/nextpool/items.php?search=Fire"
    Then "Submit version 1 for review: Fire safety basics" "button" should exist
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "Submit version 1 for review: Fire safety basics" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    Then I should see "Status changed to"
    And I should see "Version waiting for review" in the "Fire safety basics" "table_row"
    And I should not see "Evacuation plan"
    # A version in review is decided on the review page (task80).
    And "Review version 1: Fire safety basics" "button" should exist
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling

  Scenario: Repeated author rows of the item form are named and removable with the keyboard (bug65, bug71)
    Given the following "local_nextpool > items" exist:
      | title              | location |
      | Fire safety basics | Library  |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Fire safety basics"
    When I click on "Edit" "link" in the "region-main" "region"
    And I expand all fieldsets
    Then "Role of author 1" "field" should exist
    And "Name of author 1 (without Moodle account)" "field" should exist
    When I tab to "Remove author 1" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    # No Axe check here: the core autocomplete fields (tags, author account, competency) report
    # aria-required-children and aria-valid-attr-value while empty. That is core markup, see 05-quality.md.
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling

  Scenario: Row actions of categories and storage locations are named buttons with a success message (bug66, bug67)
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Catalogue categories"
    And I press "Add category"
    And I set the field "Category name" to "Safety"
    And I press "Save changes"
    And I press "Add category"
    And I set the field "Category name" to "Health"
    And I press "Save changes"
    When I tab to "Move up: Health" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "The category \"Health\" was moved."
    And "[role=alert]" "css_element" should exist in the "region-main" "region"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling
    When I change the viewport size to "large"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Storage locations"
    And I tab to "Disable: Library" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "The storage location \"Library\" is disabled."
    And the page should meet accessibility standards with "wcag143" extra tests

  Scenario: The course catalogue is paged, keyboard usable and fits into 320 px (bug68, bug77)
    Given the following "local_nextpool > items" exist:
      | title    | location | file               | published |
      | Item 01  | Library  | Safety/handout.pdf | 1         |
      | Item 02  | Library  | Safety/handout.pdf | 1         |
      | Item 03  | Library  | Safety/handout.pdf | 1         |
      | Item 04  | Library  | Safety/handout.pdf | 1         |
      | Item 05  | Library  | Safety/handout.pdf | 1         |
      | Item 06  | Library  | Safety/handout.pdf | 1         |
      | Item 07  | Library  | Safety/handout.pdf | 1         |
      | Item 08  | Library  | Safety/handout.pdf | 1         |
      | Item 09  | Library  | Safety/handout.pdf | 1         |
      | Item 10  | Library  | Safety/handout.pdf | 1         |
      | Item 11  | Library  | Safety/handout.pdf | 1         |
      | Item 12  | Library  | Safety/handout.pdf | 1         |
      | Item 13  | Library  | Safety/handout.pdf | 1         |
      | Item 14  | Library  | Safety/handout.pdf | 1         |
      | Item 15  | Library  | Safety/handout.pdf | 1         |
      | Item 16  | Library  | Safety/handout.pdf | 1         |
      | Item 17  | Library  | Safety/handout.pdf | 1         |
      | Item 18  | Library  | Safety/handout.pdf | 1         |
      | Item 19  | Library  | Safety/handout.pdf | 1         |
      | Item 20  | Library  | Safety/handout.pdf | 1         |
      | Item 21  | Library  | Safety/handout.pdf | 1         |
      | Item 22  | Library  | Safety/handout.pdf | 1         |
      | Item 23  | Library  | Safety/handout.pdf | 1         |
      | Item 24  | Library  | Safety/handout.pdf | 1         |
      | Item 25  | Library  | Safety/handout.pdf | 1         |
    And I am on the "C1" "Course" page logged in as "teacher1"
    When I navigate to "Content catalogue" in current page administration
    Then I should see "Item 24"
    And I should not see "Item 25"
    And the page should meet accessibility standards with "wcag143" extra tests
    And the "region-main" "region" should meet "best-practice" accessibility standards
    When I tab to "Next page" "link" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "Item 25"
    And I should not see "Item 01"
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling
    When I set the field "Search" to "nomatch"
    And I press the enter key
    Then I should see "No published content matches the filter."
    And I should see "Clear filter"
    And the page should not need horizontal scrolling
    And the page should meet accessibility standards with "wcag143" extra tests

  Scenario: The update banner sits in the main region and the placement list works with the keyboard (bug64, bug67)
    Given the following "local_nextpool > items" exist:
      | title              | location | file               | published |
      | Fire safety basics | Library  | Safety/handout.pdf | 1         |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I follow "Place in course"
    And I set the field "Update policy" to "Ask me first"
    And I press "Place in course"
    And the following "local_nextpool > published versions" exist:
      | item               | content        |
      | Fire safety basics | second edition |
    And I run all adhoc tasks
    When I am on the "C1" "Course" page
    Then I should see "One activity from the content catalogue has an update waiting for you." in the "region-main" "region"
    And "#local_nextpool-updatebanner [role=alert]" "css_element" should not exist
    And I tab to "Review updates" "link" in the catalogue
    And the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "Version 2 is waiting for your decision." in the "Fire safety basics" "table_row"
    And "Fire safety basics" "table_row" should exist
    And the page should meet accessibility standards with "wcag143" extra tests
    And the "region-main" "region" should meet "best-practice" accessibility standards
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling
    When I change the viewport size to "large"
    And I tab to "Apply version 2: Fire safety basics" "button" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "The activity now uses version 2."

  Scenario: The activity page of a placed package fits into 320 px (bug73)
    Given the following "mod_nextpool > xapi items" exist:
      | title          | author     |
      | Fire drill run | Ada Author |
    And I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    And I click on "Place in course" "link" in the "Fire drill run" "list_item"
    And I set the field "Display" to "Embedded in the page"
    And I press "Place in course"
    When I am on the "Fire drill run" "nextpool activity" page
    Then I should see "Version 1"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling

  Scenario: The LRS duration setting and the learning data page fit into 320 px (bug75, bug76)
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=local_eledia_lrs_settings"
    Then the "s_local_eledia_lrs_launchttl[u]" "field" should be enabled
    # No Axe check of the admin settings page: Axe often runs out of time there ("No data"), and the page is core markup.
    # No reflow check on the admin settings page: core rows and the long options of "legalmode" overflow (bug86).
    When I change the viewport size to "320x800"
    And I visit "/local/eledia_lrs/mydata.php"
    Then I should not see "Preliminary"
    And the page should not need horizontal scrolling
    And the page should meet accessibility standards with "wcag143" extra tests
