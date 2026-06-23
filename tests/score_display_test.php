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

namespace plagiarism_inspera;

use advanced_testcase;
use stdClass;
use plagiarism_inspera\services\display\report_formatter;

/**
 * PHPUnit tests for the score selection logic within the report_formatter.
 *
 * @package    plagiarism_inspera
 * @category   test
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class score_display_test extends advanced_testcase {
    /** @var report_formatter */
    private $formatter;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Instantiate our new service.
        $this->formatter = new report_formatter();
    }

    /**
     * Build a minimal record object that satisfies get_originality_status().
     */
    private function make_sub_record(int $cmid, float $similarity, ?float $originalityscore): stdClass {
        $record = new stdClass();
        // FIX: Ensure ID is present as the new formatter uses it for polling attributes.
        $record->id               = 99999;
        $record->cm               = $cmid;
        $record->status           = 'finished';
        $record->similarity       = $similarity;
        $record->originality_score = $originalityscore;
        $record->originality      = 'Low';
        return $record;
    }

    /**
     * Test: when displaytype = similarity the similarity score is rendered.
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_score_display_similarity_type(): void {
        $cmid = 6001;
        $record = $this->make_sub_record($cmid, 45.0, 78.0);

        // Call the public method directly.
        $html = $this->formatter->get_originality_status($record, 'similarity');

        $this->assertStringContainsString('45%', $html);
        $this->assertStringNotContainsString('78%', $html);
    }

    /**
     * Test: when displaytype = originality and originality_score is present.
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_score_display_originality_type_with_score(): void {
        $cmid = 6002;
        $record = $this->make_sub_record($cmid, 45.0, 78.0);

        $html = $this->formatter->get_originality_status($record, 'originality');

        $this->assertStringContainsString('78%', $html);
        $this->assertStringNotContainsString('45%', $html);
    }

    /**
     * Test: fallback to similarity when originality_score is NULL.
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_score_display_originality_type_null_fallback(): void {
        $cmid = 6003;
        $record = $this->make_sub_record($cmid, 33.0, null);

        $html = $this->formatter->get_originality_status($record, 'originality');

        $this->assertStringContainsString('33%', $html);
    }

    /**
     * Test the color range logic when displaytype is similarity.
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     * @dataProvider similarity_range_provider
     */
    public function test_similarity_color_ranges(float $score, string $expectedclass): void {
        $cmid = 700000 + (int)round($score * 100);
        $record = $this->make_sub_record($cmid, $score, null);

        $html = $this->formatter->get_originality_status($record, 'similarity');

        $this->assertStringContainsString("originality-score $expectedclass", $html);
    }

    /**
     * Data provider for similarity range testing.
     *
     * @return array
     */
    public static function similarity_range_provider(): array {
        return [
            'Boundary Low (20.0)'          => [20.0, 'low'],
            'Coherence check (20.49)'      => [20.49, 'low'],
            'Coherence check (20.5)'       => [20.5, 'medium'],
            'Boundary Medium (80.0)'       => [80.0, 'medium'],
            'Coherence check (80.49)'      => [80.49, 'medium'],
            'Coherence check (80.5)'       => [80.5, 'high'],
            'Boundary High (100)'          => [100.0, 'high'],
        ];
    }

    /**
     * Test the color logic when displaytype is originality.
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     */
    public function test_originality_color_logic(): void {
        $cmid = 8000;
        $record = $this->make_sub_record($cmid, 10.0, 99.0);
        $record->originality = 'Low risk';

        $html = $this->formatter->get_originality_status($record, 'originality');

        $this->assertStringContainsString('originality-score low', $html);
    }

    /**
     * Test the color range logic for the originality fallback path.
     * @covers \plagiarism_inspera\services\display\report_formatter::get_originality_status
     * @dataProvider originality_fallback_range_provider
     */
    public function test_originality_fallback_color_ranges(float $similarity, string $expectedclass): void {
        $cmid = 900000 + (int)round($similarity * 100);
        $record = $this->make_sub_record($cmid, $similarity, null);

        $html = $this->formatter->get_originality_status($record, 'originality');

        $this->assertStringContainsString("originality-score $expectedclass", $html);
    }

    /**
     * Data provider for originality fallback range testing.
     *
     * @return array
     */
    public static function originality_fallback_range_provider(): array {
        return [
            'Fallback boundary Low (20.0)'     => [20.0, 'low'],
            'Fallback coherence check (20.49)' => [20.49, 'low'],
            'Fallback coherence check (20.5)'  => [20.5, 'medium'],
            'Fallback boundary Medium (80.0)'  => [80.0, 'medium'],
            'Fallback coherence check (80.49)' => [80.49, 'medium'],
            'Fallback coherence check (80.5)'  => [80.5, 'high'],
            'Fallback boundary High (100)'     => [100.0, 'high'],
        ];
    }
}
