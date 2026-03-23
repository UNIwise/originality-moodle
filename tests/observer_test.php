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
 * Unit tests for the plagiarism_originality observer.
 *
 * Tests the event handler gating logic (global enable, module support,
 * per-activity settings, submit timing).
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/originality/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for the observer class.
 *
 * @covers \plagiarism_originality\observer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_originality\observer::class)]
final class observer_test extends \advanced_testcase {
    /**
     * Helper: enable the plugin globally with assign module support.
     */
    private function enable_plugin(): void {
        set_config('originality_use', 1, 'plagiarism_originality');
        set_config('originality_mod_assign', 1, 'plagiarism_originality');
        set_config('originality_api_url', 'https://api.example.com', 'plagiarism_originality');
        set_config('originality_client_id', 'testid', 'plagiarism_originality');
        set_config('originality_client_secret', 'testsecret', 'plagiarism_originality');
    }

    /**
     * Helper: set or update the per-activity originality settings.
     */
    private function set_activity_settings(int $cmid, int $enabled = 1, int $studentreport = 0, int $submiton = 0): void {
        $data = new \stdClass();
        $data->coursemodule = $cmid;
        $data->originality_enabled = $enabled;
        $data->originality_student_report = $studentreport;
        $data->originality_submit_on = $submiton;

        $plugin = new \plagiarism_plugin_originality();
        $plugin->save_form_elements($data);
    }

    /**
     * Helper: create a real assign submission with online text for a student.
     * This triggers the proper assessable_submitted event from mod_assign.
     *
     * @param \stdClass $student The student user.
     * @param \stdClass $assign The assign module record.
     * @param string $text The text content.
     */
    private function create_online_text_submission(\stdClass $student, \stdClass $assign, string $text): void {
        global $DB;

        $this->setUser($student);

        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $context = \context_module::instance($cm->id);
        $assignobj = new \assign($context, $cm, null);

        // Add a submission for this student.
        $submission = $assignobj->get_user_submission($student->id, true);
        $data = new \stdClass();
        $data->onlinetext_editor = [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
        $plugin = $assignobj->get_submission_plugin_by_type('onlinetext');
        $plugin->save($submission, $data);

        // Submit the assignment (triggers assessable_submitted).
        $assignobj->submit_for_grading($data, []);
    }

    // Event gating tests (no real submissions needed).

    /**
     * Test observer does nothing when plugin is disabled.
     *
     * @covers \plagiarism_originality\observer::assessable_submitted
     */
    public function test_observer_does_nothing_when_plugin_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        // Plugin NOT enabled.
        set_config('originality_use', 0, 'plagiarism_originality');
        set_config('originality_mod_assign', 1, 'plagiarism_originality');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        $this->create_online_text_submission($student, $assign, 'Test content');

        // No originality records should have been created.
        $count = $DB->count_records('plagiarism_originality_files');
        $this->assertEquals(0, $count);
    }

    /**
     * Test observer does nothing when module is unsupported.
     *
     * @covers \plagiarism_originality\observer::assessable_submitted
     */
    public function test_observer_does_nothing_when_module_unsupported(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('originality_use', 1, 'plagiarism_originality');
        set_config('originality_mod_assign', 0, 'plagiarism_originality'); // Assign disabled.
        set_config('originality_mod_forum', 1, 'plagiarism_originality');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        $this->create_online_text_submission($student, $assign, 'Test content');

        $count = $DB->count_records('plagiarism_originality_files');
        $this->assertEquals(0, $count);
    }

    /**
     * Test observer does nothing when activity is disabled.
     *
     * @covers \plagiarism_originality\observer::assessable_submitted
     */
    public function test_observer_does_nothing_when_activity_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $this->enable_plugin();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);

        // Activity NOT enabled.
        $this->set_activity_settings($assign->cmid, 0, 0, 0);

        $this->create_online_text_submission($student, $assign, 'Test content');

        $count = $DB->count_records('plagiarism_originality_files');
        $this->assertEquals(0, $count);
    }

    /**
     * Test observer skips when submit on marking.
     *
     * @covers \plagiarism_originality\observer::assessable_submitted
     */
    public function test_observer_skips_when_submit_on_marking(): void {
        global $DB;
        $this->resetAfterTest();

        $this->enable_plugin();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);

        // Activity enabled with submit_on = 1 (on marking).
        $this->set_activity_settings($assign->cmid, 1, 0, 1);

        $this->create_online_text_submission($student, $assign, 'Test content');

        // Should NOT create records since submit_on is set to marking.
        $count = $DB->count_records('plagiarism_originality_files');
        $this->assertEquals(0, $count);
    }

    /**
     * Test observer processes online text on upload.
     *
     * @covers \plagiarism_originality\observer::assessable_submitted
     */
    public function test_observer_processes_online_text_on_upload(): void {
        global $DB;
        $this->resetAfterTest();

        $this->enable_plugin();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0); // Submit on upload.

        $content = 'This is an online text submission for plagiarism checking.';

        $this->create_online_text_submission($student, $assign, $content);

        // A file record should be created for the online text.
        $records = $DB->get_records('plagiarism_originality_files', [
            'cm' => $assign->cmid,
            'userid' => $student->id,
        ]);
        $this->assertNotEmpty($records);

        $record = reset($records);
        $this->assertEquals('onlinetext', $record->submissiontype);
        $this->assertEquals(0, (int) $record->status);
    }

    // Submission removed.

    /**
     * Test submission removed queues delete tasks.
     *
     * @covers \plagiarism_originality\observer::submission_removed
     */
    public function test_submission_removed_queues_delete_tasks(): void {
        global $DB;
        $this->resetAfterTest();

        $this->enable_plugin();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        // Create file records to be "removed".
        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'hash1',
            'filename' => 'test1.txt',
            'submissiontype' => 'file',
            'externalid' => 'ext-111',
            'status' => 2,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'hash2',
            'filename' => 'test2.txt',
            'submissiontype' => 'file',
            'externalid' => 'ext-222',
            'status' => 2,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Fire the submission_removed event.
        $context = \context_module::instance($assign->cmid);
        $event = \mod_assign\event\submission_removed::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => $student->id,
            'other' => ['submissionid' => 1, 'submissionattempt' => 0, 'submissionstatus' => 'new'],
        ]);
        $event->trigger();

        // Two delete tasks should be queued.
        $tasks = $DB->get_records('task_adhoc', [
            'classname' => '\\plagiarism_originality\\task\\delete_from_originality',
        ]);
        $this->assertCount(2, $tasks);
    }

    /**
     * Test submission removed does nothing when plugin is disabled.
     *
     * @covers \plagiarism_originality\observer::submission_removed
     */
    public function test_submission_removed_does_nothing_when_plugin_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('originality_use', 0, 'plagiarism_originality');
        set_config('originality_mod_assign', 1, 'plagiarism_originality');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        // Create a file record.
        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'hash1',
            'filename' => 'test.txt',
            'submissiontype' => 'file',
            'externalid' => 'ext-111',
            'status' => 2,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $context = \context_module::instance($assign->cmid);
        $event = \mod_assign\event\submission_removed::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => $student->id,
            'other' => ['submissionid' => 1, 'submissionattempt' => 0, 'submissionstatus' => 'new'],
        ]);
        $event->trigger();

        // No delete tasks should be queued.
        $tasks = $DB->get_records('task_adhoc', [
            'classname' => '\\plagiarism_originality\\task\\delete_from_originality',
        ]);
        $this->assertEmpty($tasks);
    }
}
