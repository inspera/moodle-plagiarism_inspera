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
 * Renderable for the Submissions Management page.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\output;

use renderable;
use templatable;
use renderer_base;
use stdClass;
use moodle_url;

/**
 * Class debug_page
 *
 * Preparer for the originality debug mustache template.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_page implements renderable, templatable {
    /**
     * @var \plagiarism_inspera\output\debug_table The table of submissions.
     */
    protected $table;

    /**
     * @var \plagiarism_inspera\output\filtering The filtering UI object.
     */
    protected $filtering;

    /**
     * @var bool Whether the user is viewing all submissions or just errors.
     */
    protected $prefshowall;

    /**
     * @var string The current active tab.
     */
    protected $currenttab = 'originalitydebug';

    /**
     * debug_page constructor.
     *
     * @param \plagiarism_inspera\output\debug_table $table
     * @param \plagiarism_inspera\output\filtering $filtering
     * @param bool $prefshowall
     */
    public function __construct($table, $filtering, $prefshowall) {
        $this->table = $table;
        $this->filtering = $filtering;
        $this->prefshowall = $prefshowall;
    }

    /**
     * Export data for use in a mustache template.
     *
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output) {
        global $PAGE;

        $data = new stdClass();

        // 1. Filter UI.
        // We capture the output of display_add and display_active.
        ob_start();
        $this->filtering->display_add();
        $this->filtering->display_active();
        $data->filterhtml = ob_get_contents();
        ob_end_clean();

        // 2. Toggle Button Logic.
        $data->is_showing_all = $this->prefshowall;
        $toggleparams = [
            'showall' => $this->prefshowall ? -1 : 1,
            'sesskey' => sesskey(),
        ];
        $toggleurl = new moodle_url($PAGE->url, $toggleparams);
        $data->toggleurl = $toggleurl->out(false);

        if ($this->prefshowall) {
            $data->togglelabel = get_string('toggleviewerrorsonly', 'plagiarism_inspera');
            $data->toggleclass = 'btn-outline-danger';
        } else {
            $data->togglelabel = get_string('toggleviewallsubmissions', 'plagiarism_inspera');
            $data->toggleclass = 'btn-outline-primary';
        }

        // 3. Table UI.
        // Tablelib doesn't use mustache yet, so we capture its raw HTML.
        ob_start();
        $this->table->out(50, false);
        $data->tablehtml = ob_get_contents();
        ob_end_clean();

        // 4. Form Actions.
        $data->sesskey = sesskey();
        $posturl = new moodle_url('/plagiarism/inspera/originality_debug.php');
        $data->posturl = $posturl->out(false);
        $data->resubmitlabel = get_string('resubmitselectedfiles', 'plagiarism_inspera');
        $data->deletelabel = get_string('deleteselectedfiles', 'plagiarism_inspera');

        return $data;
    }
}
