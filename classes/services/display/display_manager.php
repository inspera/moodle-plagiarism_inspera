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
        // 1. We must resolve Quiz cmid early if it's hidden inside a question attempt.
        $this->resolve_quiz_cmid($linkarray);

        if (empty($linkarray['cmid'])) {
            return '';
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
