<?php

defined('MOODLE_INTERNAL') || die();

$strplagiarism = get_string('originality', 'plagiarism_originality');
$strplagiarismdefaults = get_string('defaults', 'plagiarism_originality');
//$strplagiarismdebug = get_string('originalitydebug', 'plagiarism_originality');

$tabs = array();
$tabs[] = new tabobject('originalitysettings', 'settings.php', $strplagiarism, $strplagiarism, false);
$tabs[] = new tabobject('originalitydefaults', 'originality_defaults.php', $strplagiarismdefaults, $strplagiarismdefaults, false);
//$tabs[] = new tabobject('originalitydebug', 'originality_debug.php', $strplagiarismdebug, $strplagiarismdebug, false);
print_tabs(array($tabs), $currenttab);
