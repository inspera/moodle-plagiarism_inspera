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
 * Language strings for the Inspera Originality plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['admin_overrides_info'] = 'Some features (like "Translations") do not have global defaults and are enabled by teachers directly within each activity. However, you can still use the "Hidden", "Locked", and "Advanced" lists to manage these features globally across the entire site.';
$string['admin_overrides_info_note'] = 'Note';
$string['ai_index'] = 'AI-generated content index';
$string['apitokenerror'] = 'Authentication failed. The API returned: "{$a}"';
$string['areyousurebulk'] = 'Are you sure you want to delete {$a} selected files?';
$string['areyousurebulkresubmit'] = 'Are you sure you want to resubmit {$a} selected files?';
$string['areyousurefiltereddelete'] = 'Are you sure you want to delete ALL {$a} files currently listed?';
$string['areyousurefilteredresubmit'] = 'Are you sure you want to resubmit ALL {$a} files currently listed?';
$string['attempts'] = 'Attempts made';
$string['baseurl'] = 'Base API URL';
$string['baseurl_help'] = 'The root URL of the originality detection API (e.g. https://api.originality.com/v1).';
$string['character_replacement'] = 'Character replacement detected';
$string['clientid'] = 'Client ID';
$string['clientid_help'] = 'Please contact your account manager to obtain this value.';
$string['connectionerror'] = 'Could not connect to Originality API';
$string['contextthresholdmin'] = 'The threshold must be at least 50%.';
$string['courseshortname'] = 'Course shortname';
$string['cronwarningsendfiles'] = 'The Inspera Originality plugin send files task has not been run for at least 30 min - Cron must be configured to allow Inspera Originality to function correctly.';
$string['defaultsdesc'] = 'The following settings are the defaults set when enabling Inspera Originality within an Activity Module';
$string['defaultupdated'] = 'Default values updated';
$string['delete'] = 'Delete';
$string['deleteallfiltered'] = 'Delete all filtered files';
$string['deleteorphanedfiles'] = 'Delete orphaned originality records';
$string['deleteselectedfiles'] = 'Delete Selected Files';
$string['description'] = 'Description';
$string['enableplugin'] = 'Enable Inspera Originality for {$a}';
$string['errorcode'] = 'Error code';
$string['errorcode_101'] = 'Error: Document cap reached';
$string['errorcode_3'] = 'Error: Document too short';
$string['errorcode_4'] = 'Error: Deadline exceeded';
$string['errorcode_5000'] = 'Error: Report generation failed';
$string['errorcode_7001'] = 'Error: Failed to index';
$string['errorcode_unknown'] = 'Error: {$a}';
$string['errorexcludeurls'] = 'Please enter at least one URL to exclude.';
$string['errorincludeurls'] = 'Please enter at least one URL to include.';
$string['errorselectfiletypesrequired'] = '⚠ Please select at least one file type to submit for originality checking.';
$string['filedeleted'] = 'File deleted.';
$string['fileresubmitted'] = 'File queued for resubmission.';
$string['filesresubmitted'] = 'Files queued for resubmission.';
$string['getscore'] = 'Get Score';
$string['hidden_text'] = 'Hidden text detected';
$string['id'] = 'ID';
$string['identifier'] = 'Identifier';
$string['image_as_text'] = 'Image-as-text detected';
$string['inspera:enable'] = 'Allow to enable/disable Inspera Originality for activities';
$string['inspera:manage_locked_settings'] = 'Allow to manage locked settings';
$string['inspera:requestallreports'] = 'Allow to request all Originality reports at once';
$string['inspera:resetfile'] = 'Allow to reset Inspera Originality reports';
$string['inspera:resubmitonclose'] = 'Allow to resubmit files on close/due date to Inspera Originality';
$string['inspera:viewreport'] = 'Allow to view Inspera Originality reports';
$string['institutionid'] = 'Institution ID';
$string['institutionid_help'] = 'Please contact your account manager to obtain this value.';
$string['last_resubmit_run'] = 'Last requested: {$a}';
$string['nofilesselected'] = 'No files were selected.';
$string['onlinetextsubmission'] = 'Online text submission';
$string['originality'] = 'Inspera Originality settings';
$string['originality_advanceditems'] = 'Advanced settings';
$string['originality_advanceditems_help'] = 'These settings will be hidden behind the "Show more" link to declutter the form.';
$string['originality_allowallfile'] = 'Allow all supported file types';
$string['originality_allowallfile_help'] = 'If enabled, all the file types supported by Inspera Originality will be submitted for originality checking.  Click <a href="https://support.inspera.com/hc/en-us/articles/15852514280093-What-types-of-questions-and-file-formats-does-it-apply-to" target="_blank">here</a> to see the supported file types.';
$string['originality_archive'] = 'Archive documents';
$string['originality_archive_help'] = 'If enabled, the submissions will be stored to be included in future originality analysis.';
$string['originality_context_threshold'] = 'Context similarity threshold (%)';
$string['originality_context_threshold_help'] = 'The minimal percentage of contextual similarity required to trigger a similarity alert. The threshold must be at least 50%.';
$string['originality_display_type'] = 'Score to display';
$string['originality_display_type_help'] = 'Choose the type of score to be used for this activity. Recommended "Originality score".';
$string['originality_draft_submit'] = 'When should the file be submitted for originality check';
$string['originality_draft_submit_help'] = 'Select when the file should be sent for originality checking. the option "when student sends for marking" will only be available in activities in which the students need to click submit.';
$string['originality_enable_ai'] = 'AI authorship prediction';
$string['originality_enable_ai_help'] = 'If enabled, the originality check will include the analysis on whether AI was used to create the file.';
$string['originality_enable_context_similarity'] = 'Enable contextual similarity';
$string['originality_enable_context_similarity_help'] = 'The minimal percentage of contextual similarity required to trigger a similarity alert.';
$string['originality_enable_exclude_urls'] = 'Exclude URLs?';
$string['originality_enable_exclude_urls_help'] = 'If enabled, the specified URLs will be excluded from the originality check.';
$string['originality_enable_include_urls'] = 'Include URLs?';
$string['originality_enable_include_urls_help'] = 'If enabled, the specified URLs will be included in the originality check.';
$string['originality_enable_translations'] = 'Translations';
$string['originality_enable_translations_help'] = 'The selected languages will be checked in the originality analysis. Up to three languages can be selected.';
$string['originality_exclude_urls'] = 'URLs to exclude';
$string['originality_exclude_urls_help'] = 'Specify URLs that should be excluded from the originality check. Multiple urls should be separated by commas.';
$string['originality_hiddenitems'] = 'Hidden settings';
$string['originality_hiddenitems_help'] = 'These settings will be completely hidden from non-administrators. The default value will be used.';
$string['originality_include_urls'] = 'URLs to include';
$string['originality_include_urls_help'] = 'Specify URLs that should be included in the originality check. Multiple urls should be separated by commas.';
$string['originality_lockeditems'] = 'Locked settings';
$string['originality_lockeditems_help'] = 'These settings will be visible to non-administrators but read-only (frozen). They will also be automatically moved to the "Show more" section.';
$string['originality_metadata_analysis'] = 'Metadata analysis';
$string['originality_metadata_analysis_help'] = 'Enable analysis of the metadata for additional originality insights.';
$string['originality_restrictcontent'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_admin'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_admin_help'] = 'Whether originality should be checked for uploaded files and/or texts. If only files or inline-text is available in a specific assignment, then those will be submitted regardless of what is defined here.';
$string['originality_restrictcontent_teachers'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_teachers_help'] = 'Whether originality should be checked for uploaded files and/or texts.';
$string['originality_score'] = 'Originality score';
$string['originality_selectfiletypes'] = 'File types to submit';
$string['originality_selectfiletypes_help'] = 'Select which file types should be submitted for originality checking.';
$string['originality_show_student_report'] = 'Share report with the student';
$string['originality_show_student_report_help'] = 'Define if and when the report should be shared with the students.';
$string['originality_translation_languages'] = 'Select supported translation languages';
$string['originality_translation_languages_help'] = 'The selected languages will be checked in the originality analysis. Up to three languages can be selected.';
$string['originalitydebug'] = 'Submission Management';
$string['originalitydefaults_assign'] = 'Default assign settings';
$string['originalitydefaults_forum'] = 'Default forum settings';
$string['originalitydefaults_quiz'] = 'Default quiz settings';
$string['originalitydefaults_workshop'] = 'Default workshop settings';
$string['originalityfiles'] = 'Originality Files';
$string['pluginname'] = 'Inspera Originality Plugin';
$string['privacy:metadata:core_files'] = 'The plugin links to files submitted for plagiarism analysis.';
$string['privacy:metadata:inspera'] = 'Data is transferred to the external Inspera Originality plagiarism service for similarity analysis.';
$string['privacy:metadata:inspera:content'] = 'The actual text or file content sent for analysis.';
$string['privacy:metadata:inspera:filename'] = 'The name of the file being processed.';
$string['privacy:metadata:inspera:fullname'] = 'The full name of the user assigned to the submission.';
$string['privacy:metadata:plagiarism_inspera_subs'] = 'Information about the similarity reports generated by Inspera Originality.';
$string['privacy:metadata:plagiarism_inspera_subs:ai_index'] = 'An indicator of potential AI-generated content.';
$string['privacy:metadata:plagiarism_inspera_subs:description'] = 'Technical logs or error messages related to the submission processing.';
$string['privacy:metadata:plagiarism_inspera_subs:externalid'] = 'The document identifier used by the Inspera Originality.';
$string['privacy:metadata:plagiarism_inspera_subs:originality_score'] = 'The originality percentage returned by Inspera for the submission.';
$string['privacy:metadata:plagiarism_inspera_subs:similarity'] = 'The similarity score percentage returned by Inspera Originality.';
$string['privacy:metadata:plagiarism_inspera_subs:status'] = 'The current state of the submission in the Inspera Originality workflow.';
$string['privacy:metadata:plagiarism_inspera_subs:submissionid'] = 'The internal Moodle submission identifier.';
$string['privacy:metadata:plagiarism_inspera_subs:timecreated'] = 'The date and time the report request was initiated.';
$string['privacy:metadata:plagiarism_inspera_subs:timemodified'] = 'The date and time the report was last updated.';
$string['privacy:metadata:plagiarism_inspera_subs:translation_similarity'] = 'The score for potential translated plagiarism.';
$string['privacy:metadata:plagiarism_inspera_subs:userid'] = 'The ID of the user who submitted the document.';
$string['recordsdeleted'] = 'No files were selected.';
$string['reportaccessdenied'] = 'You do not have access to this originality report. Please contact your system administrator.';
$string['reportlinkprefix'] = 'Inspera Originality Report';
$string['reportlinkscore'] = '{$a}%';
$string['restrictcontentfiles'] = 'Only submit attached files';
$string['restrictcontentno'] = 'Submit everything';
$string['restrictcontenttext'] = 'Only submit in-line text';
$string['resubmit'] = 'Resubmit';
$string['resubmit_all_tool'] = 'Generate originality reports';
$string['resubmit_all_tool_desc'] = 'Resubmit student submissions that encountered an error, have been stuck in the queue for over 10 minutes, or haven’t been sent yet.';
$string['resubmit_confirm'] = 'Are you sure you want to request originality reports for all eligible submissions in this assignment? This will run in the background.';
$string['resubmit_pending'] = 'Request is currently processing...';
$string['resubmit_scheduled'] = 'A background task has been scheduled to check and request missing originality reports. This may take a few minutes.';
$string['resubmit_tooltip'] = 'Retry report submission using the current originality checking settings.';
$string['resubmitallfiltered'] = 'Resubmit all filtered files';
$string['resubmitselectedfiles'] = 'Resubmit Selected Files';
$string['savedconfigsuccess'] = 'Originality Settings Saved';
$string['sendfiles'] = 'Inspera Originality send queued files';
$string['showstudentreport_after_grading'] = 'After grading';
$string['showstudentreport_due_date'] = 'Due date';
$string['showstudentreport_immediately'] = 'Immediately after it is available';
$string['showstudentreport_not_shared'] = 'Never shared';
$string['similarity'] = 'Similarity';
$string['similarity_score'] = 'Similarity';
$string['status'] = 'Originality status';
$string['status_error'] = 'Error';
$string['status_external_error'] = 'External Error';
$string['status_finished'] = 'Finished';
$string['status_pending'] = 'Pending';
$string['status_report_requested'] = 'Queued';
$string['statuserror'] = 'Inspera Originality Report: An error occurred.';
$string['statuspending'] = 'Inspera Originality Report: Pending...';
$string['statusrequested'] = 'Inspera Originality Report: Queued';
$string['submitondraft'] = 'Submit file when first uploaded';
$string['submitonfinal'] = 'Submit file when student sends for marking';
$string['tab_defaults'] = 'Default Settings';
$string['tab_management'] = 'Submissions management';
$string['tab_settings'] = 'Connection Settings';
$string['timecreated'] = 'Time created';
$string['translation_similarity'] = 'Translation similarity';
$string['use_originality'] = 'Enable Originality check';
$string['use_originality_admin'] = 'Enable Originality check';
$string['use_originality_admin_help'] = 'If the originality check should be enabled by default.';
$string['use_originality_group_incompatible'] = 'Originality check is not compatible with group submissions. Disable group submissions to enable originality checks.';
$string['use_originality_teachers'] = 'Enable Originality check';
$string['use_originality_teachers_help'] = 'If enabled, an originality report will be generated.';
$string['viewreport'] = 'View originality report';
$string['warning_group_onlinetext'] = '<strong>Plagiarism Configuration Warning:</strong> "Students submit in groups" is enabled with "Online text". Inspera Originality cannot check Online Text for groups. Only File submissions will be checked.';
