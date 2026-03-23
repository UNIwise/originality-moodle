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
 * plagiarism_originality upgrade.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function for the plagiarism_originality plugin.
 * This function performs the necessary database upgrades based on the old version of the plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool Returns true if the upgrade is successful, false otherwise.
 */
function xmldb_plagiarism_originality_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026022401) {
        // Create plagiarism_originality_settings table.
        $table = new xmldb_table('plagiarism_originality_settings');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cm', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '2', null, null, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cm', XMLDB_KEY_FOREIGN, ['cm'], 'course_modules', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create plagiarism_originality_files table.
        $table = new xmldb_table('plagiarism_originality_files');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cm', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('submissiontype', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('externalid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('created_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('updated_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cm', XMLDB_KEY_FOREIGN, ['cm'], 'course_modules', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Originality savepoint reached.
        upgrade_plugin_savepoint(true, 2026022401, 'plagiarism', 'originality');
    }

    if ($oldversion < 2026031201) {
        // Plagiarism_originality_settings: make cm unique.
        $table = new xmldb_table('plagiarism_originality_settings');
        // Drop the old non-unique foreign key on cm first.
        $key = new xmldb_key('cm', XMLDB_KEY_FOREIGN, ['cm'], 'course_modules', ['id']);
        $dbman->drop_key($table, $key);
        // Add it back as a foreign-unique key.
        $key = new xmldb_key('cm', XMLDB_KEY_FOREIGN_UNIQUE, ['cm'], 'course_modules', ['id']);
        $dbman->add_key($table, $key);

        // Plagiarism_originality_files: add missing fields.
        $table = new xmldb_table('plagiarism_originality_files');

        // Identifier – Moodle file content hash.
        $field = new xmldb_field('identifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Filename – original filename for display.
        $field = new xmldb_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'identifier');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Change submissiontype from TEXT to CHAR(20).
        $field = new xmldb_field('submissiontype', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'filename');
        $dbman->change_field_type($table, $field);

        // Reporturl – URL to external report.
        $field = new xmldb_field('reporturl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'externalid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Score – similarity percentage.
        $field = new xmldb_field('score', XMLDB_TYPE_INTEGER, '5', null, null, null, null, 'reporturl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Make status NOT NULL with default 0.
        $field = new xmldb_field('status', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'score');
        $dbman->change_field_notnull($table, $field);
        $dbman->change_field_default($table, $field);

        // Errorresponse – error message from service.
        $field = new xmldb_field('errorresponse', XMLDB_TYPE_TEXT, null, null, null, null, null, 'attempts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Rename created_at -> timecreated (Moodle convention).
        $field = new xmldb_field('created_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'timecreated');
        }

        // Rename updated_at -> timemodified (Moodle convention).
        $field = new xmldb_field('updated_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'timemodified');
        }

        // Add foreign key on userid.
        $key = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $dbman->add_key($table, $key);

        // Add composite index on cm + userid for lookup performance.
        $index = new xmldb_index('cm_userid', XMLDB_INDEX_NOTUNIQUE, ['cm', 'userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026031201, 'plagiarism', 'originality');
    }

    if ($oldversion < 2026032001) {
        // Add student_report field to plagiarism_originality_settings.
        $table = new xmldb_table('plagiarism_originality_settings');
        $field = new xmldb_field('student_report', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026032001, 'plagiarism', 'originality');
    }

    if ($oldversion < 2026032002) {
        // Add submit_on field to plagiarism_originality_settings.
        $table = new xmldb_table('plagiarism_originality_settings');
        $field = new xmldb_field('submit_on', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'student_report');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026032002, 'plagiarism', 'originality');
    }

    if ($oldversion < 2026032003) {
        $table = new xmldb_table('plagiarism_originality_files');

        // Add index on status for efficient filtering in scheduled tasks and failed_tasks.php.
        $index = new xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add index on externalid for lookups in polling and deletion.
        $index = new xmldb_index('externalid', XMLDB_INDEX_NOTUNIQUE, ['externalid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026032003, 'plagiarism', 'originality');
    }

    return true;
}
