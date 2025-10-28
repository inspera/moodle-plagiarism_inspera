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

namespace plagiarism_originality\output;

defined('MOODLE_INTERNAL') || die();

use plugin_renderer_base;
use html_writer;

class renderer extends plugin_renderer_base {

    /**
     * Render the originality reports for a user and assignment.
     *
     * @param array $files Array of stdClass rows from plagiarism_originality_subs
     * @return string HTML output
     */
    public function render_reports_table(array $files) {
        if (empty($files)) {
            return html_writer::tag('p', 'No originality reports found.');
        }

        $table = new html_table();
        $table->head = ['ID', 'Status', 'Similarity', 'Translation', 'AI Index', 'Originality', 'Actions'];
        $table->data = [];

        foreach ($files as $file) {
            $statusbadge = $this->status_badge($file->status);
            $similarity = isset($file->similarity) ? $file->similarity . '%' : '-';
            $translation = isset($file->translation_similarity) ? $file->translation_similarity . '%' : '-';
            $aiindex = $file->ai_index ?? '-';
            $originality = $this->originality_badge($file->originality);

            $actions = '';
            if (!empty($file->externalid)) {
                $reporturl = "https://your-originality-service.com/document/{$file->externalid}/view";
                $actions = html_writer::link($reporturl, 'View Report', ['target' => '_blank']);
            }

            $table->data[] = [
                $file->id,
                $statusbadge,
                $similarity,
                $translation,
                $aiindex,
                $originality,
                $actions
            ];
        }

        return html_writer::table($table);
    }

    protected function status_badge(string $status) {
        $classes = [
            'report_requested' => 'badge-info',
            'pending'          => 'badge-warning',
            'finished'         => 'badge-success',
            'error'            => 'badge-danger',
            'external_error'   => 'badge-danger'
        ];
        $class = $classes[$status] ?? 'badge-secondary';
        return html_writer::tag('span', $status, ['class' => "badge $class"]);
    }

    protected function originality_badge(?string $level) {
        $classes = [
            'High Risk' => 'badge-danger',
            'Medium Risk' => 'badge-warning',
            'Low Risk' => 'badge-success',
            'Clean' => 'badge-primary'
        ];
        $class = $classes[$level] ?? 'badge-secondary';
        return html_writer::tag('span', $level ?? '-', ['class' => "badge $class"]);
    }
}
