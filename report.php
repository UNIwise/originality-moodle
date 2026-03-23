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
 * Redirect to the external plagiarism report by dynamically fetching
 * the viewer link from the Originality API.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$id = required_param('id', PARAM_INT);

require_login();

global $DB, $PAGE, $OUTPUT;

// Load the file record.
$filerecord = $DB->get_record('plagiarism_originality_files', ['id' => $id], '*', MUST_EXIST);

// Check the user has access to the course module.
$cm = get_coursemodule_from_id('', $filerecord->cm, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($cm->course, false, $cm);

// Check report viewing permissions.
require_once($CFG->dirroot . '/plagiarism/originality/lib.php');
if (!plagiarism_plugin_originality::can_user_view_report($cm->id, (int) $filerecord->userid)) {
    require_capability('plagiarism/originality:viewreport', $context);
}

// We need an external ID to fetch the report.
if (empty($filerecord->externalid)) {
    $PAGE->set_url(new moodle_url('/plagiarism/originality/report.php', ['id' => $id]));
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('viewreport', 'plagiarism_originality'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('report_not_available', 'plagiarism_originality'), 'warning');
    echo $OUTPUT->footer();
    die;
}

// Fetch the document from the API to get the current viewer link.
try {
    $client = \plagiarism_originality\api_client::create();
    $document = $client->get_submission_status($filerecord->externalid);
} catch (\Exception $e) {
    $PAGE->set_url(new moodle_url('/plagiarism/originality/report.php', ['id' => $id]));
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('viewreport', 'plagiarism_originality'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('report_fetch_error', 'plagiarism_originality', $e->getMessage()), 'error');
    echo $OUTPUT->footer();
    die;
}

// Extract the viewer link from the response.
$viewerlink = $document['result']['viewerLink'] ?? $document['viewerLink'] ?? $document['reportUrl'] ?? '';

if (empty($viewerlink)) {
    $PAGE->set_url(new moodle_url('/plagiarism/originality/report.php', ['id' => $id]));
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('viewreport', 'plagiarism_originality'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('report_not_available', 'plagiarism_originality'), 'warning');
    echo $OUTPUT->footer();
    die;
}

// Update the stored report URL and score if available.
$update = new stdClass();
$update->id = $filerecord->id;
$update->reporturl = $viewerlink;
if (isset($document['result']['score'])) {
    $update->score = (int) round($document['result']['score'] * 100);
}
$update->timemodified = time();
$DB->update_record('plagiarism_originality_files', $update);

// Redirect to the external report viewer.
redirect($viewerlink);
