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
 * lib.php - Contains Plagiarism plugin specific functions called by Modules.
 *
 * @since 2.0
 * @package    plagiarism_originality
 * @subpackage plagiarism
 * @copyright  2010 Dan Marsden http://danmarsden.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

//get global class
global $CFG;
require_once($CFG->dirroot.'/plagiarism/lib.php');

///// Turnitin Class ////////////////////////////////////////////////////
class plagiarism_plugin_originality extends plagiarism_plugin {
     /**
     * hook to allow plagiarism specific information to be displayed beside a submission 
     * @param array  $linkarraycontains all relevant information for the plugin to generate a link
     * @return string
     * 
     */
    public function get_links($linkarray) {
        global $DB;

        $cmid = $linkarray['cmid'];
        $userid = $linkarray['userid'];
        $output = '';

        // Check plugin is enabled for this activity.
        $modsettings = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid]);
        if (empty($modsettings) || empty($modsettings->enabled)) {
            return $output;
        }

        // Determine the identifier to look up.
        $identifier = '';
        if (!empty($linkarray['file'])) {
            $identifier = $linkarray['file']->get_contenthash();
        } else if (!empty($linkarray['content'])) {
            $identifier = sha1($linkarray['content']);
        }

        if (empty($identifier)) {
            return $output;
        }

        $filerecord = $DB->get_record('plagiarism_originality_files', [
            'cm' => $cmid,
            'userid' => $userid,
            'identifier' => $identifier,
        ]);

        if (!$filerecord) {
            return $output;
        }

        switch ((int) $filerecord->status) {
            case 0: // Pending.
                $output .= html_writer::tag('span',
                    get_string('status_pending', 'plagiarism_originality'),
                    ['class' => 'plagiarism-originality-pending']);
                break;
            case 1: // Submitted.
                $output .= html_writer::tag('span',
                    get_string('status_submitted', 'plagiarism_originality'),
                    ['class' => 'plagiarism-originality-submitted']);
                break;
            case 2: // Complete.
                $score = $filerecord->score ?? 0;
                $output .= html_writer::tag('span',
                    get_string('similarity', 'plagiarism_originality', $score),
                    ['class' => 'plagiarism-originality-score']);
                if (!empty($filerecord->reporturl)) {
                    $output .= ' ' . html_writer::link(
                        $filerecord->reporturl,
                        get_string('viewreport', 'plagiarism_originality'),
                        ['target' => '_blank', 'class' => 'plagiarism-originality-report']
                    );
                }
                break;
            case 3: // Error.
                $output .= html_writer::tag('span',
                    get_string('status_error', 'plagiarism_originality'),
                    ['class' => 'plagiarism-originality-error']);
                break;
        }

        return $output;
    }

    /* hook to save plagiarism specific settings on a module settings page
     * @param object $data - data from an mform submission.
    */
    public function save_form_elements($data) {
        global $DB;
        if (!isset($data->coursemodule)) {
            return;
        }
        $cmid = $data->coursemodule;
        $enabled = isset($data->originality_enabled) ? (int) $data->originality_enabled : 0;

        if ($record = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid])) {
            $record->enabled = $enabled;
            $DB->update_record('plagiarism_originality_settings', $record);
        } else {
            $record = new stdClass();
            $record->cm = $cmid;
            $record->enabled = $enabled;
            $DB->insert_record('plagiarism_originality_settings', $record);
        }
    }

    /**
     * hook to add plagiarism specific settings to a module settings page
     * @param object $mform  - Moodle form
     * @param object $context - current context
     */
    public function get_form_elements_module($mform, $context, $modulename = '') {
        global $DB;

        $plagiarismsettings = (array) get_config('plagiarism_originality');
        if (empty($plagiarismsettings['originality_use'])) {
            return;
        }

        $cmid = optional_param('update', 0, PARAM_INT);

        $mform->addElement('header', 'originalitydesc', get_string('pluginname', 'plagiarism_originality'));
        $mform->addElement('checkbox', 'originality_enabled', get_string('originality_enable', 'plagiarism_originality'));

        if ($cmid && $record = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid])) {
            $mform->setDefault('originality_enabled', $record->enabled);
        }
    }

    /**
     * hook to allow a disclosure to be printed notifying users what will happen with their submission
     * @param int $cmid - course module id
     * @return string
     */
    public function print_disclosure($cmid) {
        global $DB, $OUTPUT;

        $plagiarismsettings = (array) get_config('plagiarism_originality');
        if (empty($plagiarismsettings['originality_use'])) {
            return '';
        }

        // Check if originality is enabled for this specific activity.
        $modsettings = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid]);
        if (empty($modsettings) || empty($modsettings->enabled)) {
            return '';
        }

        $output = '';
        if (!empty($plagiarismsettings['originality_student_disclosure'])) {
            $output .= $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
            $formatoptions = new stdClass();
            $formatoptions->noclean = true;
            $output .= format_text($plagiarismsettings['originality_student_disclosure'], FORMAT_MOODLE, $formatoptions);
            $output .= $OUTPUT->box_end();
        }
        return $output;
    }

    /**
     * hook to allow status of submitted files to be updated - called on grading/report pages.
     *
     * @param object $course - full Course object
     * @param object $cm - full cm object
     */
    public function update_status($course, $cm) {
        //called at top of submissions/grading pages - allows printing of admin style links or updating status
    }

    /**
     * called by admin/cron.php 
     *
     */
    public function cron() {
        //do any scheduled task stuff
    }
}

