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
 * Scheduled task to process pending plagiarism submissions and poll for results.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Task to retry failed submissions and poll the external service for results.
 */
class submit_files extends \core\task\scheduled_task {

    /** @var int Maximum number of retry attempts for failed submissions. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Return the name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('submittask', 'plagiarism_originality');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB, $CFG;

        $config = get_config('plagiarism_originality');
        if (empty($config->originality_use)) {
            return;
        }

        require_once($CFG->dirroot . '/plagiarism/originality/lib.php');

        try {
            $client = \plagiarism_originality\api_client::create();
        } catch (\Exception $e) {
            mtrace('Originality: Cannot create API client - ' . $e->getMessage());
            return;
        }

        // 1. Retry failed submissions (status = 3) that haven't exceeded max attempts.
        $this->retry_failed_submissions($DB, $client);

        // 2. Poll for results on submitted files (status = 1).
        $this->poll_submitted_results($DB, $client);
    }

    /**
     * Retry submissions that previously failed.
     *
     * @param \moodle_database $DB
     * @param \plagiarism_originality\api_client $client
     */
    private function retry_failed_submissions(\moodle_database $DB, \plagiarism_originality\api_client $client): void {
        global $CFG;

        $records = $DB->get_records_select(
            'plagiarism_originality_files',
            'status = :status AND attempts < :maxattempts',
            ['status' => 3, 'maxattempts' => self::MAX_ATTEMPTS],
            'timemodified ASC',
            '*',
            0,
            100 // Process in batches.
        );

        $fs = get_file_storage();

        foreach ($records as $record) {
            try {
                if ($record->submissiontype === 'onlinetext') {
                    // For online text we can't easily re-fetch the content here,
                    // so we just skip — it will be resubmitted on next student action.
                    continue;
                }

                // Find the file by content hash.
                $files = $DB->get_records('files', [
                    'contenthash' => $record->identifier,
                    'component' => 'assignsubmission_file',
                ], '', '*', 0, 1);

                $filerecord = reset($files);
                if (!$filerecord) {
                    mtrace("Originality: Cannot find file with hash {$record->identifier}, skipping.");
                    continue;
                }

                $file = $fs->get_file_by_id($filerecord->id);
                if (!$file || $file->is_directory()) {
                    continue;
                }

                $response = $client->submit_file($file, $record->cm, $record->userid);

                $record->externalid = (string) ($response['documentId'] ?? '');
                $record->status = 1; // Submitted.
                $record->attempts = $record->attempts + 1;
                $record->errorresponse = null;
                $record->timemodified = time();
                $DB->update_record('plagiarism_originality_files', $record);

                mtrace("Originality: Retried file {$record->id} successfully.");
            } catch (\Exception $e) {
                $record->attempts = $record->attempts + 1;
                $record->errorresponse = $e->getMessage();
                $record->timemodified = time();
                $DB->update_record('plagiarism_originality_files', $record);

                mtrace("Originality: Retry failed for file {$record->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Poll the external service for results on submitted files.
     *
     * @param \moodle_database $DB
     * @param \plagiarism_originality\api_client $client
     */
    private function poll_submitted_results(\moodle_database $DB, \plagiarism_originality\api_client $client): void {
        $records = $DB->get_records_select(
            'plagiarism_originality_files',
            "status = :status AND externalid IS NOT NULL AND externalid != ''",
            ['status' => 1],
            'timemodified ASC',
            '*',
            0,
            200
        );

        foreach ($records as $record) {
            try {
                $result = $client->get_submission_status($record->externalid);

                $externalstatus = $result['status'] ?? '';

                if ($externalstatus === 'complete' || $externalstatus === 'available') {
                    $record->status = 2; // Complete.
                    // API returns score as float 0-1, convert to integer percentage.
                    $rawscore = $result['result']['score'] ?? 0;
                    $record->score = (int) round($rawscore * 100);
                    $record->reporturl = $result['result']['viewerLink'] ?? '';
                    $record->errorresponse = null;
                    $record->timemodified = time();
                    $DB->update_record('plagiarism_originality_files', $record);

                    mtrace("Originality: Result received for {$record->id}, score: {$record->score}%.");
                } else if ($externalstatus === 'failed') {
                    $record->status = 3; // Error.
                    $record->errorresponse = $result['detail'] ?? 'External processing failed';
                    $record->timemodified = time();
                    $DB->update_record('plagiarism_originality_files', $record);

                    mtrace("Originality: External error for {$record->id}: {$record->errorresponse}");
                }
                // If still "in_progress", do nothing — we'll check again next run.
            } catch (\Exception $e) {
                mtrace("Originality: Poll failed for {$record->id}: " . $e->getMessage());
            }
        }
    }
}
