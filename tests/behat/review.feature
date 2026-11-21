@local @local_nextpool
Feature: Reviewers publish, return or reject a version on the review page
  In order to decide on versions in review with a reason and keep the storage location up to date
  As a reviewer
  I need a review page with one labelled button per decision (F07, task80)

  Background:
    Given the following "local_nextpool > storage locations" exist:
      | name    |
      | Library |
    And the following "local_nextpool > storage files" exist:
      | location | path        | content     |
      | Library  | handout.pdf | first draft |
    And the following "local_nextpool > items" exist:
      | title         | location | file        |
      | Safety basics | Library  | handout.pdf |

  @javascript @accessibility
  Scenario: Return a version with a reason, resubmit it and publish it with the item
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Safety basics"
    And I press "Submit version 1 for review"
    Then I should see "Version 1 is in review. The catalogue entry currently has the status \"Draft\"." in the ".local-nextpool-versionnotice" "css_element"
    And "Version 1 back to draft" "button" should not exist
    When I press "Review version 1"
    Then I should see "Review: Version 1"
    And I should see "Draft of the editorial team"
    And I should see "Submitted by Admin User"
    And the page should meet accessibility standards
    When I press "Return for revision"
    Then I should see "Please give a reason."
    And I should see "In review"
    When I set the field "Reason" to "Please add the emergency numbers."
    And I press "Return for revision"
    Then I should see "Version 1 was returned for revision."
    And I should see "Draft" in the ".local-nextpool-version .badge" "css_element"
    When I press "Submit version 1 for review"
    And I press "Review version 1"
    Then I should see "Please add the emergency numbers."
    When I press "Publish version 1"
    Then I should see "Version 1 was published."
    And I should see "Published" in the ".local-nextpool-item__status" "css_element"
    And I should see "Current version"

  @javascript @accessibility
  Scenario: Reject a version with a reason
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > eLeDia | Nextpool" in site administration
    And I follow "Safety basics"
    And I press "Submit version 1 for review"
    And I press "Review version 1"
    When I set the field "Reason" to "Replaced by the new handbook."
    And I press "Reject"
    Then I should see "Version 1 was rejected."
    And I should see "Rejected" in the ".local-nextpool-version .badge" "css_element"
    And "Submit version 1 for review" "button" should not exist
    And "Review version 1" "button" should not exist
    And I should see "Replaced by the new handbook." in the ".local-nextpool-versionnotice" "css_element"
    And "Submit item for review" "button" should not exist
    And "Submit item for review" "link" should not exist
    And the page should meet accessibility standards
