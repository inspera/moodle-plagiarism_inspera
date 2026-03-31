@plagiarism_inspera
Feature: Admin config for Inspera Originality plugin
  In order to configure Inspera Originality globally
  As an admin
  I need to save and verify plugin settings

  Background:
    # Turn on the master switch so the plugin appears in the menu
    Given the following config values are set as admin:
      | enableplagiarism | 1 |

  @javascript
  Scenario: Admin successfully saves configuration (Happy Path)
    Given I log in as "admin"
    When I navigate to "Plugins > Plagiarism > Inspera Originality Plugin" in site administration

    # We use our specific dummy URL to trigger the PHP Behat Bypass
    And I set the field "Enable Originality check" to "1"
    And I set the field "Base API URL" to "https://api.originality.example/v1"
    And I set the field "Client ID" to "inspera-api-secret"
    And I set the field "Institution ID" to "institution-001"
    And I press "Save changes"

    # We expect the success message because the bypass skipped the cURL check
    Then I should see "Originality Settings Saved"
    And the field "Enable Originality check" matches value "1"
    And the field "Base API URL" matches value "https://api.originality.example/v1"

  @javascript
  Scenario: Admin enters invalid API credentials (Sad Path)
    Given I log in as "admin"
    When I navigate to "Plugins > Plagiarism > Inspera Originality Plugin" in site administration

    # We use a completely fake URL so the PHP Behat Bypass ignores it
    And I set the field "Enable Originality check" to "1"
    And I set the field "Base API URL" to "https://this-is-a-bad-url.test"
    And I set the field "Client ID" to "bad-client"
    And I set the field "Institution ID" to "bad-inst"
    And I press "Save changes"

    # We expect the real cURL error message to appear on the screen!
    # (Behat only needs a partial match, so we don't have to type the whole long error)
    Then I should see "Could not connect to Originality API"
