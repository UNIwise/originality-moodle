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
 * Backup support for plagiarism_originality per-activity settings.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Includes the per-activity Originality settings in module backups.
 */
class backup_plagiarism_originality_plugin extends backup_plagiarism_plugin {
    /**
     * Define the module plugin structure.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $settings = new backup_nested_element('originality_settings', ['id'], [
            'enabled',
            'student_report',
            'submit_on',
            'index_documents',
        ]);
        $pluginwrapper->add_child($settings);

        $settings->set_source_table('plagiarism_originality_settings', ['cm' => backup::VAR_PARENTID]);

        return $plugin;
    }
}
