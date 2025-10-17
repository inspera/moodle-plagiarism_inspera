<?php

defined('MOODLE_INTERNAL') || die();

$observers = array (
    array(
        'eventname' => '\assignsubmission_file\event\assessable_uploaded',
        'callback' => 'plagiarism_originality_observer::assignsubmission_file_uploaded'
    ),
    array(
        'eventname' => '\mod_workshop\event\assessable_uploaded',
        'callback' => 'plagiarism_originality_observer::workshop_file_uploaded'
    ),
    array(
        'eventname' => '\mod_forum\event\assessable_uploaded',
        'callback' => 'plagiarism_originality_observer::forum_file_uploaded'
    ),
    array(
        'eventname' => '\assignsubmission_onlinetext\event\assessable_uploaded',
        'callback' => 'plagiarism_originality_observer::assignsubmission_onlinetext_uploaded'
    ),
    array(
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => 'plagiarism_originality_observer::assignsubmission_submitted'
    ),
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => 'plagiarism_originality_observer::quiz_submitted'
    )
);

global $CFG; // Not sure if global CFG is actually needed here but just in case.
if (file_exists($CFG->dirroot.'/mod/hsuforum/version.php')) {
    $observers[] = array(
        'eventname' => '\mod_hsuforum\event\assessable_uploaded',
        'callback' => 'plagiarism_originality_observer::hsuforum_file_uploaded'
    );
}
