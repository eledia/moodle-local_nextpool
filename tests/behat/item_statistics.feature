@local @local_nextpool
Feature: The editorial team sees the anonymous statistics of a catalogue item (F15, task127)
  In order to see how a content item works across courses and whether a new version works better
  As a member of the editorial team or an item editor
  I need a tab "Statistics" on the item with placements and key figures in total and per version, without courses or persons

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
      | Course 2 | C2        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
      | editor1  | Edda      | Editor   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "mod_nextpool > xapi items" exist:
      | title       |
      | Pump basics |
    And the following "local_nextpool > placements" exist:
      | item        | course |
      | Pump basics | C1     |
      | Pump basics | C2     |

  Scenario: The chip "Placed in courses" leads to the key figures, without course names (F15.AC01, test136)
    Given the following "local_nextpool > item statistics" exist:
      | item        | version | started | completed | passed | completionrate | passrate | scoremedian | scorelow | scorehigh | durationmedian | attemptsmedian | coursecount |
      | Pump basics | 0       | 10      | 8         | 6      | 80             | 75       | 82.5        | 70       | 90        | 840            | 1              | 2           |
      | Pump basics | 1       | 10      | 8         | 6      | 80             | 75       | 82.5        | 70       | 90        | 840            | 1              |             |
    And I am on the "Pump basics" "local_nextpool > Item" page logged in as "admin"
    When I follow "Placements: 2"
    Then I should see "Statistics" in the "nav[aria-label='Catalogue item']" "css_element"
    And I should see "As of"
    And I should see "2" in the "dl.local-nextpool-stats__facts" "css_element"
    And I should see "Courses with learning data"
    And the following should exist in the "local-nextpool-stats__table" table:
      | Version   | Started | Completed | Passed | Completion rate | Pass rate | Median score | Middle half of scores | Median attempts |
      | Total     | 10      | 8         | 6      | 80%             | 75%       | 82.5%        | 70% to 90%            | 1               |
      | Version 1 | 10      | 8         | 6      | 80%             | 75%       | 82.5%        | 70% to 90%            | 1               |
    And I should see "0:14" in the "Total" "table_row"
    And I should not see "Course 1" in the "region-main" "region"
    And I should not see "Course 2" in the "region-main" "region"
    And I should see "They can go down when learning data is deleted"

  Scenario: Small groups, values that could be worked out and missing results are shown as text (F15.AC02, US-F15-3)
    Given the following "mod_nextpool > xapi versions" exist:
      | item        |
      | Pump basics |
      | Pump basics |
    And the following "local_nextpool > item statistics" exist:
      | item        | version | started | completed | passed | completionrate | passrate | scoremedian | scorelow | scorehigh | durationmedian | attemptsmedian | flags                                                                                                                                                         |
      | Pump basics | 0       |         |           |        |                |          |             |          |           |                |                | started:derived,completed:derived,passed:derived,completionrate:derived,passrate:derived,scoremedian:derived,scorelow:derived,scorehigh:derived,durationmedian:derived,attemptsmedian:derived |
      | Pump basics | 1       | 9       | 7         | 0      | 77.78          |          |             |          |           | 600            | 1              | passrate:none,scoremedian:none,scorelow:none,scorehigh:none                                                                                                   |
      | Pump basics | 2       |         |           |        |                |          |             |          |           |                |                |                                                                                                                                                               |
    When I am on the "Pump basics" "local_nextpool > Item statistics" page logged in as "admin"
    Then I should see "Not shown so that small groups cannot be worked out" in the "Total" "table_row"
    And I should see "No result reported" in the "Version 1" "table_row"
    And I should see "No score reported" in the "Version 1" "table_row"
    And I should see "77.8%" in the "Version 1" "table_row"
    And I should see "Fewer than 5 learners, no statistics yet" in the "Version 2" "table_row"
    And I should see "Not used yet" in the "Version 3" "table_row"
    And I should see "a value is shown only when it is based on at least 5 people"
    And I should see "the value is not shown so that the figures of a small group cannot be worked out"
    And I should see "the content does not report this value"
    And I should not see "Courses with learning data"

  Scenario: Statistics not calculated yet
    When I am on the "Pump basics" "local_nextpool > Item statistics" page logged in as "admin"
    Then I should see "No statistics yet. They are calculated overnight."
    And I should see "2" in the "dl.local-nextpool-stats__facts" "css_element"
    And "local-nextpool-stats__table" "table" should not exist

  Scenario: An item of a type without learning data shows the placements and a notice (F15.AC06, US-F15-4, test141)
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path               | content |
      | Library  | Safety/handout.pdf | handout |
    And the following "local_nextpool > items" exist:
      | title   | location | file               | published |
      | Handout | Library  | Safety/handout.pdf | 1         |
    And the following "local_nextpool > placements" exist:
      | item    | course |
      | Handout | C1     |
    When I am on the "Handout" "local_nextpool > Item statistics" page logged in as "admin"
    Then I should see "Learning data is not recorded for this type of content. The statistics show the placements only."
    And I should see "Courses" in the "dl.local-nextpool-stats__facts" "css_element"
    And I should not see "Pass rate"
    And I should not see "Completion rate"

  Scenario: Item editors see the statistics, teachers without editorial rights do not (F15.AC03, test138)
    Given the following "local_nextpool > item editors" exist:
      | item        | user    |
      | Pump basics | editor1 |
    When I am on the "Pump basics" "local_nextpool > Item statistics" page logged in as "editor1"
    Then I should see "No statistics yet."
    And I log out
    # A teacher finds the item in the course catalogue, but without a way to its statistics. Opening the item page
    # or the statistics page directly ends on the error page (access::require_view(), access_test, item_editor_test).
    When I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Content catalogue" in current page administration
    Then I should see "Pump basics" in the "region-main" "region"
    And I should not see "Statistics" in the "region-main" "region"
    And "//a[contains(@href, '/local/nextpool/statistics.php')]" "xpath_element" should not exist
    And "//a[contains(@href, '/local/nextpool/item.php')]" "xpath_element" should not exist

  @javascript @accessibility
  Scenario: The statistics work with the keyboard, reflow at 320 px and pass Axe (F15.AC08, test143)
    Given the following "mod_nextpool > xapi versions" exist:
      | item        |
      | Pump basics |
    And the following "local_nextpool > item statistics" exist:
      | item        | version | started | completed | passed | completionrate | passrate | scoremedian | scorelow | scorehigh | durationmedian | attemptsmedian | coursecount | flags         |
      | Pump basics | 0       | 12      | 9         | 7      | 75             | 77.78    | 82.5        | 70       | 90        | 840            | 1              | 2           |               |
      | Pump basics | 1       | 9       | 7         | 5      | 77.78          |          | 80          | 70       | 90        | 900            | 1              |             | passrate:none |
    And I am on the "Pump basics" "local_nextpool > Item" page logged in as "admin"
    When I tab to "Placements: 2" "link" in the catalogue
    Then the focused element should have a visible focus indicator
    When I tab to "//nav[@aria-label='Catalogue item']//a[contains(., 'Statistics')]" "xpath_element" in the catalogue
    Then the focused element should have a visible focus indicator
    And I press the enter key
    And I should see "Key figures in total and per version"
    And the page should meet accessibility standards with "wcag143" extra tests
    When I tab to "//nav[@aria-label='Catalogue item']//a[contains(., 'Item')]" "xpath_element" in the catalogue
    Then the focused element should have a visible focus indicator
    When I change the viewport size to "320x800"
    Then the page should not need horizontal scrolling
    And the page should meet accessibility standards with "wcag143" extra tests
