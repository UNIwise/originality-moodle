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
 * Settings page for the Wiseflow Originality plagiarism plugin.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/plagiarism/originality/lib.php');
require_once($CFG->dirroot . '/plagiarism/originality/plagiarism_form.php');

require_login();
admin_externalpage_setup('plagiarismoriginality');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$mform = new plagiarism_setup_form();
$plagiarismplugin = new plagiarism_plugin_originality();
$settingspage = new moodle_url('/plagiarism/originality/settings.php');

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/category.php', ['category' => 'plagiarism']));
}

echo $OUTPUT->header();

// Tab navigation.
$settingsurl = new moodle_url('/plagiarism/originality/settings.php');
$failedurl = new moodle_url('/plagiarism/originality/failed_tasks.php');
$tabs = [
    new tabobject('settings', $settingsurl, get_string('pluginsettings', 'plagiarism_originality')),
    new tabobject('failedtasks', $failedurl, get_string('failedtasks', 'plagiarism_originality')),
];
echo $OUTPUT->tabtree($tabs, 'settings');

if (($data = $mform->get_data()) && confirm_sesskey()) {
    // Ensure unchecked checkboxes are explicitly saved as 0.
    $checkboxes = [
        'originality_use',
        'originality_mod_assign',
        'originality_mod_forum',
        'originality_mod_workshop',
        'originality_mod_quiz',
        'originality_student_report',
    ];
    foreach ($checkboxes as $cb) {
        if (!isset($data->$cb)) {
            $data->$cb = 0;
        }
    }
    foreach ($data as $field => $value) {
        if (strpos($field, 'originality') === 0) {
            set_config($field, $value, 'plagiarism_originality');
        }
    }
    // Set the 'enabled' key that plagiarism_load_available_plugins() checks for plugin discovery.
    set_config('enabled', !empty($data->originality_use) ? 1 : 0, 'plagiarism_originality');
    echo $OUTPUT->notification(get_string('savedconfigsuccess', 'plagiarism_originality'), 'notifysuccess');
}
$plagiarismsettings = (array) get_config('plagiarism_originality');
$mform->set_data($plagiarismsettings);

echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
$mform->display();
echo $OUTPUT->box_end();
echo $OUTPUT->footer();
