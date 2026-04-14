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
        // 0. Early exit if Quiz support is disabled or the question type is unsupported.
        if (!$this->should_process_quiz_link($linkarray)) {
            return '';
        }

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

        // 4. Determine Grader Status using the centralized capability map.
        global $CFG;
        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

        $gradecapabilities = plagiarism_inspera_get_grade_capabilities();

        // If the module isn't in our supported capability map, exit early.
        if (!isset($gradecapabilities[$cm->modname])) {
            return '';
        }

        $cmcontext = \context_module::instance($cmid);
        $isgrader = has_capability($gradecapabilities[$cm->modname], $cmcontext);

        // 5. Route to the correct dedicated Handler Service!
        $handler = $this->get_handler($cm->modname);

        if ($handler) {
            return $handler->get_links($linkarray, $plagiarismvalues, $isgrader);
        }

        return '';
    }

    /**
     * Checks whether a quiz-related display request should be processed.
     *
     * @param array $linkarray The raw link data provided by Moodle.
     * @return bool True when the request should proceed.
     */
    private function should_process_quiz_link(array $linkarray): bool {
        global $CFG;
        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

        $component = $linkarray['component'] ?? '';
        $isqtypecomponent = strpos($component, 'qtype_') === 0;
        $isquizcomponent = $component === 'mod_quiz';

        if (!$isquizcomponent && !$isqtypecomponent) {
            return true;
        }

        if (!get_config('plagiarism_inspera', 'enable_mod_quiz')) {
            return false;
        }

        if ($isqtypecomponent) {
            // Strip 'qtype_' prefix (6 chars) to match the values returned by supported_qtypes().
            $qtype = substr($component, 6);
            return in_array($qtype, plagiarism_inspera_supported_qtypes(), true);
        }

        return true;
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
        if (!empty($linkarray['userid']) && !empty($linkarray['content'])) {
            return;
        }

        $record = $this->resolve_question_attempt_record($linkarray);
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
     * Resolve the question attempt record from a Moodle plagiarism link payload.
     *
     * Supports both explicit question attempt ids and the common qtype_* payload
     * shape where area is the question usage id and itemid is the slot.
     *
     * @param array $linkarray The raw link data.
     * @return \stdClass|false
     */
    private function resolve_question_attempt_record(array $linkarray) {
        $sql = "SELECT qa.id,
                       qa.responsesummary,
                       qa.questionusageid,
                       quiza.userid,
                       quiza.quiz
                  FROM {question_attempts} qa
             LEFT JOIN {quiz_attempts} quiza
                    ON quiza.uniqueid = qa.questionusageid";

        $questionattemptid = $this->extract_question_attempt_id($linkarray);
        if ($questionattemptid) {
            return $this->db->get_record_sql(
                $sql . " WHERE qa.id = :questionattemptid",
                ['questionattemptid' => $questionattemptid]
            );
        }

        if (!empty($linkarray['area']) && !empty($linkarray['itemid'])) {
            return $this->db->get_record_sql(
                $sql . " WHERE qa.questionusageid = :usageid
                           AND qa.slot = :slot",
                [
                    'usageid' => (int)$linkarray['area'],
                    'slot' => (int)$linkarray['itemid'],
                ]
            );
        }

        return false;
    }

    /**
     * Extract a question attempt id from a Moodle plagiarism link payload.
     *
     * @param array $linkarray The raw link data.
     * @return int
     */
    private function extract_question_attempt_id(array $linkarray): int {
        $candidates = ['questionattemptid', 'questionattempt', 'qaid'];
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

            if (!empty($linkarray['component']) && strpos($linkarray['component'], 'qtype_') === 0) {
                global $CFG;
                require_once($CFG->dirroot . '/question/engine/lib.php');

                if (empty($linkarray['cmid']) && !empty($linkarray['area'])) {
                    try {
                        $quba = \question_engine::load_questions_usage_by_activity($linkarray['area']);
                        $context = $quba->get_owning_context();
                        if ($context->contextlevel == CONTEXT_MODULE) {
                            $linkarray['cmid'] = $context->instanceid;
                        }
                    } catch (\Exception $e) {
                        // If the usage ID is invalid, missing, or expired, fail gracefully.
                        // The cmid remains unset, and generate_links() will cleanly exit.
                        debugging("INSPERA ERROR: Failed to load question usage in display_manager. Message: " .
                            $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }
        }
    }
}
