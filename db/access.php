<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = array(

    /* Manually unenroll automatically enrolled students */
    'enrol/rest:unenrol' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'manager'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        )
    ),
);