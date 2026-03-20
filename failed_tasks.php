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
 * Failed tasks management page for plagiarism_originality.
 *
 * Lists submissions and deletions that failed after all retries, with
 * buttons to re-queue them for another round of attempts.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/plagiarism/originality/lib.php');

require_login();
admin_externalpage_setup('plagiarismoriginality');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$pageurl = new moodle_url('/plagiarism/originality/failed_tasks.php');
$PAGE->set_url($pageurl);

// Handle retry actions.
$retryid = optional_param('retryid', 0, PARAM_INT);
$retryaction = optional_param('retryaction', '', PARAM_ALPHA); // 'submit' or 'delete'.
$retryall = optional_param('retryall', '', PARAM_ALPHA); // 'submit' or 'delete'.

if ($retryid && $retryaction && confirm_sesskey()) {
    $record = $DB->get_record('plagiarism_originality_files', ['id' => $retryid]);
    if ($record) {
        if ($retryaction === 'submit' && (int) $record->status === 3) {
            // Reset status and re-queue submit task.
            $record->status = 0;
            $record->errorresponse = null;
            $record->attempts = 0;
            $record->timemodified = time();
            $DB->update_record('plagiarism_originality_files', $record);

            $task = new \plagiarism_originality\task\submit_to_originality();
            $task->set_custom_data([
                'record_id' => $record->id,
                'attempt' => 1,
            ]);
            \core\task\manager::queue_adhoc_task($task);
        } else if ($retryaction === 'delete' && (int) $record->status === 4) {
            // Re-queue delete task.
            $task = new \plagiarism_originality\task\delete_from_originality();
            $task->set_custom_data([
                'external_id' => $record->externalid ?? '',
                'record_id' => $record->id,
                'attempt' => 1,
            ]);
            \core\task\manager::queue_adhoc_task($task);

            // Reset status back to submitted while deletion is retried.
            $record->status = 1;
            $record->errorresponse = null;
            $record->timemodified = time();
            $DB->update_record('plagiarism_originality_files', $record);
        }
    }
    redirect($pageurl, get_string('retryqueued', 'plagiarism_originality'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($retryall && confirm_sesskey()) {
    if ($retryall === 'submit') {
        $records = $DB->get_records('plagiarism_originality_files', ['status' => 3]);
        foreach ($records as $record) {
            $record->status = 0;
            $record->errorresponse = null;
            $record->attempts = 0;
            $record->timemodified = time();
            $DB->update_record('plagiarism_originality_files', $record);

            $task = new \plagiarism_originality\task\submit_to_originality();
            $task->set_custom_data([
                'record_id' => $record->id,
                'attempt' => 1,
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
    } else if ($retryall === 'delete') {
        $records = $DB->get_records('plagiarism_originality_files', ['status' => 4]);
        foreach ($records as $record) {
            $task = new \plagiarism_originality\task\delete_from_originality();
            $task->set_custom_data([
                'external_id' => $record->externalid ?? '',
                'record_id' => $record->id,
                'attempt' => 1,
            ]);
            \core\task\manager::queue_adhoc_task($task);

            $record->status = 1;
            $record->errorresponse = null;
            $record->timemodified = time();
            $DB->update_record('plagiarism_originality_files', $record);
        }
    }
    redirect($pageurl, get_string('retryallqueued', 'plagiarism_originality'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Render the page.
echo $OUTPUT->header();

// Tab navigation.
$settingsurl = new moodle_url('/plagiarism/originality/settings.php');
$tabs = [
    new tabobject('settings', $settingsurl, get_string('pluginsettings', 'plagiarism_originality')),
    new tabobject('failedtasks', $pageurl, get_string('failedtasks', 'plagiarism_originality')),
];
echo $OUTPUT->tabtree($tabs, 'failedtasks');

// Failed submissions (status = 3).
$failedsubmits = $DB->get_records('plagiarism_originality_files', ['status' => 3], 'timemodified DESC');
// Failed deletions (status = 4).
$faileddeletes = $DB->get_records('plagiarism_originality_files', ['status' => 4], 'timemodified DESC');

// --- Failed Submissions ---
echo $OUTPUT->heading(get_string('failedsubmissions', 'plagiarism_originality'), 3);

if (empty($failedsubmits)) {
    echo html_writer::tag('p', get_string('nofailedsubmissions', 'plagiarism_originality'),
        ['class' => 'text-muted']);
} else {
    // Retry all button.
    $retryallurl = new moodle_url($pageurl, ['retryall' => 'submit', 'sesskey' => sesskey()]);
    echo html_writer::link($retryallurl, get_string('retryall', 'plagiarism_originality'),
        ['class' => 'btn btn-secondary mb-2']);

    $table = new html_table();
    $table->head = [
        get_string('failedcol_id', 'plagiarism_originality'),
        get_string('failedcol_filename', 'plagiarism_originality'),
        get_string('failedcol_user', 'plagiarism_originality'),
        get_string('failedcol_activity', 'plagiarism_originality'),
        get_string('failedcol_attempts', 'plagiarism_originality'),
        get_string('failedcol_error', 'plagiarism_originality'),
        get_string('failedcol_time', 'plagiarism_originality'),
        get_string('failedcol_action', 'plagiarism_originality'),
    ];
    $table->attributes['class'] = 'generaltable';
    $table->data = [];

    foreach ($failedsubmits as $record) {
        $user = $DB->get_record('user', ['id' => $record->userid], 'id, firstname, lastname, email');
        $username = $user ? fullname($user) : get_string('unknownuser', 'plagiarism_originality');

        $cm = $DB->get_record('course_modules', ['id' => $record->cm]);
        $activityname = '';
        if ($cm) {
            $modinfo = get_fast_modinfo($cm->course);
            $cminfo = $modinfo->get_cm($cm->id);
            $activityname = $cminfo->get_formatted_name();
        }

        $retryurl = new moodle_url($pageurl, [
            'retryid' => $record->id,
            'retryaction' => 'submit',
            'sesskey' => sesskey(),
        ]);
        $retrybtn = html_writer::link($retryurl, get_string('retry', 'plagiarism_originality'),
            ['class' => 'btn btn-sm btn-primary']);

        $table->data[] = [
            $record->id,
            s($record->filename ?? ''),
            $username,
            $activityname,
            $record->attempts,
            html_writer::tag('span', s(shorten_text($record->errorresponse ?? '', 200)),
                ['class' => 'text-danger small']),
            userdate($record->timemodified),
            $retrybtn,
        ];
    }

    echo html_writer::table($table);
}

// --- Failed Deletions ---
echo $OUTPUT->heading(get_string('faileddeletions', 'plagiarism_originality'), 3);

if (empty($faileddeletes)) {
    echo html_writer::tag('p', get_string('nofaileddeletions', 'plagiarism_originality'),
        ['class' => 'text-muted']);
} else {
    // Retry all button.
    $retryallurl = new moodle_url($pageurl, ['retryall' => 'delete', 'sesskey' => sesskey()]);
    echo html_writer::link($retryallurl, get_string('retryall', 'plagiarism_originality'),
        ['class' => 'btn btn-secondary mb-2']);

    $table = new html_table();
    $table->head = [
        get_string('failedcol_id', 'plagiarism_originality'),
        get_string('failedcol_filename', 'plagiarism_originality'),
        get_string('failedcol_externalid', 'plagiarism_originality'),
        get_string('failedcol_attempts', 'plagiarism_originality'),
        get_string('failedcol_error', 'plagiarism_originality'),
        get_string('failedcol_time', 'plagiarism_originality'),
        get_string('failedcol_action', 'plagiarism_originality'),
    ];
    $table->attributes['class'] = 'generaltable';
    $table->data = [];

    foreach ($faileddeletes as $record) {
        $retryurl = new moodle_url($pageurl, [
            'retryid' => $record->id,
            'retryaction' => 'delete',
            'sesskey' => sesskey(),
        ]);
        $retrybtn = html_writer::link($retryurl, get_string('retry', 'plagiarism_originality'),
            ['class' => 'btn btn-sm btn-primary']);

        $table->data[] = [
            $record->id,
            s($record->filename ?? ''),
            s($record->externalid ?? ''),
            $record->attempts,
            html_writer::tag('span', s(shorten_text($record->errorresponse ?? '', 200)),
                ['class' => 'text-danger small']),
            userdate($record->timemodified),
            $retrybtn,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
