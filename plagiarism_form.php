<?php

require_once($CFG->dirroot.'/lib/formslib.php');

class plagiarism_setup_form extends moodleform {

/// Define the form
    function definition () {
        global $CFG;

        $mform =& $this->_form;
        $choices = array('No','Yes');
        $mform->addElement('html', get_string('originalityexplain', 'plagiarism_originality'));
        $mform->addElement('checkbox', 'originality_use', get_string('useoriginality', 'plagiarism_originality'));

        $mform->addElement('textarea', 'originality_student_disclosure', get_string('studentdisclosure','plagiarism_originality'),'wrap="virtual" rows="6" cols="50"');
        $mform->addHelpButton('originality_student_disclosure', 'studentdisclosure', 'plagiarism_originality');
        $mform->setDefault('originality_student_disclosure', get_string('studentdisclosuredefault','plagiarism_originality'));

        // API connection settings.
        $mform->addElement('header', 'originality_api_header', get_string('apisettings', 'plagiarism_originality'));

        $mform->addElement('text', 'originality_api_url', get_string('apiurl', 'plagiarism_originality'), ['size' => 60]);
        $mform->setType('originality_api_url', PARAM_URL);
        $mform->setDefault('originality_api_url', 'http://localhost:8888');
        $mform->addHelpButton('originality_api_url', 'apiurl', 'plagiarism_originality');
        $mform->addRule('originality_api_url', null, 'required', null, 'client');
        $mform->disabledIf('originality_api_url', 'originality_use');

        $mform->addElement('text', 'originality_client_id', get_string('clientid', 'plagiarism_originality'), ['size' => 60]);
        $mform->setType('originality_client_id', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('originality_client_id', 'clientid', 'plagiarism_originality');
        $mform->addRule('originality_client_id', null, 'required', null, 'client');
        $mform->disabledIf('originality_client_id', 'originality_use');

        $mform->addElement('passwordunmask', 'originality_client_secret', get_string('clientsecret', 'plagiarism_originality'), ['size' => 60]);
        $mform->setType('originality_client_secret', PARAM_RAW);
        $mform->addHelpButton('originality_client_secret', 'clientsecret', 'plagiarism_originality');
        $mform->addRule('originality_client_secret', null, 'required', null, 'client');
        $mform->disabledIf('originality_client_secret', 'originality_use');

        $this->add_action_buttons(true);
    }
}

