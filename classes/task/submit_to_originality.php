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
 * Adhoc task to submit a file to the Originality API with exponential backoff retry.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\task;

/**
 * Adhoc task: submit a file or text to the external Originality service.
 *
 * Custom data expected:
 *   - record_id: int  (plagiarism_originality_files.id)
 *   - attempt:   int  (current attempt number, starts at 1)
 */
class submit_to_originality extends \core\task\adhoc_task {
    /** @var int Maximum number of retry attempts. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/plagiarism/originality/lib.php');

        $data = $this->get_custom_data();
        $recordid = (int) $data->record_id;
        $attempt = (int) ($data->attempt ?? 1);

        $record = $DB->get_record('plagiarism_originality_files', ['id' => $recordid]);
        if (!$record) {
            mtrace("Originality submit task: record {$recordid} not found, skipping.");
            return;
        }

        // Already successfully submitted or completed — nothing to do.
        if ((int) $record->status === 1 || (int) $record->status === 2) {
            mtrace("Originality submit task: record {$recordid} already submitted/complete, skipping.");
            return;
        }

        try {
            $client = \plagiarism_originality\api_client::create();

            $index = $this->should_index((int) $record->cm);

            if ($record->submissiontype === 'onlinetext') {
                $response = $this->submit_onlinetext($DB, $client, $record, $index);
            } else {
                $response = $this->submit_file_record($DB, $client, $record, $index);
            }

            $record->externalid = (string) ($response['documentId'] ?? '');
            $record->status = 1; // Submitted.
            $record->attempts = $attempt;
            $record->errorresponse = null;
            $record->timemodified = time();
            $DB->update_record('plagiarism_originality_files', $record);

            mtrace("Originality submit task: record {$recordid} submitted successfully on attempt {$attempt}.");
        } catch (\Exception $e) {
            $record->attempts = $attempt;
            $record->errorresponse = $e->getMessage();
            $record->timemodified = time();

            if ($attempt >= self::MAX_ATTEMPTS) {
                $record->status = 3; // Final error.
                $DB->update_record('plagiarism_originality_files', $record);
                mtrace("Originality submit task: record {$recordid} failed after {$attempt} attempts: " . $e->getMessage());
            } else {
                $record->status = 0; // Still pending, will retry.
                $DB->update_record('plagiarism_originality_files', $record);

                // Schedule retry with exponential backoff: 30s, 120s, 480s, 1920s.
                $delay = 30 * pow(4, $attempt - 1);
                $task = new self();
                $task->set_custom_data([
                    'record_id' => $recordid,
                    'attempt' => $attempt + 1,
                ]);
                $task->set_next_run_time(time() + $delay);
                \core\task\manager::queue_adhoc_task($task);

                mtrace("Originality submit task: record {$recordid} failed on attempt {$attempt}, retrying in {$delay}s.");
            }
        }
    }

    /**
     * Determine whether documents for a course module should be indexed.
     *
     * Uses the per-activity setting when available, otherwise falls back to the
     * global plugin default.
     *
     * @param int $cmid The course module ID.
     * @return bool
     */
    private function should_index(int $cmid): bool {
        global $DB;

        $modsettings = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid]);
        if ($modsettings !== false && isset($modsettings->index_documents)) {
            return (bool) $modsettings->index_documents;
        }

        return (bool) get_config('plagiarism_originality', 'originality_index_documents');
    }

    /**
     * Submit a file record to the external service.
     *
     * @param \moodle_database $DB
     * @param \plagiarism_originality\api_client $client
     * @param object $record The plagiarism_originality_files record.
     * @param bool $index Whether the document should be indexed by the service.
     * @return array The API response.
     * @throws \moodle_exception If the file cannot be found or submitted.
     */
    private function submit_file_record(\moodle_database $DB, \plagiarism_originality\api_client $client, object $record, bool $index = false): array {
        $fs = get_file_storage();
        $cm = get_coursemodule_from_id('', (int) $record->cm);
        if (!$cm) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Course module {$record->cm} not found for record {$record->id}.");
        }
        $context = \context_module::instance($cm->id, IGNORE_MISSING);
        if (!$context) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Context not found for cm {$record->cm}.");
        }

        // Search for the file across all areas within this module context.
        $files = $DB->get_records_select(
            'files',
            'contenthash = :hash AND contextid = :ctx AND filename != :dot',
            ['hash' => $record->identifier, 'ctx' => $context->id, 'dot' => '.'],
            '',
            '*',
            0,
            1
        );

        $filerecord = reset($files);
        if (!$filerecord) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Cannot find file with hash {$record->identifier} for record {$record->id}.");
        }

        $file = $fs->get_file_by_id($filerecord->id);
        if (!$file || $file->is_directory()) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "File not valid for record {$record->id}.");
        }

        return $client->submit_file($file, (int) $record->cm, (int) $record->userid, $index);
    }

    /**
     * Submit online text content to the external service.
     *
     * @param \moodle_database $DB
     * @param \plagiarism_originality\api_client $client
     * @param object $record The plagiarism_originality_files record.
     * @param bool $index Whether the document should be indexed by the service.
     * @return array The API response.
     * @throws \moodle_exception If the text content cannot be found or submitted.
     */
    private function submit_onlinetext(\moodle_database $DB, \plagiarism_originality\api_client $client, object $record, bool $index = false): array {
        $cm = get_coursemodule_from_id('', (int) $record->cm);
        if (!$cm) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Course module {$record->cm} not found for record {$record->id}.");
        }

        // Retrieve the online text from the assignment submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $cm->instance,
            'userid' => (int) $record->userid,
        ], '*', IGNORE_MULTIPLE);

        if (!$submission) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Submission not found for user {$record->userid} in assignment {$cm->instance}.");
        }

        $onlinetext = $DB->get_record('assignsubmission_onlinetext', [
            'assignment' => $cm->instance,
            'submission' => $submission->id,
        ]);

        if (!$onlinetext || empty($onlinetext->onlinetext)) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Online text not found for record {$record->id}.");
        }

        // Verify the content matches (identifier is sha1 of content).
        if (sha1($onlinetext->onlinetext) !== $record->identifier) {
            throw new \moodle_exception('apierror', 'plagiarism_originality', '',
                "Online text content hash mismatch for record {$record->id}.");
        }

        return $client->submit_text($onlinetext->onlinetext, (int) $record->cm, (int) $record->userid, $index);
    }
}
