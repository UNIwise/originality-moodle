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
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/lib.php');

/**
 * Plagiarism plugin class for the Wiseflow Originality service.
 */
class plagiarism_plugin_originality extends plagiarism_plugin {
    /** @var array Static cache of search results keyed by cmid. */
    private static $searchcache = [];
    /**
     * Hook to allow plagiarism specific information to be displayed beside a submission.
     *
     * @param array $linkarray Contains all relevant information for the plugin to generate a link.
     * @return string
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

        // Batch-fetch all document statuses for this course module from the API.
        // Results are cached per cmid for the duration of the request (avoids N API calls on grading pages).
        $apidocs = [];
        if (!empty($filerecord->externalid)) {
            if (!isset(self::$searchcache[$cmid])) {
                try {
                    $client = \plagiarism_originality\api_client::create();
                    $results = $client->search_documents('moodle_cm_id', (string) $cmid);
                    $indexed = [];
                    if (is_array($results)) {
                        $documents = $results['documents'] ?? $results;
                        foreach ($documents as $doc) {
                            $docid = (string) ($doc['documentId'] ?? $doc['id'] ?? '');
                            if (!empty($docid)) {
                                $indexed[$docid] = $doc;
                            }
                        }
                    }
                    self::$searchcache[$cmid] = $indexed;
                } catch (\Exception $e) {
                    self::$searchcache[$cmid] = [];
                }
            }
            $apidocs = self::$searchcache[$cmid];
        }

        // Look up this file's document in the search results.
        $document = null;
        if (!empty($filerecord->externalid) && isset($apidocs[$filerecord->externalid])) {
            $document = $apidocs[$filerecord->externalid];
        }

        if ($document !== null) {
            $apistatus = $document['status'] ?? '';
            $score = null;
            $viewerlink = null;

            if (isset($document['result']['score'])) {
                $score = (int) round($document['result']['score'] * 100);
            }
            if (!empty($document['result']['viewerLink'])) {
                $viewerlink = $document['result']['viewerLink'];
            }

            // Update local record with latest data from API.
            $update = new \stdClass();
            $update->id = $filerecord->id;
            $update->timemodified = time();

            if ($apistatus === 'complete' || $apistatus === 'available') {
                $update->status = 2;
                if ($score !== null) {
                    $update->score = $score;
                }
                if ($viewerlink) {
                    $update->reporturl = $viewerlink;
                }
            } else if ($apistatus === 'failed') {
                $update->status = 3;
                $update->errorresponse = $document['detail'] ?? 'External processing failed';
            } else if ($apistatus === 'in_progress') {
                $update->status = 1;
            }
            $DB->update_record('plagiarism_originality_files', $update);

            // Show report link and details only to users with view permission.
            if (static::can_user_view_report($cmid, $userid)) {
                $reporturl = new moodle_url('/plagiarism/originality/report.php', ['id' => $filerecord->id]);
                $output .= html_writer::link(
                    $reporturl,
                    get_string('viewreport', 'plagiarism_originality'),
                    ['target' => '_blank', 'class' => 'plagiarism-originality-report']
                ) . html_writer::empty_tag('br');

                $output .= html_writer::tag(
                    'span',
                    get_string('status', 'plagiarism_originality', $apistatus),
                    ['class' => 'plagiarism-originality-status']
                ) . html_writer::empty_tag('br');

                if ($score !== null) {
                    $output .= html_writer::tag(
                        'span',
                        get_string('similarity', 'plagiarism_originality', $score),
                        ['class' => 'plagiarism-originality-score']
                    );
                }
            }

            return $output;
        }

        // Fallback: display based on local status when no API data available.
        // Only show status/score to users who can view reports.
        if (static::can_user_view_report($cmid, $userid)) {
            switch ((int) $filerecord->status) {
                case 0: // Pending.
                    $output .= html_writer::tag(
                        'span',
                        get_string('status_pending', 'plagiarism_originality'),
                        ['class' => 'plagiarism-originality-pending']
                    );
                    break;
                case 1: // Submitted.
                    $output .= html_writer::tag(
                        'span',
                        get_string('status_submitted', 'plagiarism_originality'),
                        ['class' => 'plagiarism-originality-submitted']
                    );
                    break;
                case 2: // Complete.
                    $score = $filerecord->score ?? 0;
                    $output .= html_writer::tag(
                        'span',
                        get_string('status_complete', 'plagiarism_originality'),
                        ['class' => 'plagiarism-originality-complete']
                    );
                    $output .= ' ' . html_writer::tag(
                        'span',
                        get_string('similarity', 'plagiarism_originality', $score),
                        ['class' => 'plagiarism-originality-score']
                    );
                    break;
                case 3: // Error.
                    $output .= html_writer::tag(
                        'span',
                        get_string('status_error', 'plagiarism_originality'),
                        ['class' => 'plagiarism-originality-error']
                    );
                    break;
            }
        }

        return $output;
    }

    /**
     * Hook to save plagiarism specific settings on a module settings page.
     *
     * @param object $data Data from an mform submission.
     */
    public function save_form_elements($data) {
        global $DB;
        if (!isset($data->coursemodule)) {
            return;
        }
        $cmid = $data->coursemodule;
        $enabled = isset($data->originality_enabled) ? (int) $data->originality_enabled : 0;
        $studentreport = isset($data->originality_student_report) ? (int) $data->originality_student_report : 0;
        $submiton = isset($data->originality_submit_on) ? (int) $data->originality_submit_on : 0;

        if ($record = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid])) {
            $record->enabled = $enabled;
            $record->student_report = $studentreport;
            $record->submit_on = $submiton;
            $DB->update_record('plagiarism_originality_settings', $record);
        } else {
            $record = new stdClass();
            $record->cm = $cmid;
            $record->enabled = $enabled;
            $record->student_report = $studentreport;
            $record->submit_on = $submiton;
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

        // Check if originiality is enabled for this activity type.
        if (!static::is_module_supported($modulename, $plagiarismsettings)) {
            return;
        }

        $cmid = optional_param('update', 0, PARAM_INT);

        $mform->addElement('header', 'originalitydesc', get_string('pluginname', 'plagiarism_originality'));
        $mform->addElement('checkbox', 'originality_enabled', get_string('originality_enable', 'plagiarism_originality'));

        // Only show student report option if it is enabled globally.
        if (!empty($plagiarismsettings['originality_student_report'])) {
            $mform->addElement(
                'checkbox',
                'originality_student_report',
                get_string('allow_student_report_activity', 'plagiarism_originality')
            );
            $mform->addHelpButton('originality_student_report', 'allow_student_report_activity', 'plagiarism_originality');
            $mform->disabledIf('originality_student_report', 'originality_enabled');
        }

        // Submission timing.
        $submitonoptions = [
            0 => get_string('submit_on_upload', 'plagiarism_originality'),
            1 => get_string('submit_on_marking', 'plagiarism_originality'),
        ];
        $mform->addElement('select', 'originality_submit_on', get_string('submit_on', 'plagiarism_originality'), $submitonoptions);
        $mform->addHelpButton('originality_submit_on', 'submit_on', 'plagiarism_originality');
        $mform->setDefault('originality_submit_on', (int) ($plagiarismsettings['originality_submit_on'] ?? 0));
        $mform->disabledIf('originality_submit_on', 'originality_enabled');

        if ($cmid && $record = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid])) {
            $mform->setDefault('originality_enabled', $record->enabled);
            if (isset($record->student_report)) {
                $mform->setDefault('originality_student_report', $record->student_report);
            }
            if (isset($record->submit_on)) {
                $mform->setDefault('originality_submit_on', $record->submit_on);
            }
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
     * Check whether the current user is allowed to view the report for a given course module and file owner.
     *
     * Teachers/managers always see reports (via viewreport capability).
     * Students see reports only if global + per-activity student_report settings are both enabled.
     *
     * @param int $cmid Course module ID.
     * @param int $fileuserid The user who owns the submission.
     * @return bool
     */
    public static function can_user_view_report(int $cmid, int $fileuserid): bool {
        global $DB, $USER;

        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        // Users with the viewreport capability (teachers/managers) always see reports.
        if (has_capability('plagiarism/originality:viewreport', $context)) {
            return true;
        }

        // For the submitting student: check global + per-activity settings.
        if ((int) $USER->id !== (int) $fileuserid) {
            return false;
        }

        $globalsettings = (array) get_config('plagiarism_originality');
        if (empty($globalsettings['originality_student_report'])) {
            return false;
        }

        $modsettings = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid]);
        if (empty($modsettings) || empty($modsettings->student_report)) {
            return false;
        }

        return true;
    }

    /**
     * List of activity modules that support plagiarism checking.
     */
    const SUPPORTED_MODULES = ['assign', 'forum', 'workshop', 'quiz'];

    /**
     * Check if a module type is supported and enabled in global settings.
     *
     * @param string $modulename Module name (e.g. 'assign', 'mod_assign').
     * @param array|null $settings Global plugin settings (loaded if null).
     * @return bool
     */
    public static function is_module_supported(string $modulename, ?array $settings = null): bool {
        // Normalise: strip 'mod_' prefix if present.
        $modulename = preg_replace('/^mod_/', '', $modulename);

        if (!in_array($modulename, static::SUPPORTED_MODULES)) {
            return false;
        }

        if ($settings === null) {
            $settings = (array) get_config('plagiarism_originality');
        }

        $key = 'originality_mod_' . $modulename;
        return !empty($settings[$key]);
    }

    /**
     * Get the module name (e.g. 'assign') for a given course module ID.
     *
     * @param int $cmid
     * @return string Module name or empty string if not found.
     */
    public static function get_module_name(int $cmid): string {
        global $DB;
        $sql = "SELECT m.name FROM {modules} m
                  JOIN {course_modules} cm ON cm.module = m.id
                 WHERE cm.id = :cmid";
        return $DB->get_field_sql($sql, ['cmid' => $cmid]) ?: '';
    }
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

    // Queue an adhoc task to submit the file in the background with retry.
    $task = new \plagiarism_originality\task\submit_to_originality();
    $task->set_custom_data([
        'record_id' => $record->id,
        'attempt' => 1,
    ]);
    \core\task\manager::queue_adhoc_task($task);
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

    // Queue an adhoc task to submit the text in the background with retry.
    $task = new \plagiarism_originality\task\submit_to_originality();
    $task->set_custom_data([
        'record_id' => $record->id,
        'attempt' => 1,
    ]);
    \core\task\manager::queue_adhoc_task($task);
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
