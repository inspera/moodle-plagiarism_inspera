@plagiarism @plagiarism_inspera @javascript
Feature: Resubmit all button visibility
  In order to avoid showing bulk actions in individual grading
  As a teacher
  I need "Generate originality reports" only on submissions list (action=grading), not on grader (action=grader)

  Background:
    Given the following config values are set as admin:
      | enableplagiarism | 1 |
    And the following config values are set as admin:
      | enabled           | 1 | plagiarism_inspera |
      | enable_mod_assign | 1 | plagiarism_inspera |
      | baseurl           | https://api.originality.example/v1 | plagiarism_inspera |
      | clientid          | dummyclient | plagiarism_inspera |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name                        | idnumber |
      | assign   | C1     | Originality Test Assignment | assign1  |
    And the following "permission overrides" exist:
      | capability                           | permission | role           | contextlevel | reference |
      | plagiarism/inspera:requestallreports | Allow      | editingteacher | Course       | C1        |

  Scenario: Button is hidden in individual grader (action=grader)
    # 1. Log in and enable the plugin for this specific assignment.
    Given I log in as "teacher1"
    And I am on the "Originality Test Assignment" "assign activity editing" page
    And I set the following fields to these values:
      | Enable Originality check | 1 |

    And I wait "3" seconds
    And I press "Save and display"

    # 2. Go to submissions list (action=grading) and verify button is present.
    When I click on "Submissions" "link"
    Then I should see "Generate originality reports"

    # 3. Navigate to the individual grader interface.
    And I open the action menu in "Student One" "table_row"
    And I choose "Grade" in the open action menu
    And I wait "3" seconds

    # 4. Verify the PHP restriction worked and the button is gone.
    Then I should not see "Generate originality reports"
