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
 * Language strings for the Wiseflow Originality plagiarism plugin.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Wiseflow Originality plagiarism plugin';
$string['studentdisclosuredefault']  ='All files uploaded will be submitted to a plagiarism detection service';
$string['studentdisclosure'] = 'Student Disclosure';
$string['studentdisclosure_help'] = 'This text will be displayed to all students on the file upload page.';
$string['originalityexplain'] = 'For more information on this plugin see: ';
$string['originality'] = 'originality template plagiarism plugin';
$string['useoriginality'] ='Enable originality';
$string['savedconfigsuccess'] = 'Plagiarism Settings Saved';
$string['originality_enable'] = 'Enable Originality for this activity';
$string['apisettings'] = 'API connection settings';
$string['apiurl'] = 'API endpoint URL';
$string['apiurl_help'] = 'The base URL of the plagiarism service API (e.g. http://localhost:8888). Token and document endpoints are derived automatically.';
$string['clientid'] = 'Client ID';
$string['clientid_help'] = 'The OAuth2 client ID provided by the plagiarism service.';
$string['clientsecret'] = 'Client secret';
$string['clientsecret_help'] = 'The OAuth2 client secret provided by the plagiarism service.';
$string['status_pending'] = 'Pending';
$string['status_submitted'] = 'Submitted';
$string['status_complete'] = 'Complete';
$string['status_error'] = 'Error';
$string['status'] = 'Status: {$a}';
$string['similarity'] = 'Similarity: {$a}%';
$string['viewreport'] = 'View full report';
$string['report_not_available'] = 'The plagiarism report is not yet available. Please try again later.';
$string['report_fetch_error'] = 'Could not fetch the plagiarism report: {$a}';
$string['originality:viewreport'] = 'View plagiarism reports for other users';
$string['submittask'] = 'Submit files to plagiarism service';
$string['apierror'] = 'Originality API error: {$a}';
$string['missingconfig'] = 'Originality plugin is not fully configured. Please set API URL, client ID and secret.';
$string['reportsettings'] = 'Report visibility';
$string['allow_student_report'] = 'Allow students to view reports';
$string['allow_student_report_help'] = 'When enabled, teachers can allow students to view their own plagiarism reports on individual activities. This is the global switch — it must be enabled here before it can be turned on per activity.';
$string['allow_student_report_activity'] = 'Allow students to view their reports';
$string['allow_student_report_activity_help'] = 'When enabled, students who submitted work in this activity can view their own plagiarism report.';
$string['timingsettings'] = 'Submission timing';
$string['submit_on'] = 'Submit to Originality';
$string['submit_on_help'] = 'Choose when files are sent to the plagiarism service. "On upload" sends immediately when the student submits. "On marking" waits until a teacher grades the submission.';
$string['submit_on_upload'] = 'On submission upload';
$string['submit_on_marking'] = 'On student marking';
$string['activitiessettings'] = 'Supported activities';
$string['enable_mod_assign'] = 'Enable for Assignments';
$string['enable_mod_forum'] = 'Enable for Forums';
$string['enable_mod_workshop'] = 'Enable for Workshops';
$string['enable_mod_quiz'] = 'Enable for Quizzes (essay questions)';
$string['pluginsettings'] = 'Settings';
$string['failedtasks'] = 'Failed tasks';
$string['failedsubmissions'] = 'Failed submissions';
$string['faileddeletions'] = 'Failed deletions';
$string['nofailedsubmissions'] = 'No failed submissions.';
$string['nofaileddeletions'] = 'No failed deletions.';
$string['retry'] = 'Retry';
$string['retryall'] = 'Retry all';
$string['retryqueued'] = 'Task has been re-queued for retry.';
$string['retryallqueued'] = 'All failed tasks have been re-queued for retry.';
$string['failedcol_id'] = 'ID';
$string['failedcol_filename'] = 'File';
$string['failedcol_user'] = 'User';
$string['failedcol_activity'] = 'Activity';
$string['failedcol_externalid'] = 'External ID';
$string['failedcol_attempts'] = 'Attempts';
$string['failedcol_error'] = 'Error';
$string['failedcol_time'] = 'Last attempt';
$string['failedcol_action'] = 'Action';
$string['unknownuser'] = 'Unknown user';
$string['status_delete_failed'] = 'Delete failed';
$string['searchbyidorfile'] = 'Search by ID, user ID, CM ID, or filename...';
$string['clear'] = 'Clear';
