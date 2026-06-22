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
 * Tests for the report formatter service.
 *
 * @category   test
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\display\report_formatter;
use stdClass;

/**
 * Test class for the report formatter.
 *
 * @package    plagiarism_inspera
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_formatter_test extends advanced_testcase {
    /**
     * Test the pending status output.
     *
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_get_originality_status_pending(): void {
        $formatter = new report_formatter();

        $record = new stdClass();
        $record->id = 123;
        $record->status = 'pending';

        $html = $formatter->get_originality_status($record);

        // Assert the output contains the localized pending string.
        $expectedstring = get_string('statuspending', 'plagiarism_inspera');
        $this->assertStringContainsString($expectedstring, $html);
    }

    /**
     * Test a finished report calculating similarity.
     *
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_get_originality_status_finished_similarity(): void {
        $formatter = new report_formatter();

        $record = new stdClass();
        $record->id = 123;
        $record->status = 'finished';
        $record->similarity = 85.5; // High risk (Red).

        $html = $formatter->get_originality_status($record, 'similarity');

        // It should round 85.5 to 86.
        $this->assertStringContainsString('86', $html);
        // It should apply the high-risk CSS class.
        $this->assertStringContainsString('high', $html);
        // It should contain the redirect URL.
        $this->assertStringContainsString('redirect.php?id=123', $html);
    }

    /**
     * Test that an error displays the shortened description.
     *
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    /**
     * Test that an error displays the shortened description.
     *
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_get_originality_status_error(): void {
        global $PAGE;

        // PHPUnit runs in CLI, so we must mock the page URL to prevent debugging notices
        // when the formatter builds the returnurl for the resubmit action.
        $PAGE->set_url(new \moodle_url('/'));

        $formatter = new report_formatter();

        $record = new \stdClass();
        $record->id = 123;
        $record->status = 'error';
        $record->description = 'The Inspera API returned a 500 Internal Server Error.';

        $html = $formatter->get_originality_status($record);

        $this->assertStringContainsString('error', $html);
        $this->assertStringContainsString('The Inspera API returned', $html);

        // Optional but good: Verify the resubmit URL was actually generated!
        $this->assertStringContainsString('resubmit.php', $html);
    }
}
