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
 * Handles the display of Inspera originality reports.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\services\display;

defined('MOODLE_INTERNAL') || die();

/**
 * Manager class for routing plagiarism display requests.
 *
 * This class acts as a central orchestrator to resolve the current
 * activity context and delegate the link generation to the
 * appropriate module-specific strategy handler.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_manager {
    /** @var \moodle_database The Moodle database object. */
    private $db;

    /** @var report_formatter The report formatting service. */
    private $formatter;

    /** @var array Cache for plagiarism configuration values indexed by cmid. */
    private static array $configcache = [];

    /**
     * Constructor for the display manager.
     *
     * @param \moodle_database $db The Moodle database object.
     */
    public function __construct(\moodle_database $db) {
        $this->db = $db;
        $this->formatter = new report_formatter();
    }

    /**
     * Orchestrates the generation of plagiarism report links.
     *
     * This method resolves the context (especially for Quizzes), loads
     * activity configuration, and routes the request to the appropriate
     * module-specific handler.
     *
     * @param array $linkarray The raw link data provided by Moodle.
     * @return string HTML output to be displayed in the Moodle UI.
     */
    public function generate_links(array $linkarray): string {
        // 1. Resolve missing Quiz/Question-engine fields.
        $this->resolve_quiz_cmid($linkarray);
        $this->resolve_quiz_link_fields($linkarray);

        if (empty($linkarray['cmid'])) {
            return '';
        }

        // Defensive checks for downstream handlers.
        if (!array_key_exists('userid', $linkarray)) {
            $linkarray['userid'] = null;
        }
        if (!array_key_exists('content', $linkarray)) {
            $linkarray['content'] = '';
        }

        $cmid = (int)$linkarray['cmid'];

        // 2. Load plugin config (with static caching).
        if (!isset(self::$configcache[$cmid])) {
            self::$configcache[$cmid] = $this->db->get_records_menu(
                'plagiarism_inspera_config',
                ['cm' => $cmid],
                '',
                'name,value'
            );
        }
        $plagiarismvalues = self::$configcache[$cmid];

        // 3. Resolve the Course Module and determine the module type.
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return '';
        }

        // 4. Determine Grader Status based on module type.
        $cmcontext = \context_module::instance($cmid);
        $isgrader = false;

        if ($cm->modname === 'assign') {
            $isgrader = has_capability('mod/assign:grade', $cmcontext);
        } else if ($cm->modname === 'quiz') {
            $isgrader = has_capability('mod/quiz:grade', $cmcontext);
        } else if ($cm->modname === 'workshop') {
            $isgrader = has_capability('mod/workshop:viewallsubmissions', $cmcontext);
        }

        // 5. Route to the correct dedicated Handler Service!
        $handler = $this->get_handler($cm->modname);

        if ($handler) {
            return $handler->get_links($linkarray, $plagiarismvalues, $isgrader);
        }

        return '';
    }

    /**
     * Resolve missing quiz link fields for question-engine contexts.
     *
     * Moodle callbacks for qtype_* contexts may not include the same payload
     * as standard quiz module callbacks. Backfill userid/content (and cmid as
     * a fallback) from the related question attempt so handlers receive a
     * consistent link structure.
     *
     * @param array $linkarray The link data to enrich.
     * @return void
     */
    private function resolve_quiz_link_fields(array &$linkarray): void {
        // Only skip if we have a valid userid AND a non-empty content string.
        if (
            !empty($linkarray['userid']) &&
            !empty($linkarray['content'])
        ) {
            return;
        }

        $questionattemptid = $this->extract_question_attempt_id($linkarray);
        if (!$questionattemptid) {
            return;
        }

        $sql = "SELECT qa.id,
                       qa.responsesummary,
                       qa.questionusageid,
                       quiza.userid,
                       quiza.quiz
                  FROM {question_attempts} qa
             LEFT JOIN {quiz_attempts} quiza
                    ON quiza.uniqueid = qa.questionusageid
                 WHERE qa.id = :questionattemptid";

        $record = $this->db->get_record_sql($sql, ['questionattemptid' => $questionattemptid]);
        if (!$record) {
            return;
        }

        if (empty($linkarray['userid']) && !empty($record->userid)) {
            $linkarray['userid'] = (int)$record->userid;
        }

        if (!array_key_exists('content', $linkarray) || $linkarray['content'] === null || $linkarray['content'] === '') {
            $linkarray['content'] = (string)($record->responsesummary ?? '');
        }

        if (empty($linkarray['cmid']) && !empty($record->quiz)) {
            $cmid = $this->db->get_field_sql(
                "SELECT cm.id
                   FROM {course_modules} cm
                   JOIN {modules} m
                     ON m.id = cm.module
                  WHERE m.name = :modname
                    AND cm.instance = :instanceid",
                [
                    'modname' => 'quiz',
                    'instanceid' => $record->quiz,
                ]
            );

            if ($cmid) {
                $linkarray['cmid'] = (int)$cmid;
            }
        }
    }

    /**
     * Extract a question attempt id from a Moodle plagiarism link payload.
     *
     * @param array $linkarray The raw link data.
     * @return int
     */
    private function extract_question_attempt_id(array $linkarray): int {
        $candidates = ['questionattemptid', 'questionattempt', 'qaid', 'itemid'];
        foreach ($candidates as $key) {
            if (!empty($linkarray[$key])) {
                return (int)$linkarray[$key];
            }
        }
        return 0;
    }

    /**
     * Factory method to return the correct module handler.
     */
    private function get_handler(string $modname): ?handler_interface {
        switch ($modname) {
            case 'assign':
                return new assign_handler($this->db, $this->formatter);
            case 'quiz':
                return new quiz_handler($this->db, $this->formatter);
            case 'workshop':
                return new workshop_handler($this->db, $this->formatter);
            default:
                return null; // Unsupported module.
        }
    }

    /**
     * Handles the complex edge-case of Quiz Question context resolution.
     */
    private function resolve_quiz_cmid(array &$linkarray): void {
        if (!empty($linkarray['component']) && strpos($linkarray['component'], 'qtype_') === 0) {
            global $CFG;
            require_once($CFG->dirroot . '/question/engine/lib.php');

            if (empty($linkarray['cmid']) && !empty($linkarray['area'])) {
                $quba = \question_engine::load_questions_usage_by_activity($linkarray['area']);
                $context = $quba->get_owning_context();
                if ($context->contextlevel == CONTEXT_MODULE) {
                    $linkarray['cmid'] = $context->instanceid;
                }
            }
        }
    }
}
