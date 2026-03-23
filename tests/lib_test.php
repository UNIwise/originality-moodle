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
 * Unit tests for the plagiarism_originality main lib.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/originality/lib.php');

/**
 * Tests for the plagiarism_plugin_originality class and helper functions.
 *
 * @covers \plagiarism_plugin_originality
 * @covers ::plagiarism_originality_submit_text
 * @covers ::plagiarism_originality_submit_file
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_plugin_originality::class)]
final class lib_test extends \advanced_testcase {
    /**
     * Helper: set or update the per-activity originality settings.
     *
     * Uses the plugin's own save_form_elements() to avoid duplicate-key
     * violations from the coursemodule_edit_post_actions callback.
     *
     * @param int $cmid Course module ID.
     * @param int $enabled Whether originality is enabled.
     * @param int $studentreport Whether students can view reports.
     * @param int $submiton 0 = on upload, 1 = on marking.
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

    // Tests for is_module_supported().

    public function test_is_module_supported_returns_true_for_enabled_module(): void {
        $this->resetAfterTest();

        set_config('originality_mod_assign', 1, 'plagiarism_originality');

        $this->assertTrue(\plagiarism_plugin_originality::is_module_supported('assign'));
    }

    public function test_is_module_supported_strips_mod_prefix(): void {
        $this->resetAfterTest();

        set_config('originality_mod_forum', 1, 'plagiarism_originality');

        $this->assertTrue(\plagiarism_plugin_originality::is_module_supported('mod_forum'));
    }

    public function test_is_module_supported_returns_false_for_disabled_module(): void {
        $this->resetAfterTest();

        set_config('originality_mod_assign', 0, 'plagiarism_originality');

        $this->assertFalse(\plagiarism_plugin_originality::is_module_supported('assign'));
    }

    public function test_is_module_supported_returns_false_for_unknown_module(): void {
        $this->resetAfterTest();

        $this->assertFalse(\plagiarism_plugin_originality::is_module_supported('chat'));
        $this->assertFalse(\plagiarism_plugin_originality::is_module_supported('mod_chat'));
    }

    public function test_is_module_supported_uses_passed_settings(): void {
        $this->resetAfterTest();

        $settings = ['originality_mod_workshop' => 1];
        $this->assertTrue(\plagiarism_plugin_originality::is_module_supported('workshop', $settings));

        $settings = ['originality_mod_workshop' => 0];
        $this->assertFalse(\plagiarism_plugin_originality::is_module_supported('workshop', $settings));
    }

    public function test_is_module_supported_all_supported_modules(): void {
        $this->resetAfterTest();

        $settings = [
            'originality_mod_assign' => 1,
            'originality_mod_forum' => 1,
            'originality_mod_workshop' => 1,
            'originality_mod_quiz' => 1,
        ];

        foreach (\plagiarism_plugin_originality::SUPPORTED_MODULES as $mod) {
            $this->assertTrue(
                \plagiarism_plugin_originality::is_module_supported($mod, $settings),
                "Module '$mod' should be supported."
            );
        }
    }

    // Tests for save_form_elements().

    public function test_save_form_elements_inserts_new_record(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->set_activity_settings($assign->cmid, 1, 1, 0);

        $record = $DB->get_record('plagiarism_originality_settings', ['cm' => $assign->cmid]);
        $this->assertNotEmpty($record);
        $this->assertEquals(1, (int) $record->enabled);
        $this->assertEquals(1, (int) $record->student_report);
        $this->assertEquals(0, (int) $record->submit_on);
    }

    public function test_save_form_elements_updates_existing_record(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        // First save: disabled.
        $this->set_activity_settings($assign->cmid, 0, 0, 0);

        // Second save: enabled with all options.
        $this->set_activity_settings($assign->cmid, 1, 1, 1);

        $record = $DB->get_record('plagiarism_originality_settings', ['cm' => $assign->cmid]);
        $this->assertEquals(1, (int) $record->enabled);
        $this->assertEquals(1, (int) $record->student_report);
        $this->assertEquals(1, (int) $record->submit_on);
    }

    public function test_save_form_elements_defaults_unchecked_checkboxes(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        // Submit with no checkbox fields (simulates unchecked).
        $data = new \stdClass();
        $data->coursemodule = $assign->cmid;

        $plugin = new \plagiarism_plugin_originality();
        $plugin->save_form_elements($data);

        $record = $DB->get_record('plagiarism_originality_settings', ['cm' => $assign->cmid]);
        $this->assertNotEmpty($record);
        $this->assertEquals(0, (int) $record->enabled);
        $this->assertEquals(0, (int) $record->student_report);
        $this->assertEquals(0, (int) $record->submit_on);
    }

    public function test_save_form_elements_no_coursemodule_does_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        $countbefore = $DB->count_records('plagiarism_originality_settings');

        $data = new \stdClass();
        $data->originality_enabled = 1;

        $plugin = new \plagiarism_plugin_originality();
        $plugin->save_form_elements($data);

        $countafter = $DB->count_records('plagiarism_originality_settings');
        $this->assertEquals($countbefore, $countafter);
    }

    // Tests for can_user_view_report().

    public function test_can_user_view_report_teacher_always_allowed(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->setUser($teacher);

        $this->assertTrue(
            \plagiarism_plugin_originality::can_user_view_report($assign->cmid, $student->id)
        );
    }

    public function test_can_user_view_report_student_denied_by_default(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->setUser($student);

        $this->assertFalse(
            \plagiarism_plugin_originality::can_user_view_report($assign->cmid, $student->id)
        );
    }

    public function test_can_user_view_report_student_allowed_with_both_settings(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        set_config('originality_student_report', 1, 'plagiarism_originality');
        $this->set_activity_settings($assign->cmid, 1, 1, 0);

        $this->setUser($student);

        $this->assertTrue(
            \plagiarism_plugin_originality::can_user_view_report($assign->cmid, $student->id)
        );
    }

    public function test_can_user_view_report_student_denied_without_global_setting(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        set_config('originality_student_report', 0, 'plagiarism_originality');
        $this->set_activity_settings($assign->cmid, 1, 1, 0);

        $this->setUser($student);

        $this->assertFalse(
            \plagiarism_plugin_originality::can_user_view_report($assign->cmid, $student->id)
        );
    }

    public function test_can_user_view_report_student_denied_without_activity_setting(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        set_config('originality_student_report', 1, 'plagiarism_originality');
        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        $this->setUser($student);

        $this->assertFalse(
            \plagiarism_plugin_originality::can_user_view_report($assign->cmid, $student->id)
        );
    }

    public function test_can_user_view_report_other_student_denied(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        set_config('originality_student_report', 1, 'plagiarism_originality');
        $this->set_activity_settings($assign->cmid, 1, 1, 0);

        $this->setUser($student2);

        $this->assertFalse(
            \plagiarism_plugin_originality::can_user_view_report($assign->cmid, $student1->id)
        );
    }

    // Tests for get_module_name().

    public function test_get_module_name_returns_correct_name(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $name = \plagiarism_plugin_originality::get_module_name($assign->cmid);
        $this->assertEquals('assign', $name);
    }

    public function test_get_module_name_returns_empty_for_invalid_cmid(): void {
        $this->resetAfterTest();

        $name = \plagiarism_plugin_originality::get_module_name(999999);
        $this->assertEquals('', $name);
    }

    // Tests for get_links().

    public function test_get_links_returns_empty_when_plugin_disabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $plugin = new \plagiarism_plugin_originality();
        $output = $plugin->get_links([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'file' => null,
            'content' => '',
        ]);

        $this->assertEquals('', $output);
    }

    public function test_get_links_returns_empty_when_no_identifier(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        $plugin = new \plagiarism_plugin_originality();
        $output = $plugin->get_links([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
        ]);

        $this->assertEquals('', $output);
    }

    public function test_get_links_returns_empty_when_no_file_record(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        $plugin = new \plagiarism_plugin_originality();
        $output = $plugin->get_links([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'content' => 'some text that has no matching record',
        ]);

        $this->assertEquals('', $output);
    }

    public function test_get_links_shows_status_from_local_record(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->set_activity_settings($assign->cmid, 1, 0, 0);

        $content = 'Test online text submission';
        $identifier = sha1($content);

        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => $identifier,
            'filename' => 'onlinetext',
            'submissiontype' => 'onlinetext',
            'status' => 2,
            'score' => 45,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->setUser($teacher);

        $plugin = new \plagiarism_plugin_originality();
        $output = $plugin->get_links([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'content' => $content,
        ]);

        $this->assertStringContainsString('Complete', $output);
        $this->assertStringContainsString('45%', $output);
    }

    // Tests for print_disclosure().

    public function test_print_disclosure_returns_empty_when_disabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        set_config('originality_use', 0, 'plagiarism_originality');

        $plugin = new \plagiarism_plugin_originality();
        $output = $plugin->print_disclosure($assign->cmid);

        $this->assertEquals('', $output);
    }

    public function test_print_disclosure_returns_empty_when_activity_disabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        set_config('originality_use', 1, 'plagiarism_originality');
        $this->set_activity_settings($assign->cmid, 0, 0, 0);

        $plugin = new \plagiarism_plugin_originality();
        $output = $plugin->print_disclosure($assign->cmid);

        $this->assertEquals('', $output);
    }

    // Tests for plagiarism_originality_submit_text().

    public function test_submit_text_creates_record_and_queues_task(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $content = 'This is some online text to check for plagiarism.';
        $identifier = sha1($content);

        plagiarism_originality_submit_text($content, $assign->cmid, $student->id);

        $record = $DB->get_record('plagiarism_originality_files', [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => $identifier,
        ]);
        $this->assertNotEmpty($record);
        $this->assertEquals('onlinetext', $record->submissiontype);
        $this->assertEquals('onlinetext', $record->filename);
        $this->assertEquals(0, (int) $record->status);
        $this->assertEquals(0, (int) $record->attempts);

        $tasks = $DB->get_records('task_adhoc', [
            'classname' => '\\plagiarism_originality\\task\\submit_to_originality',
        ]);
        $this->assertNotEmpty($tasks);
    }

    public function test_submit_text_skips_duplicate(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $content = 'Duplicate test content';
        $identifier = sha1($content);

        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => $identifier,
            'filename' => 'onlinetext',
            'submissiontype' => 'onlinetext',
            'status' => 1,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        plagiarism_originality_submit_text($content, $assign->cmid, $student->id);

        $count = $DB->count_records('plagiarism_originality_files', [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => $identifier,
        ]);
        $this->assertEquals(1, $count);
    }

    public function test_submit_text_resubmits_on_error_status(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $content = 'Resubmit test content';
        $identifier = sha1($content);

        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => $identifier,
            'filename' => 'onlinetext',
            'submissiontype' => 'onlinetext',
            'status' => 3,
            'attempts' => 5,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        plagiarism_originality_submit_text($content, $assign->cmid, $student->id);

        $record = $DB->get_record('plagiarism_originality_files', [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => $identifier,
        ]);
        $this->assertEquals(0, (int) $record->status);
    }

    // SUPPORTED_MODULES constant.

    public function test_supported_modules_contains_expected_values(): void {
        $expected = ['assign', 'forum', 'workshop', 'quiz'];
        $this->assertEquals($expected, \plagiarism_plugin_originality::SUPPORTED_MODULES);
    }
}
