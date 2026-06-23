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
 * Formatter service for plagiarism report display.
 *
 * This class is responsible for taking raw plagiarism submission records
 * and transforming them into a data context suitable for rendering
 * via Mustache templates.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_formatter {
    /**
     * Generates HTML for a plagiarism report link/status using a Mustache template.
     *
     * @param \stdClass $record The plagiarism submission record
     * @param string $displaytype similarity|originality Display type of the score to show
     * @return string HTML output
     */
    public function get_originality_status(\stdClass $record, string $displaytype = 'similarity'): string {
        global $OUTPUT, $PAGE;

        // 1. Establish the base data context.
        $context = [
            'wrapperclass' => 'plagiarism-originality-status',
            'isfinished' => false,
            'isrequested' => false,
            'ispending' => false,
            'iserror' => false,
            'ispolling' => false,
            'canresubmit' => false,
            'resubmiturl' => null,
            'resubmitcmid' => null,
            'resubmitreturnurl' => null,
            'resubmitsesskey' => null,
            'resubmiticonhtml' => null,
            'id' => $record->id,
            'status' => $record->status,
            'displaytype' => $displaytype,
        ];

        // 2. Populate data based on the status.
        switch ($record->status) {
            case 'finished':
                $context['isfinished'] = true;
                $context['wrapperclass'] = 'plagiarism-originality-reportlink';

                $url = new \moodle_url('/plagiarism/inspera/redirect.php', ['id' => $record->id]);
                $context['url'] = $url->out(false);

                // Score calculation logic with defensive property checks.
                // Safely extract the originality text, falling back to an empty string if null/missing.
                $originality = trim((string)($record->originality ?? ''));

                if (
                    $displaytype === 'originality' &&
                    property_exists($record, 'originality_score') &&
                    $record->originality_score !== null
                ) {
                    $scorevalue = $record->originality_score;
                    $score = (int)round((float)$scorevalue);

                    // Derive risk class from text if available, otherwise synthesize from the score.
                    if ($originality !== '') {
                        $riskclass = strtolower(explode(' ', $originality)[0]);
                    } else if ($score <= 20) {
                        $riskclass = 'low';
                    } else if ($score <= 80) {
                        $riskclass = 'medium';
                    } else {
                        $riskclass = 'high';
                    }
                } else {
                    // Fallback to similarity.
                    $scorevalue = property_exists($record, 'similarity') ? $record->similarity : 0;
                    $score = (int)round((float)$scorevalue);

                    if ($score <= 20) {
                        $riskclass = 'low';
                    } else if ($score <= 80) {
                        $riskclass = 'medium';
                    } else {
                        $riskclass = 'high';
                    }
                }

                $context['riskclass'] = $riskclass;
                $context['linkprefix'] = get_string('reportlinkprefix', 'plagiarism_inspera');
                $context['scoretext'] = get_string('reportlinkscore', 'plagiarism_inspera', $score);

                $context['iconhtml'] = $OUTPUT->pix_icon(
                    'logo',
                    $context['linkprefix'],
                    'plagiarism_inspera',
                    ['class' => 'originality-logo-icon']
                );
                break;

            case 'report_requested':
            case 'pending':
                $context[$record->status === 'pending' ? 'ispending' : 'isrequested'] = true;
                $context['statustext'] = get_string('status' .
                    ($record->status === 'pending' ? 'pending' : 'requested'), 'plagiarism_inspera');

                $context['ispolling'] = true;
                break;

            case 'error':
            case 'external_error':
            case 'fatal_error':
                $context['iserror'] = true;
                $context['wrapperclass'] .= ' error';

                $cmid = !empty($record->cm) ? (int)$record->cm : 0;
                $resubmitcontext = null;

                // Only trust $PAGE->context when it matches the record CM.
                if ($PAGE->context instanceof \context_module && (int)$PAGE->context->instanceid === $cmid) {
                    $resubmitcontext = $PAGE->context;
                } else if ($cmid > 0) {
                    $resubmitcontext = \context_module::instance($cmid, IGNORE_MISSING);
                }

                global $CFG;
                require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');
                $gradecapabilities = plagiarism_inspera_get_grade_capabilities();

                // Explicitly fetch the course module to guarantee we have the correct modname.
                $cm = ($cmid > 0) ? get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING) : null;

                $canrequestallreports = $resubmitcontext &&
                    has_capability('plagiarism/inspera:requestallreports', $resubmitcontext);

                $cangrade = $cm && $resubmitcontext &&
                    isset($gradecapabilities[$cm->modname]) &&
                    has_capability($gradecapabilities[$cm->modname], $resubmitcontext);

                $context['canresubmit'] = ($cmid > 0) &&
                    $canrequestallreports &&
                    $cangrade &&
                    in_array($record->status, ['error', 'external_error'], true);

                if ($context['canresubmit']) {
                    $context['resubmiturl'] = (new \moodle_url('/plagiarism/inspera/resubmit.php'))->out(false);
                    $context['resubmitcmid'] = $cmid;

                    // Defensively fall back to the site root if $PAGE->url is not initialized.
                    // We must use out_as_local_url() because resubmit.php expects a PARAM_LOCALURL.
                    $context['resubmitreturnurl'] = (method_exists($PAGE, 'has_set_url') && $PAGE->has_set_url())
                        ? $PAGE->url->out_as_local_url(false)
                        : (new \moodle_url('/'))->out_as_local_url(false);
                    $context['resubmitsesskey'] = sesskey();
                    $context['resubmiticonhtml'] = $OUTPUT->pix_icon('t/reload', get_string('resubmit', 'plagiarism_inspera'));
                }

                // Use the specific fatal error string, or fall back to the generic error string.
                if ($record->status === 'fatal_error') {
                    $context['statustext'] = get_string('status_fatal_error', 'plagiarism_inspera');
                } else {
                    $context['statustext'] = get_string('statuserror', 'plagiarism_inspera');
                }

                if (!empty($record->description)) {
                    $context['hasdescription'] = true;
                    $rawdescription = (string)$record->description;

                    // Do not use s() here; Mustache safely escapes standard {{ }} variables.
                    $context['rawdescription'] = $rawdescription;
                    $context['shortdescription'] = shorten_text($rawdescription, 200, true);
                }
                break;

            default:
                // Unknown status, render nothing.
                return '';
        }

        // 3. Render the Mustache template!
        return $OUTPUT->render_from_template('plagiarism_inspera/report_status', $context);
    }
}
