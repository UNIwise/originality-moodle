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
 * Adhoc task to delete a document from the Originality API with exponential backoff retry.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\task;

/**
 * Adhoc task: delete a document from the external Originality service.
 *
 * Custom data expected:
 *   - external_id: string  (the external document ID)
 *   - record_id:   int     (plagiarism_originality_files.id, for local cleanup)
 *   - attempt:     int     (current attempt number, starts at 1)
 */
class delete_from_originality extends \core\task\adhoc_task {
    /** @var int Maximum number of retry attempts. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $externalid = $data->external_id ?? '';
        $recordid = (int) ($data->record_id ?? 0);
        $attempt = (int) ($data->attempt ?? 1);

        if (empty($externalid)) {
            // No external ID — just delete the local record.
            if ($recordid > 0) {
                $DB->delete_records('plagiarism_originality_files', ['id' => $recordid]);
            }
            mtrace("Originality delete task: no external ID, local record {$recordid} cleaned up.");
            return;
        }

        try {
            $client = \plagiarism_originality\api_client::create();
            $client->delete_document($externalid);

            // Success — delete the local record.
            if ($recordid > 0) {
                $DB->delete_records('plagiarism_originality_files', ['id' => $recordid]);
            }

            mtrace("Originality delete task: document {$externalid} deleted on attempt {$attempt}.");
        } catch (\Exception $e) {
            if ($attempt >= self::MAX_ATTEMPTS) {
                // Give up — mark the record as delete_failed so admin can retry.
                if ($recordid > 0 && $DB->record_exists('plagiarism_originality_files', ['id' => $recordid])) {
                    $update = new \stdClass();
                    $update->id = $recordid;
                    $update->status = 4; // Delete_failed.
                    $update->errorresponse = $e->getMessage();
                    $update->timemodified = time();
                    $DB->update_record('plagiarism_originality_files', $update);
                }
                mtrace("Originality delete task: document {$externalid} failed after {$attempt} attempts: "
                    . $e->getMessage() . ". Record marked as delete_failed.");
            } else {
                // Schedule retry with exponential backoff: 30s, 120s, 480s, 1920s.
                $delay = 30 * pow(4, $attempt - 1);
                $task = new self();
                $task->set_custom_data([
                    'external_id' => $externalid,
                    'record_id' => $recordid,
                    'attempt' => $attempt + 1,
                ]);
                $task->set_next_run_time(time() + $delay);
                \core\task\manager::queue_adhoc_task($task);

                mtrace("Originality delete task: document {$externalid} failed on attempt {$attempt}, "
                    . "retrying in {$delay}s: " . $e->getMessage());
            }
        }
    }
}
