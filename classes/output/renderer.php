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
 * Renderer for Plagiarism Inspera.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\output;

use plugin_renderer_base;

/**
 * The primary renderer for the plagiarism_inspera plugin.
 *
 * Handles the high-level output orchestration for the plugin.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the main debug page.
     *
     * @param debug_page $page The page renderable.
     * @return string The rendered HTML.
     */
    public function render_debug_page(debug_page $page): string {
        $output = $this->header();

        // Handle tabs via the legacy file, but encapsulated in the renderer.
        $currenttab = 'originalitydebug';
        ob_start();
        include(__DIR__ . '/../../originality_tabs.php');
        $output .= ob_get_contents();
        ob_end_clean();

        $output .= $this->heading(get_string('originalityfiles', 'plagiarism_inspera'));

        // Render the mustache template.
        $output .= $this->render_from_template('plagiarism_inspera/debug_page', $page->export_for_template($this));

        $output .= $this->footer();

        return $output;
    }

    /**
     * Render a standard Moodle confirmation screen for bulk actions.
     *
     * @param string $message The message to display.
     * @param \moodle_url $continue The URL to continue.
     * @param \moodle_url $cancel The URL to cancel.
     * @return string The rendered HTML.
     */
    public function render_bulk_confirmation(string $message, \moodle_url $continue, \moodle_url $cancel): string {
        $output = $this->header();
        $output .= $this->confirm($message, $continue, $cancel);
        $output .= $this->footer();
        return $output;
    }
}
