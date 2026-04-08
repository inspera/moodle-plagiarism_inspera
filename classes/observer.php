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
 * Event observer definitions for the Inspera Originality plagiarism plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * The event observer class for the originality plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 */
class observer {
    /**
     * Observer function to handle the assessable_uploaded event in mod_assign.
     * @param \assignsubmission_file\event\assessable_uploaded $event
     */
    public static function assignsubmission_file_uploaded(
        \assignsubmission_file\event\assessable_uploaded $event
    ) {
        if (!empty(get_config('plagiarism_inspera', 'enable_mod_assign'))) {
            $eventdata = $event->get_data();
            $eventdata['eventtype'] = 'assignsubmission_file_uploaded';
            $originality = new \plagiarism_plugin_inspera();
            $originality->event_handler($eventdata);
        }
    }

    /**
     * Observer function to handle the assessable_uploaded event in mod_assign onlinetext.
     * @param \assignsubmission_onlinetext\event\assessable_uploaded $event
     */
    public static function assignsubmission_onlinetext_uploaded(
        \assignsubmission_onlinetext\event\assessable_uploaded $event
    ) {
        if (!empty(get_config('plagiarism_inspera', 'enable_mod_assign'))) {
            $eventdata = $event->get_data();
            $eventdata['eventtype'] = 'assignsubmission_onlinetext_uploaded';
            $originality = new \plagiarism_plugin_inspera();
            $originality->event_handler($eventdata);
        }
    }

    /**
     * Observer function to handle the assessable_submitted event in mod_assign.
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function assignsubmission_submitted(
        \mod_assign\event\assessable_submitted $event
    ) {
        if (!empty(get_config('plagiarism_inspera', 'enable_mod_assign'))) {
            $eventdata = $event->get_data();
            $eventdata['eventtype'] = 'assignsubmission_submitted';
            $originality = new \plagiarism_plugin_inspera();
            $originality->event_handler($eventdata);
        }
    }

    /**
     * Observer function to handle the attempt_submitted event in mod_quiz.
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_submitted(\mod_quiz\event\attempt_submitted $event) {
        if (!empty(get_config('plagiarism_inspera', 'enable_mod_quiz'))) {
            $eventdata = $event->get_data();
            $eventdata['eventtype'] = 'quiz_submitted';
            $originality = new \plagiarism_plugin_inspera();
            $originality->event_handler($eventdata);
        }
    }

    /**
     * Observer function to handle the assessable_uploaded event in mod_forum.
     * @param \mod_forum\event\assessable_uploaded $event
     */
    public static function forum_file_uploaded(
        \mod_forum\event\assessable_uploaded $event
    ) {
        if (!empty(get_config('plagiarism_inspera', 'enable_mod_forum'))) {
            $eventdata = $event->get_data();
            $eventdata['eventtype'] = 'forum_file_uploaded';
            $originality = new \plagiarism_plugin_inspera();
            $originality->event_handler($eventdata);
        }
    }

    /**
     * Observer function to handle the assessable_uploaded event in mod_hsuforum.
     * @param \mod_hsuforum\event\assessable_uploaded $event
     */
    public static function hsuforum_file_uploaded(
        \mod_hsuforum\event\assessable_uploaded $event
    ) {
        if (!empty(get_config('plagiarism_inspera', 'enable_mod_hsuforum'))) {
            $eventdata = $event->get_data();
            $eventdata['eventtype'] = 'hsuforum_file_uploaded';
            $originality = new \plagiarism_plugin_inspera();
            $originality->event_handler($eventdata);
        }
    }

    /**
     * Observer to handle the workshop phase switch.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function workshop_phase_switched(\core\event\base $event): void {
        global $DB;

        // Check if plugin is globally enabled for workshops before doing any logic.
        if (empty(get_config('plagiarism_inspera', 'enable_mod_workshop'))) {
            return;
        }

        $newphase = $event->other['workshopphase'] ?? null;
        $workshopid = (int) $event->objectid;
        $cmid = (int) $event->contextinstanceid;

        // Only proceed if the new phase is 30 (PHASE_ASSESSMENT).
        if ($newphase == 30) {
            $queueservice = new \plagiarism_inspera\services\queue_service($DB);
            $workshopservice = new \plagiarism_inspera\services\workshop_service($DB, $queueservice);
            $workshopservice->process_phase_switch($workshopid, $cmid);
        }
    }

    /**
     * Observer to handle late submissions in the workshop Assessment phase.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function workshop_assessable_uploaded(\core\event\base $event): void {
        global $DB;

        if (empty(get_config('plagiarism_inspera', 'enable_mod_workshop'))) {
            return;
        }

        $submissionid = (int) $event->objectid;
        $cmid = (int) $event->contextinstanceid;

        // Fallback logic for workshopid.
        $workshopid = $event->other['workshopid'] ?? $event->other['instanceid'] ?? 0;

        // If still 0, look it up via the Submission record.
        if (!$workshopid && $submissionid) {
            $workshopid = $DB->get_field('workshop_submissions', 'workshopid', ['id' => $submissionid]);
        }

        if (!$workshopid) {
            return;
        }

        $workshop = $DB->get_record('workshop', ['id' => $workshopid], 'id, phase');

        if ($workshop && (int)$workshop->phase === 30) {
            $queueservice = new \plagiarism_inspera\services\queue_service($DB);
            $workshopservice = new \plagiarism_inspera\services\workshop_service($DB, $queueservice);
            $workshopservice->process_late_submission((int)$workshopid, $cmid, $submissionid);
        }
    }
}