function originality_event_file_uploaded($eventdata) {
    $result = true;
    return $result;
}
function originality_event_files_done($eventdata) {
    $result = true;
    return $result;
}

function originality_event_mod_created($eventdata) {
    $result = true;
    return $result;
}

function originality_event_mod_updated($eventdata) {
    $result = true;
    return $result;
}

function originality_event_mod_deleted($eventdata) {
    $result = true;
    return $result;
}

/**
 * Submit a stored file to the external plagiarism service and record it in the database.
 *
 * @param \stored_file $file The file to submit.
 * @param int $cmid The course module ID.
 * @param int $userid The user who submitted the file.
 */
function plagiarism_originality_submit_file(\stored_file $file, int $cmid, int $userid): void {
    global $DB;

    $identifier = $file->get_contenthash();
    $now = time();

    // Avoid duplicate submissions.
    $existing = $DB->get_record('plagiarism_originality_files', [
        'cm' => $cmid,
        'userid' => $userid,
        'identifier' => $identifier,
    ]);
    if ($existing && (int) $existing->status !== 3) {
        // Already submitted or processing — skip unless it was an error.
        return;
    }

    // Create or reuse the database record.
    $record = $existing ?: new \stdClass();
    $record->cm = $cmid;
    $record->userid = $userid;
    $record->identifier = $identifier;
    $record->filename = $file->get_filename();
    $record->submissiontype = 'file';
    $record->status = 0; // Pending.
    $record->timemodified = $now;

    if (empty($existing)) {
        $record->timecreated = $now;
        $record->attempts = 0;
        $record->id = $DB->insert_record('plagiarism_originality_files', $record);
    } else {
        $DB->update_record('plagiarism_originality_files', $record);
    }

    // Attempt to send to the external service.
    try {
        $client = \plagiarism_originality\api_client::create();
        $response = $client->submit_file($file, $cmid, $userid);

        $record->externalid = (string) ($response['documentId'] ?? '');
        $record->status = 1; // Submitted.
        $record->attempts = $record->attempts + 1;
        $record->timemodified = time();
        $DB->update_record('plagiarism_originality_files', $record);
    } catch (\Exception $e) {
        $record->status = 3; // Error.
        $record->errorresponse = $e->getMessage();
        $record->attempts = $record->attempts + 1;
        $record->timemodified = time();
        $DB->update_record('plagiarism_originality_files', $record);
        debugging('Originality file submit failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Submit online text content to the external plagiarism service.
 *
 * @param string $content The text content to submit.
 * @param int $cmid The course module ID.
 * @param int $userid The user who submitted the content.
 */
function plagiarism_originality_submit_text(string $content, int $cmid, int $userid): void {
    global $DB;

    $identifier = sha1($content);
    $now = time();

    $existing = $DB->get_record('plagiarism_originality_files', [
        'cm' => $cmid,
        'userid' => $userid,
        'identifier' => $identifier,
    ]);
    if ($existing && (int) $existing->status !== 3) {
        return;
    }

    $record = $existing ?: new \stdClass();
    $record->cm = $cmid;
    $record->userid = $userid;
    $record->identifier = $identifier;
    $record->filename = 'onlinetext';
    $record->submissiontype = 'onlinetext';
    $record->status = 0;
    $record->timemodified = $now;

    if (empty($existing)) {
        $record->timecreated = $now;
        $record->attempts = 0;
        $record->id = $DB->insert_record('plagiarism_originality_files', $record);
    } else {
        $DB->update_record('plagiarism_originality_files', $record);
    }

    try {
        $client = \plagiarism_originality\api_client::create();
        $response = $client->submit_text($content, $cmid, $userid);

        $record->externalid = (string) ($response['documentId'] ?? '');
        $record->status = 1;
        $record->attempts = $record->attempts + 1;
        $record->timemodified = time();
        $DB->update_record('plagiarism_originality_files', $record);
    } catch (\Exception $e) {
        $record->status = 3;
        $record->errorresponse = $e->getMessage();
        $record->attempts = $record->attempts + 1;
        $record->timemodified = time();
        $DB->update_record('plagiarism_originality_files', $record);
        debugging('Originality text submit failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Callback to add plagiarism form elements to module settings forms.
 * Used by Moodle 4.2+ plugin callback system.
 *
 * @param moodleform_mod $formwrapper The form wrapper.
 * @param MoodleQuickForm $mform The form.
 */
function plagiarism_originality_coursemodule_standard_elements($formwrapper, $mform) {
    $plugin = new plagiarism_plugin_originality();
    $context = $formwrapper->get_context();
    $plugin->get_form_elements_module($mform, $context, $formwrapper->get_current()->modulename ?? '');
}

/**
 * Callback to save plagiarism form data after module edit.
 * Used by Moodle 4.2+ plugin callback system.
 *
 * @param stdClass $data The form data.
 * @param stdClass $course The course object.
 * @return stdClass The (possibly modified) data.
 */
function plagiarism_originality_coursemodule_edit_post_actions($data, $course) {
    $plugin = new plagiarism_plugin_originality();
    $plugin->save_form_elements($data);
    return $data;
}
