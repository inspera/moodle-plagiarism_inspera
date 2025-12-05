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
 * Scheduled task to clean up orphaned originality records.
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_originality\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

/**
 * Scheduled task to delete orphaned originality submission records.
 *
 * This task cleans up records where:
 * 1. The associated Moodle file has been deleted
 * 2. Temporary files for online text are old and no longer needed
 *
 * @package    plagiarism_originality
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_orphaned_files extends scheduled_task {

    /**
     * Returns the name of this task (shown in admin screens)
     *
     * @return string
     */
    public function get_name() {
        return get_string('deleteorphanedfiles', 'plagiarism_originality');
    }

    /**
     * Execute task.
     */
    public function execute() {
        require_once(__DIR__ . '/../../lib.php');

        $cleaned = plagiarism_originality_cleanup_orphaned_records();
        if ($cleaned > 0) {
            mtrace("Cleaned up {$cleaned} orphaned records");
        } else {
            mtrace("No orphaned records found");
        }
    }
}