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

/**
 * Scheduled task to poll the external service for submission results.
 */
class submit_files extends \core\task\scheduled_task {
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

        // Poll for results on submitted files (status = 1).
        $this->poll_submitted_results($DB, $client);

        // Re-queue orphaned pending records that have no adhoc task waiting.
        $this->requeue_orphaned_pending($DB);
    }

    /**
     * Re-queue adhoc tasks for pending records (status = 0) that have been
     * stuck for more than 5 minutes and have no queued adhoc task.
     *
     * @param \moodle_database $DB
     */
    private function requeue_orphaned_pending(\moodle_database $DB): void {
        $cutoff = time() - 300; // At least 5 minutes old.

        $records = $DB->get_records_select(
            'plagiarism_originality_files',
            "status = :status AND timemodified < :cutoff",
            ['status' => 0, 'cutoff' => $cutoff],
            'timemodified ASC',
            '*',
            0,
            100
        );

        if (empty($records)) {
            return;
        }

        // Get record IDs that already have a queued adhoc task to avoid duplicates.
        $queued = $DB->get_records_select(
            'task_adhoc',
            "classname = :classname",
            ['classname' => '\\plagiarism_originality\\task\\submit_to_originality'],
            '',
            'id, customdata'
        );
        $queuedids = [];
        foreach ($queued as $task) {
            $data = json_decode($task->customdata);
            if (!empty($data->record_id)) {
                $queuedids[(int) $data->record_id] = true;
            }
        }

        foreach ($records as $record) {
            if (isset($queuedids[(int) $record->id])) {
                continue;
            }

            $attempt = ((int) ($record->attempts ?? 0)) + 1;
            $task = new submit_to_originality();
            $task->set_custom_data([
                'record_id' => $record->id,
                'attempt' => $attempt,
            ]);
            \core\task\manager::queue_adhoc_task($task);

            mtrace("Originality: Re-queued orphaned record {$record->id} (attempt {$attempt}).");
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
