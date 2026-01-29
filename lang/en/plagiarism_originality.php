<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings for the Inspera Originality plagiarism plugin.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Your Name (Your Company)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Inspera Originality Plugin';

// === Plugin settings ===
$string['originality'] = 'Inspera Originality settings';
$string['tab_settings'] = 'Connection Settings';
$string['tab_defaults'] = 'Default Settings';
$string['tab_management'] = 'Submissions management';
$string['baseurl'] = 'Base API URL';
$string['baseurl_help'] = 'The root URL of the originality detection API (e.g. https://api.originality.com/v1).';
$string['clientid'] = 'Client ID';
$string['clientid_help'] = 'Please contact your account manager to obtain this value.';
$string['institutionid'] = 'Institution ID';
$string['institutionid_help'] = 'Please contact your account manager to obtain this value.';
$string['enableplugin'] = 'Enable Inspera Originality for {$a}';

// === Plugin Defaults ===
$string['use_originality'] = 'Enable Originality check';
$string['use_originality_admin'] = 'Enable Originality check';
$string['use_originality_admin_help'] = 'If the originality check should be enabled by default.';
$string['use_originality_teachers'] = 'Enable Originality check';
$string['use_originality_teachers_help'] = 'If enabled, an originality report will be generated.';
$string['originalitydefaults_assign'] = 'Default assign settings';
$string['originalitydefaults_forum'] = 'Default forum settings';
$string['originalitydefaults_workshop'] = 'Default workshop settings';
$string['originalitydefaults_quiz'] = 'Default quiz settings';
$string['defaultsdesc'] = 'The following settings are the defaults set when enabling Inspera Originality within an Activity Module';
$string['defaultupdated'] = 'Default values updated';
$string['originality_enable_translations'] = 'Translations';
$string['originality_enable_translations_help'] = 'The selected languages will be checked in the originality analysis. Up to three languages can be selected.';
$string['originality_metadata_analysis'] = 'Metadata analysis';
$string['originality_metadata_analysis_help'] = 'Enable analysis of the metadata for additional originality insights.';
$string['originality_enable_ai'] = 'AI authorship prediction';
$string['originality_enable_ai_help'] = 'If enabled, the originality check will include the analysis on whether AI was used to create the file.';
$string['originality_archive'] = 'Archive documents';
$string['originality_archive_help'] = 'If enabled, the submissions will be stored to be included in future originality analysis.';
$string['originality_enable_context_similarity'] = 'Enable contextual similarity';
$string['originality_enable_context_similarity_help'] = 'The minimal percentage of contextual similarity required to trigger a similarity alert.';
$string['originality_context_threshold'] = 'Context similarity threshold (%)';
$string['originality_context_threshold_help'] = 'The minimal percentage of contextual similarity required to trigger a similarity alert. The threshold must be at least 50%.';
$string['contextthresholdmin'] = 'The threshold must be at least 50%.';
$string['originality_translation_languages'] = 'Select supported translation languages';
$string['originality_translation_languages_help'] = 'The selected languages will be checked in the originality analysis. Up to three languages can be selected.';
$string['originality_enable_include_urls'] = 'Include URLs?';
$string['originality_enable_include_urls_help'] = 'If enabled, the specified URLs will be included in the originality check.';
$string['originality_include_urls'] = 'URLs to include';
$string['originality_include_urls_help'] = 'Specify URLs that should be included in the originality check. Multiple urls should be separated by commas.';
$string['originality_enable_exclude_urls'] = 'Exclude URLs?';
$string['originality_enable_exclude_urls_help'] = 'If enabled, the specified URLs will be excluded from the originality check.';
$string['originality_exclude_urls'] = 'URLs to exclude';
$string['originality_exclude_urls_help'] = 'Specify URLs that should be excluded from the originality check. Multiple urls should be separated by commas.';
$string['errorincludeurls'] = 'Please enter at least one URL to include.';
$string['errorexcludeurls'] = 'Please enter at least one URL to exclude.';
$string['originality_allowallfile'] = 'Allow all supported file types';
$string['originality_allowallfile_help'] = 'If enabled, all the file types supported by Inspera Originality will be submitted for originality checking.  Click <a href="https://support.inspera.com/hc/en-us/articles/15852514280093-What-types-of-questions-and-file-formats-does-it-apply-to" target="_blank">here</a> to see the supported file types.';
$string['originality_show_student_report'] = 'Share report with the student';
$string['originality_show_student_report_help'] = 'Define if and when the report should be shared with the students.';
$string['showstudentreport_not_shared'] = 'Never shared';
$string['showstudentreport_immediately'] = 'Immediately after it is available';
$string['showstudentreport_after_grading'] = 'After grading';
$string['showstudentreport_due_date'] = 'Due date';
$string['originality_advanceditems'] = 'Advanced settings';
$string['originality_advanceditems_help'] = 'These settings will be hidden behind the "Show more" link to declutter the form.';
$string['originality_hiddenitems'] = 'Hidden settings';
$string['originality_hiddenitems_help'] = 'These settings will be completely hidden from non-administrators. The default value will be used.';
$string['originality_lockeditems'] = 'Locked settings';
$string['originality_lockeditems_help'] = 'These settings will be visible to non-administrators but read-only (frozen). They will also be automatically moved to the "Show more" section.';
$string['originality_draft_submit'] = 'When should the file be submitted for originality check';
$string['originality_draft_submit_help'] = 'Select when the file should be sent for originality checking. the option "when student sends for marking" will only be available in activities in which the students need to click submit.';
$string['submitondraft'] = 'Submit file when first uploaded';
$string['submitonfinal'] = 'Submit file when student sends for marking';
$string['originality_selectfiletypes'] = 'File types to submit';
$string['originality_selectfiletypes_help'] = 'Select which file types should be submitted for originality checking.';
$string['errorselectfiletypesrequired'] = '⚠ Please select at least one file type to submit for originality checking.';
$string['originality_restrictcontent'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_admin'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_admin_help'] = 'Whether originality should be checked for uploaded files and/or texts. If only files or inline-text is available in a specific assignment, then those will be submitted regardless of what is defined here.';
$string['originality_restrictcontent_teachers'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_teachers_help'] = 'Whether originality should be checked for uploaded files and/or texts.';
$string['restrictcontentfiles'] = 'Only submit attached files';
$string['restrictcontentno'] = 'Submit everything';
$string['restrictcontenttext'] = 'Only submit in-line text';
$string['savedconfigsuccess'] = 'Originality Settings Saved';


$string['sendfiles'] = 'Inspera Originality send queued files';
$string['deleteorphanedfiles'] = 'Delete orphaned originality records';
$string['status'] = 'Originality status';
$string['similarity'] = 'Similarity';
$string['translation_similarity'] = 'Translation similarity';
$string['ai_index'] = 'AI-generated content index';
$string['character_replacement'] = 'Character replacement detected';
$string['hidden_text'] = 'Hidden text detected';
$string['image_as_text'] = 'Image-as-text detected';
$string['viewreport'] = 'View originality report';

$string['reportlinkprefix'] = 'Inspera Originality Report';
$string['reportlinkscore'] = '{$a}%';
$string['statusrequested'] = 'Inspera Originality Report: Queued';
$string['statuspending'] = 'Inspera Originality Report: Pending...';
$string['statuserror'] = 'Inspera Originality Report: An error occurred.';
$string['reportaccessdenied'] = 'You do not have access to this originality report. Please contact your system administrator.';

// UI notices / tooltips
$string['use_originality_group_incompatible'] = 'Originality check is not compatible with group submissions. Disable group submissions to enable originality checks.';

$string['courseshortname'] = 'Course shortname';
$string['originalitydebug'] = 'Submission Management';
$string['errorcode'] = 'Error code';
$string['errorcode_3'] = 'Error: Document too short';
$string['errorcode_4'] = 'Error: Deadline exceeded';
$string['errorcode_101'] = 'Error: Document cap reached';
$string['errorcode_5000'] = 'Error: Report generation failed';
$string['errorcode_7001'] = 'Error: Failed to index';
$string['errorcode_unknown'] = 'Error: {$a}';

$string['status_pending'] = 'Pending';
$string['status_report_requested'] = 'Queued';
$string['status_finished'] = 'Finished';
$string['status_error'] = 'Error';
$string['status_external_error'] = 'External Error';

// Management page columns/filters
$string['description'] = 'Description';

$string['id'] = 'ID';
$string['identifier'] = 'Identifier';
$string['attempts'] = 'Attempts made';
$string['timecreated'] = 'Time created';
$string['resubmit'] = 'Resubmit';
$string['delete'] = 'Delete';
$string['getscore'] = 'Get Score';
$string['cronwarningsendfiles'] = 'The Inspera Originality plugin send files task has not been run for at least 30 min - Cron must be configured to allow Inspera Originality to function correctly.';
$string['originalityfiles'] = 'Originality Files';
$string['fileresubmitted'] = 'File queued for resubmission.';
$string['filedeleted'] = 'File deleted.';
$string['deleteselectedfiles'] = 'Delete Selected Files';
$string['resubmitselectedfiles'] = 'Resubmit Selected Files';
$string['deleteallfiltered'] = 'Delete all filtered files';
$string['resubmitallfiltered'] = 'Resubmit all filtered files';
$string['areyousurebulk'] = 'Are you sure you want to delete {$a} selected files?';
$string['areyousurebulkresubmit'] = 'Are you sure you want to resubmit {$a} selected files?';
$string['areyousurefiltereddelete'] = 'Are you sure you want to delete ALL {$a} files currently listed?';
$string['areyousurefilteredresubmit'] = 'Are you sure you want to resubmit ALL {$a} files currently listed?';
$string['nofilesselected'] = 'No files were selected.';
$string['recordsdeleted'] = 'No files were selected.';
$string['filesresubmitted'] = 'Files queued for resubmission.';
$string['resubmit_tooltip'] = 'Retry report submission using the current originality checking settings.';
$string['connectionerror'] = 'Could not connect to Originality API';
$string['apitokenerror'] = 'Authentication failed. The API returned: "{$a}"';
