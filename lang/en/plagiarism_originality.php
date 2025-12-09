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

$string['pluginname'] = 'Originality plagiarism plugin';

// === Plugin settings ===
$string['originality'] = 'Inspera Originality settings';
$string['tab_settings'] = 'Settings';
$string['tab_defaults'] = 'Defaults';
$string['tab_management'] = 'Submissions management';
$string['baseurl'] = 'Base API URL';
$string['baseurl_help'] = 'The root URL of the plagiarism detection API (e.g. https://api.plagiarism.com/v1).';
$string['clientid'] = 'Client ID';
$string['clientid_help'] = 'Your assigned client ID for API authentication.';
$string['institutionid'] = 'Institution ID';
$string['institutionid_help'] = 'Your institution identifier provided by the plagiarism detection service.';
$string['enableplugin'] = 'Enable Inspera Originality for {$a}';

// === Plugin Defaults ===
$string['use_originality'] = 'Enable Originality plagiarism check';
$string['originalitydefaults_assign'] = 'Default assign settings';
$string['originalitydefaults_forum'] = 'Default forum settings';
$string['originalitydefaults_workshop'] = 'Default workshop settings';
$string['originalitydefaults_quiz'] = 'Default quiz settings';
$string['defaultsdesc'] = 'The following settings are the defaults set when enabling Inspera Originality within an Activity Module';
$string['defaultupdated'] = 'Default values updated';
$string['originality_enable_translations'] = 'Translations';
$string['originality_enable_translations_help'] = 'Enable cross-language similarity checks. If enabled, teachers may select target languages.';
$string['originality_metadata_analysis'] = 'Metadata analysis';
$string['originality_metadata_analysis_help'] = 'Enable analysis of document metadata for additional plagiarism insights.';
$string['originality_enable_ai'] = 'AI authorship detection';
$string['originality_enable_ai_help'] = 'If enabled, the system will analyze the text for signs of AI-generated content.';
$string['originality_archive'] = 'Archive documents';
$string['originality_archive_help'] = 'If enabled, all submissions are archived for long-term storage and future verification.';
$string['originality_enable_context_similarity'] = 'Enable contextual similarity';
$string['originality_enable_context_similarity_help'] = 'When enabled, the system compares contextual meaning between documents. You can define a minimum similarity threshold.';
$string['originality_context_threshold'] = 'Context similarity threshold (%)';
$string['originality_context_threshold_help'] = 'The minimum percentage of contextual similarity required to trigger a similarity alert (must be 50% or greater).';
$string['contextthresholdmin'] = 'The threshold must be at least 50%.';
$string['originality_translation_languages'] = 'Select supported translation languages';
$string['originality_translation_languages_help'] = 'Select one or more target languages into which submitted content can be translated for similarity analysis. Enabling translations allows the system to compare content across multiple languages, improving detection of cross-language similarities and translations. For example, if a submission is in Spanish and “English” is selected here, the system will also analyze an English translation of the text for potential matches.';
$string['originality_enable_include_urls'] = 'Include URLs';
$string['originality_include_urls'] = 'URLs to include';
$string['originality_include_urls_help'] = 'If enabled, the specified URLs will be included in the originality check. Enter multiple URLs separated by commas.';
$string['originality_enable_exclude_urls'] = 'Exclude URLs';
$string['originality_exclude_urls'] = 'URLs to exclude';
$string['originality_exclude_urls_help'] = 'If enabled, the specified URLs will be excluded from the originality check. Enter multiple URLs separated by commas.';
$string['errorincludeurls'] = 'Please enter at least one URL to include.';
$string['errorexcludeurls'] = 'Please enter at least one URL to exclude.';
$string['originality_allowallfile'] = 'Allow all supported file types';
$string['originality_allowallfile_help'] = 'Select settings to hide behind the "Show more" link. For non-administrators, these settings will also be locked (read-only) to the default value.';
$string['originality_show_student_report'] = 'Share report with the student';
$string['originality_show_student_report_help'] = 'selecting this means that the teacher will have the option to share the report with the students. If/when that’s shared is defined by the dropdown';
$string['showstudentreport_not_shared'] = 'Not shared';
$string['showstudentreport_immediately'] = 'Immediately after it is available';
$string['showstudentreport_after_grading'] = 'After grading';
$string['showstudentreport_due_date'] = 'Due date';
$string['originality_advanceditems'] = 'Advanced settings';
$string['originality_advanceditems_help'] = 'These settings will be hidden behind the "Show more" link to declutter the form.';
$string['originality_hiddenitems'] = 'Hidden settings';
$string['originality_hiddenitems_help'] = 'These settings will be completely hidden from non-administrators. The default value will be used.';
$string['originality_lockeditems'] = 'Locked settings';
$string['originality_lockeditems_help'] = 'These settings will be visible to non-administrators but read-only (frozen). They will also be automatically moved to the "Show more" section.';
$string['originality_draft_submit'] = 'When should the file be submitted';
$string['submitondraft'] = 'Submit file when first uploaded';
$string['submitonfinal'] = 'Submit file when student sends for marking';
$string['originality_selectfiletypes'] = 'File types to submit';
$string['originality_restrictcontent'] = 'Submit attached files and in-line text';
$string['originality_restrictcontent_help'] = 'Originality can process uploaded files but can also process in-line text from forum posts and text from the online text assignment submission type. You can decide which components to send to Originality.';
$string['restrictcontentfiles'] = 'Only submit attached files';
$string['restrictcontentno'] = 'Submit everything';
$string['restrictcontenttext'] = 'Only submit in-line text';
$string['savedconfigsuccess'] = 'Plagiarism Settings Saved';


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
$string['statusrequested'] = 'Inspera Originality Report: Report Requested';
$string['statuspending'] = 'Inspera Originality Report: Pending...';
$string['statuserror'] = 'Inspera Originality Report: An error occurred.';

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
$string['status_report_requested'] = 'Report requested';
$string['status_finished'] = 'Finished';
$string['status_error'] = 'Error';
$string['status_external_error'] = 'External Error';

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
$string['deleteselectedfiles'] = 'Delete selected files';
$string['deleteallfiltered'] = 'Delete all filtered files';
$string['resubmitallfiltered'] = 'Resubmit all filtered files';
$string['areyousurebulk'] = 'Are you sure you want to delete {$a} selected files?';
$string['areyousurefiltereddelete'] = 'Are you sure you want to delete ALL {$a} files currently listed?';
$string['areyousurefilteredresubmit'] = 'Are you sure you want to resubmit ALL {$a} files currently listed?';
$string['nofilesselected'] = 'No files were selected.';
$string['recordsdeleted'] = 'No files were selected.';
$string['filesresubmitted'] = 'Files queued for resubmission.';
