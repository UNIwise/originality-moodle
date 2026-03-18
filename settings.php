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
 * plagiarism.php - allows the admin to configure plagiarism stuff
 *
 * @package   plagiarism_turnitin
 * @author    Dan Marsden <dan@danmarsden.com>
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    require_once(dirname(dirname(__FILE__)) . '/../config.php');
    require_once($CFG->libdir.'/adminlib.php');
    require_once($CFG->libdir.'/plagiarismlib.php');
    require_once($CFG->dirroot.'/plagiarism/originality/lib.php');
    require_once($CFG->dirroot.'/plagiarism/originality/plagiarism_form.php');

    require_login();
    admin_externalpage_setup('plagiarismoriginality');

    // $context = get_context_instance(CONTEXT_SYSTEM);
    $context = context_system::instance();
    require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");

    require_once('plagiarism_form.php');
    $mform = new plagiarism_setup_form();
    $plagiarismplugin = new plagiarism_plugin_originality();
    $settingspage = new moodle_url('/plagiarism/originality/settings.php');

    if ($mform->is_cancelled()) {
        redirect(new moodle_url('/admin/category.php', array('category'=>'plagiarism')));
    }

    echo $OUTPUT->header();

    if (($data = $mform->get_data()) && confirm_sesskey()) {
        if (!isset($data->originality_use)) {
            $data->originality_use = 0;
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
    $plagiarismsettings = (array)get_config('plagiarism_originality');
    $mform->set_data($plagiarismsettings);
    
    echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
    $mform->display();
    echo $OUTPUT->box_end();
    echo $OUTPUT->footer();
