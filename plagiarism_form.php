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

        $this->add_action_buttons(true);
    }
}

