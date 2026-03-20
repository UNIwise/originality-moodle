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

// Pagination and search parameters.
$perpage = 20;
$spage = optional_param('spage', 0, PARAM_INT);   // Submissions page.
$dpage = optional_param('dpage', 0, PARAM_INT);   // Deletions page.
$ssearch = optional_param('ssearch', '', PARAM_RAW); // Submissions search.
$dsearch = optional_param('dsearch', '', PARAM_RAW); // Deletions search.

// Build WHERE clause for search.
$buildwhere = function(int $status, string $search) use ($DB) {
    $params = ['status' => $status];
    $where = 'status = :status';
    $search = trim($search);
    if ($search !== '') {
        // Search by record ID, user ID, cm ID (exact numeric) or filename (partial text).
        if (ctype_digit($search)) {
            $where .= ' AND (id = :searchid OR userid = :searchuid OR cm = :searchcm OR '
                . $DB->sql_like('filename', ':searchname', false) . ')';
            $params['searchid'] = (int) $search;
            $params['searchuid'] = (int) $search;
            $params['searchcm'] = (int) $search;
            $params['searchname'] = '%' . $DB->sql_like_escape($search) . '%';
        } else {
            $where .= ' AND ' . $DB->sql_like('filename', ':searchname', false);
            $params['searchname'] = '%' . $DB->sql_like_escape($search) . '%';
        }
    }
    return [$where, $params];
};

// --- Failed Submissions ---
echo $OUTPUT->heading(get_string('failedsubmissions', 'plagiarism_originality'), 3);

// Search form.
$searchurl = new moodle_url($pageurl, ['dpage' => $dpage, 'dsearch' => $dsearch]);
echo '<form method="get" action="' . $searchurl->out_omit_querystring() . '" class="mb-2">';
foreach ($searchurl->params() as $k => $v) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
}
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'ssearch', 'value' => $ssearch,
    'placeholder' => get_string('searchbyidorfile', 'plagiarism_originality'),
    'class' => 'form-control d-inline-block', 'style' => 'width:300px;',
]);
echo ' ' . html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-secondary',
]);
if ($ssearch !== '') {
    $clearurl = new moodle_url($pageurl, ['dpage' => $dpage, 'dsearch' => $dsearch]);
    echo ' ' . html_writer::link($clearurl, get_string('clear', 'plagiarism_originality'),
        ['class' => 'btn btn-link']);
}
echo '</form>';

list($swhere, $sparams) = $buildwhere(3, $ssearch);
$submitcount = $DB->count_records_select('plagiarism_originality_files', $swhere, $sparams);
$failedsubmits = $DB->get_records_select('plagiarism_originality_files', $swhere, $sparams,
    'timemodified DESC', '*', $spage * $perpage, $perpage);

if ($submitcount == 0) {
    echo html_writer::tag('p', get_string('nofailedsubmissions', 'plagiarism_originality'),
        ['class' => 'text-muted']);
} else {
    // Batch-fetch all users and course modules for this page.
    $suserids = array_unique(array_column($failedsubmits, 'userid'));
    $scmids = array_unique(array_column($failedsubmits, 'cm'));

    $susers = [];
    if (!empty($suserids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($suserids, SQL_PARAMS_NAMED);
        $susers = $DB->get_records_select('user', "id $insql", $inparams, '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email');
    }

    // Pre-resolve activity names, grouped by course to minimise get_fast_modinfo calls.
    $sactivitynames = [];
    $scms = [];
    if (!empty($scmids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($scmids, SQL_PARAMS_NAMED);
        $scms = $DB->get_records_select('course_modules', "id $insql", $inparams);
    }
    $coursegroups = [];
    foreach ($scms as $cm) {
        $coursegroups[$cm->course][] = $cm->id;
    }
    foreach ($coursegroups as $courseid => $cmids) {
        try {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($cmids as $cid) {
                try {
                    $cminfo = $modinfo->get_cm($cid);
                    $sactivitynames[$cid] = $cminfo->get_formatted_name();
                } catch (\Exception $e) {
                    $sactivitynames[$cid] = '#' . $cid;
                }
            }
        } catch (\Exception $e) {
            foreach ($cmids as $cid) {
                $sactivitynames[$cid] = '#' . $cid;
            }
        }
    }

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
        $user = $susers[$record->userid] ?? null;
        $username = $user ? fullname($user) : get_string('unknownuser', 'plagiarism_originality');
        $activityname = $sactivitynames[$record->cm] ?? '';

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

    // Pagination.
    $spagingurl = new moodle_url($pageurl, ['ssearch' => $ssearch, 'dpage' => $dpage, 'dsearch' => $dsearch]);
    echo $OUTPUT->paging_bar($submitcount, $spage, $perpage, $spagingurl, 'spage');
}

// --- Failed Deletions ---
echo $OUTPUT->heading(get_string('faileddeletions', 'plagiarism_originality'), 3);

// Search form.
$searchurl = new moodle_url($pageurl, ['spage' => $spage, 'ssearch' => $ssearch]);
echo '<form method="get" action="' . $searchurl->out_omit_querystring() . '" class="mb-2">';
foreach ($searchurl->params() as $k => $v) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
}
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'dsearch', 'value' => $dsearch,
    'placeholder' => get_string('searchbyidorfile', 'plagiarism_originality'),
    'class' => 'form-control d-inline-block', 'style' => 'width:300px;',
]);
echo ' ' . html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-secondary',
]);
if ($dsearch !== '') {
    $clearurl = new moodle_url($pageurl, ['spage' => $spage, 'ssearch' => $ssearch]);
    echo ' ' . html_writer::link($clearurl, get_string('clear', 'plagiarism_originality'),
        ['class' => 'btn btn-link']);
}
echo '</form>';

