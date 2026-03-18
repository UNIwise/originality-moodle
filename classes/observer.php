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
 * Event observer for plagiarism_originality.
 *
 * Listens for assessable_uploaded and assessable_submitted events to
 * automatically send files to the external plagiarism service.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer class for plagiarism_originality events.
 */
class observer {

    /**
     * Handle the assessable_uploaded event (file submissions).
     *
     * @param \core\event\assessable_uploaded $event
     */
    public static function assessable_uploaded(\core\event\assessable_uploaded $event): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/plagiarism/originality/lib.php');

        $config = get_config('plagiarism_originality');
        if (empty($config->originality_use)) {
            return;
        }

        $cmid = $event->contextinstanceid;

        // Check if originality is enabled for this activity.
        $modsettings = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid]);
        if (empty($modsettings) || empty($modsettings->enabled)) {
            return;
        }

        $eventdata = $event->get_data();
        $userid = $eventdata['userid'];

        // Get the files from the event.
        $other = $eventdata['other'] ?? [];
        $pathnamehashes = $other['pathnamehashes'] ?? [];

        if (empty($pathnamehashes)) {
            return;
        }

        $fs = get_file_storage();
        foreach ($pathnamehashes as $hash) {
            $file = $fs->get_file_by_hash($hash);
            if ($file && !$file->is_directory()) {
                plagiarism_originality_submit_file($file, $cmid, $userid);
            }
        }
    }

    /**
     * Handle the assessable_submitted event (online text / final submission).
     *
     * @param \core\event\assessable_submitted $event
     */
    public static function assessable_submitted(\core\event\assessable_submitted $event): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/plagiarism/originality/lib.php');

        $config = get_config('plagiarism_originality');
        if (empty($config->originality_use)) {
            return;
        }

        $cmid = $event->contextinstanceid;

        $modsettings = $DB->get_record('plagiarism_originality_settings', ['cm' => $cmid]);
        if (empty($modsettings) || empty($modsettings->enabled)) {
            return;
        }

        $eventdata = $event->get_data();
        $userid = $eventdata['userid'];
        $other = $eventdata['other'] ?? [];

        // Handle online text content if present.
        $content = $other['content'] ?? '';
        if (!empty($content)) {
            plagiarism_originality_submit_text($content, $cmid, $userid);
        }

        // Handle any associated files.
        $pathnamehashes = $other['pathnamehashes'] ?? [];
        if (!empty($pathnamehashes)) {
            $fs = get_file_storage();
            foreach ($pathnamehashes as $hash) {
                $file = $fs->get_file_by_hash($hash);
                if ($file && !$file->is_directory()) {
                    plagiarism_originality_submit_file($file, $cmid, $userid);
                }
            }
        }
    }

    /**
     * Handle the submission_removed event.
     *
     * Deletes the corresponding documents from the external plagiarism service.
     *
     * @param \mod_assign\event\submission_removed $event
     */
    public static function submission_removed(\mod_assign\event\submission_removed $event): void {
        global $DB;

        $config = get_config('plagiarism_originality');
        if (empty($config->originality_use)) {
            return;
        }

        $cmid = $event->contextinstanceid;
        $userid = $event->relateduserid ?? $event->userid;

        // Find all tracked files for this user on this course module.
        $records = $DB->get_records('plagiarism_originality_files', [
            'cm'     => $cmid,
            'userid' => $userid,
        ]);

        if (empty($records)) {
            return;
        }

        try {
            $client = \plagiarism_originality\api_client::create();
        } catch (\Exception $e) {
            debugging('Originality submission_removed: could not create API client: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        foreach ($records as $record) {
            // Delete from external service if we have an external ID.
            if (!empty($record->externalid)) {
                try {
                    $client->delete_document($record->externalid);
                } catch (\Exception $e) {
                    debugging('Originality delete failed for externalid ' . $record->externalid
                        . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            // Remove the local tracking record.
            $DB->delete_records('plagiarism_originality_files', ['id' => $record->id]);
        }
    }
}
