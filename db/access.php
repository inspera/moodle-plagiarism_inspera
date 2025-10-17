<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'plagiarism/originality:enable' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/originality:viewreport' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/originality:resetfile' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/originality:advancedsettings' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
    'plagiarism/originality:resubmitallfiles' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_PREVENT,
         'teacher' => CAP_PREVENT,
         'manager' => CAP_PREVENT)
    ),
    'plagiarism/originality:resubmitonclose' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
         'legacy' => array(
         'editingteacher' => CAP_ALLOW,
         'teacher' => CAP_ALLOW,
         'manager' => CAP_ALLOW)
    ),
);
