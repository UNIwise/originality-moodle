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

defined('MOODLE_INTERNAL') || die();

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

        // For online text we cannot re-fetch content from the event, skip retry.
        if ($record->submissiontype === 'onlinetext') {
            mtrace("Originality submit task: record {$recordid} is onlinetext, cannot retry.");
            return;
        }

        try {
            $client = \plagiarism_originality\api_client::create();

            // Find the file by content hash using the course module context.
            $fs = get_file_storage();
            $cm = get_coursemodule_from_id('', (int) $record->cm);
            if (!$cm) {
                mtrace("Originality submit task: course module {$record->cm} not found for record {$recordid}.");
                return;
            }
            $context = \context_module::instance($cm->id, IGNORE_MISSING);
            if (!$context) {
                mtrace("Originality submit task: context not found for cm {$record->cm}.");
                return;
            }

            // Search for the file across all areas within this module context.
            $files = $DB->get_records_select('files',
                'contenthash = :hash AND contextid = :ctx AND filename != :dot',
                ['hash' => $record->identifier, 'ctx' => $context->id, 'dot' => '.'],
                '', '*', 0, 1
            );

            $filerecord = reset($files);
            if (!$filerecord) {
                mtrace("Originality submit task: cannot find file with hash {$record->identifier}.");
                return;
            }

            $file = $fs->get_file_by_id($filerecord->id);
            if (!$file || $file->is_directory()) {
                mtrace("Originality submit task: file not valid for record {$recordid}.");
                return;
            }

            $response = $client->submit_file($file, (int) $record->cm, (int) $record->userid);

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
}
