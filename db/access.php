<?php

$capabilities = array(
    // Whether the user can manage.
    'plagiarism/originality:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    // Whether the user can view other users' plagiarism reports.
    'plagiarism/originality:viewreport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
);
