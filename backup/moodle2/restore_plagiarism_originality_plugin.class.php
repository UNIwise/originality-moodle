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
 * Restore support for plagiarism_originality per-activity settings.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the per-activity Originality settings onto the restored course module.
 */
class restore_plagiarism_originality_plugin extends restore_plagiarism_plugin {
    /**
     * Define the module plugin structure.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('originality_settings', $this->get_pathfor('/originality_settings')),
        ];
    }

    /**
     * Restore a per-activity settings record.
     *
     * @param array $data
     */
    public function process_originality_settings($data) {
        global $DB;

        $data = (object) $data;
        $cmid = $this->task->get_moduleid();

        $record = (object) [
            'cm' => $cmid,
            'enabled' => (int) $data->enabled,
            'student_report' => (int) ($data->student_report ?? 0),
            'submit_on' => (int) ($data->submit_on ?? 0),
            'index_documents' => (int) ($data->index_documents ?? 0),
        ];

        // The cm column is unique, so update if a record was already created for the new module.
        if ($existingid = $DB->get_field('plagiarism_originality_settings', 'id', ['cm' => $cmid])) {
            $record->id = $existingid;
            $DB->update_record('plagiarism_originality_settings', $record);
        } else {
            $DB->insert_record('plagiarism_originality_settings', $record);
        }
    }
}
