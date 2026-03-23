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
 * Unit tests for the plagiarism_originality adhoc and scheduled tasks.
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
 * Tests for the task classes.
 *
 * @covers \plagiarism_originality\task\submit_to_originality
 * @covers \plagiarism_originality\task\delete_from_originality
 * @covers \plagiarism_originality\task\submit_files
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_originality\task\submit_to_originality::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_originality\task\delete_from_originality::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_originality\task\submit_files::class)]
final class task_test extends \advanced_testcase {
    // Submit to originality - record not found.

    public function test_submit_task_skips_missing_record(): void {
        $this->resetAfterTest();

        $task = new task\submit_to_originality();
        $task->set_custom_data([
            'record_id' => 999999,
            'attempt' => 1,
        ]);

        $this->expectOutputRegex('/not found/');
        $task->execute();
    }

    public function test_submit_task_skips_already_submitted_record(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $recordid = $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'testhash',
            'filename' => 'test.txt',
            'submissiontype' => 'file',
            'status' => 1, // Already submitted.
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new task\submit_to_originality();
        $task->set_custom_data([
            'record_id' => $recordid,
            'attempt' => 1,
        ]);

        $this->expectOutputRegex('/already submitted/');
        $task->execute();

        // Record should remain status=1.
        $record = $DB->get_record('plagiarism_originality_files', ['id' => $recordid]);
        $this->assertEquals(1, (int) $record->status);
    }

    public function test_submit_task_skips_completed_record(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $recordid = $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'testhash',
            'filename' => 'test.txt',
            'submissiontype' => 'file',
            'status' => 2, // Already complete.
            'score' => 25,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new task\submit_to_originality();
        $task->set_custom_data([
            'record_id' => $recordid,
            'attempt' => 1,
        ]);

        $this->expectOutputRegex('/already submitted/');
        $task->execute();

        // Record should remain unchanged.
        $record = $DB->get_record('plagiarism_originality_files', ['id' => $recordid]);
        $this->assertEquals(2, (int) $record->status);
        $this->assertEquals(25, (int) $record->score);
    }

    public function test_submit_task_skips_onlinetext_retry(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $recordid = $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => sha1('some text'),
            'filename' => 'onlinetext',
            'submissiontype' => 'onlinetext',
            'status' => 0,
            'attempts' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new task\submit_to_originality();
        $task->set_custom_data([
            'record_id' => $recordid,
            'attempt' => 2,
        ]);

        $this->expectOutputRegex('/onlinetext/');
        $task->execute();

        // Record should remain status=0.
        $record = $DB->get_record('plagiarism_originality_files', ['id' => $recordid]);
        $this->assertEquals(0, (int) $record->status);
    }

    // Delete from originality.

    public function test_delete_task_cleans_up_record_without_external_id(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $recordid = $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'testhash',
            'filename' => 'test.txt',
            'submissiontype' => 'file',
            'status' => 1,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new task\delete_from_originality();
        $task->set_custom_data([
            'external_id' => '',
            'record_id' => $recordid,
            'attempt' => 1,
        ]);

        $this->expectOutputRegex('/no external ID/');
        $task->execute();

        // Record should be deleted.
        $exists = $DB->record_exists('plagiarism_originality_files', ['id' => $recordid]);
        $this->assertFalse($exists);
    }

    // Submit files scheduled task - gating.

    public function test_submit_files_task_exits_when_plugin_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('originality_use', 0, 'plagiarism_originality');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $DB->insert_record('plagiarism_originality_files', (object) [
            'cm' => $assign->cmid,
            'userid' => $student->id,
            'identifier' => 'testhash',
            'filename' => 'test.txt',
            'submissiontype' => 'file',
            'externalid' => 'ext-123',
            'status' => 1,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new task\submit_files();
        $task->execute();

        // Record should remain unchanged.
        $record = $DB->get_record('plagiarism_originality_files', [
            'cm' => $assign->cmid,
            'userid' => $student->id,
        ]);
        $this->assertEquals(1, (int) $record->status);
    }

    public function test_submit_files_task_has_correct_name(): void {
        $task = new task\submit_files();
        $name = $task->get_name();
        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }
}