list($dwhere, $dparams) = $buildwhere(4, $dsearch);
$deletecount = $DB->count_records_select('plagiarism_originality_files', $dwhere, $dparams);
$faileddeletes = $DB->get_records_select('plagiarism_originality_files', $dwhere, $dparams,
    'timemodified DESC', '*', $dpage * $perpage, $perpage);

if ($deletecount == 0) {
    echo html_writer::tag('p', get_string('nofaileddeletions', 'plagiarism_originality'),
        ['class' => 'text-muted']);
} else {
    // Batch-fetch all users and course modules for this page.
    $duserids = array_unique(array_column($faileddeletes, 'userid'));
    $dcmids = array_unique(array_column($faileddeletes, 'cm'));

    $dusers = [];
    if (!empty($duserids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($duserids, SQL_PARAMS_NAMED);
        $dusers = $DB->get_records_select('user', "id $insql", $inparams, '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email');
    }

    $dactivitynames = [];
    $dcms = [];
    if (!empty($dcmids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($dcmids, SQL_PARAMS_NAMED);
        $dcms = $DB->get_records_select('course_modules', "id $insql", $inparams);
    }
    $coursegroups = [];
    foreach ($dcms as $cm) {
        $coursegroups[$cm->course][] = $cm->id;
    }
    foreach ($coursegroups as $courseid => $cmids) {
        try {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($cmids as $cid) {
                try {
                    $cminfo = $modinfo->get_cm($cid);
                    $dactivitynames[$cid] = $cminfo->get_formatted_name();
                } catch (\Exception $e) {
                    $dactivitynames[$cid] = '#' . $cid;
                }
            }
        } catch (\Exception $e) {
            foreach ($cmids as $cid) {
                $dactivitynames[$cid] = '#' . $cid;
            }
        }
    }

    // Retry all button.
    $retryallurl = new moodle_url($pageurl, ['retryall' => 'delete', 'sesskey' => sesskey()]);
    echo html_writer::link($retryallurl, get_string('retryall', 'plagiarism_originality'),
        ['class' => 'btn btn-secondary mb-2']);

    $table = new html_table();
    $table->head = [
        get_string('failedcol_id', 'plagiarism_originality'),
        get_string('failedcol_filename', 'plagiarism_originality'),
        get_string('failedcol_user', 'plagiarism_originality'),
        get_string('failedcol_activity', 'plagiarism_originality'),
        get_string('failedcol_externalid', 'plagiarism_originality'),
        get_string('failedcol_attempts', 'plagiarism_originality'),
        get_string('failedcol_error', 'plagiarism_originality'),
        get_string('failedcol_time', 'plagiarism_originality'),
        get_string('failedcol_action', 'plagiarism_originality'),
    ];
    $table->attributes['class'] = 'generaltable';
    $table->data = [];

    foreach ($faileddeletes as $record) {
        $user = $dusers[$record->userid] ?? null;
        $username = $user ? fullname($user) : get_string('unknownuser', 'plagiarism_originality');
        $activityname = $dactivitynames[$record->cm] ?? '';

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
            $username,
            $activityname,
            s($record->externalid ?? ''),
            $record->attempts,
            html_writer::tag('span', s(shorten_text($record->errorresponse ?? '', 200)),
                ['class' => 'text-danger small']),
            userdate($record->timemodified),
            $retrybtn,
        ];
    }

    echo html_writer::table($table);

    // Pagination.
    $dpagingurl = new moodle_url($pageurl, ['dsearch' => $dsearch, 'spage' => $spage, 'ssearch' => $ssearch]);
    echo $OUTPUT->paging_bar($deletecount, $dpage, $perpage, $dpagingurl, 'dpage');
}

echo $OUTPUT->footer();
