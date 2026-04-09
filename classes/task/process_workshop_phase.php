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
 * Ad-hoc task to process workshop submissions asynchronously.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\task;

/**
 * Ad-hoc task to process workshop submissions asynchronously.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_workshop_phase extends \core\task\adhoc_task {
    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        // Retrieve the data passed from the observer.
        $data = $this->get_custom_data();

        if (empty($data->workshopid) || empty($data->cmid)) {
            return;
        }

        $cmid = (int)$data->cmid;
        $workshopid = (int)$data->workshopid;

        // 1. Guard: Was the workshop deleted while this task was waiting in the queue?
        if (!get_coursemodule_from_id('workshop', $cmid, 0, false, IGNORE_MISSING)) {
            return; // Silently drop the task.
        }

        // 2. Guard: Did the teacher disable the plugin for this activity before the task ran?
        $useoriginality = $DB->get_field(
            'plagiarism_inspera_config',
            'value',
            [
                'cm' => $cmid,
                'name' => 'use_originality',
            ]
        );

        if (empty($useoriginality)) {
            return; // Silently drop the task.
        }

        // Call the service exactly as we did before.
        $queueservice = new \plagiarism_inspera\services\queue_service($DB);
        $workshopservice = new \plagiarism_inspera\services\workshop_service($DB, $queueservice);

        $workshopservice->process_phase_switch($workshopid, $cmid);
    }
}
