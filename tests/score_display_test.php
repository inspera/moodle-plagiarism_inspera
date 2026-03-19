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

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use ReflectionMethod; // THIS FIXES THE ERROR.
use stdClass;

global $CFG;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * PHPUnit tests for the originality_display_type score selection logic.
 *
 * @package    plagiarism_inspera
 * @category   test
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class score_display_test extends advanced_testcase {
    /** @var plagiarism_plugin_inspera */
    private $plugin;

    /** @var ReflectionMethod */
    private $getoriginalitystatus;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->plugin = new \plagiarism_plugin_inspera();

        $this->getoriginalitystatus = new ReflectionMethod(
            \plagiarism_plugin_inspera::class,
            'get_originality_status'
        );
        $this->getoriginalitystatus->setAccessible(true);
    }

    /**
     * Build a minimal record object that satisfies get_originality_status().
     *
     * @param int        $cmid             Course-module ID stored in the config table.
     * @param float      $similarity       Similarity percentage.
     * @param float|null $originalityscore Originality percentage, or NULL for legacy rows.
     * @return stdClass
     */
    private function make_sub_record(int $cmid, float $similarity, ?float $originalityscore): stdClass {
        $record = new stdClass();
        // Use a high integer for the record ID so the redirect URL is deterministic.
        // This value is never inserted into the DB and cannot conflict with real records.
        $record->id               = 99999;
        $record->cm               = $cmid;
        $record->status           = 'finished';
        $record->similarity       = $similarity;
        $record->originality_score = $originalityscore;
        $record->originality      = 'Low'; // Determines risk CSS class.
        return $record;
    }

    /**
     * Test: when displaytype = similarity the similarity score is rendered.
     * @covers \plagiarism_plugin_inspera::get_originality_status
     */
    public function test_score_display_similarity_type(): void {
        $cmid = 6001; // Unique cmid avoids static-cache collision with other tests.

        $record = $this->make_sub_record($cmid, 45.0, 78.0);
        $html = $this->getoriginalitystatus->invoke($this->plugin, $record, 'similarity');

        $this->assertStringContainsString(
            '45%',
            $html,
            'Expected similarity score (45%) in the rendered output.'
        );
        $this->assertStringNotContainsString(
            '78%',
            $html,
            'Originality score (78%) must not appear when displaytype is similarity.'
        );
    }

    /**
     * Test: when displaytype = originality and originality_score is present.
     * @covers \plagiarism_plugin_inspera::get_originality_status
     */
    public function test_score_display_originality_type_with_score(): void {
        $cmid = 6002;

        $record = $this->make_sub_record($cmid, 45.0, 78.0);
        $html = $this->getoriginalitystatus->invoke($this->plugin, $record, 'originality');

        $this->assertStringContainsString(
            '78%',
            $html,
            'Expected originality score (78%) in the rendered output.'
        );
        $this->assertStringNotContainsString(
            '45%',
            $html,
            'Similarity score (45%) must not appear when displaytype is originality and originality_score is set.'
        );
    }

    /**
     * Test: fallback to similarity when originality_score is NULL.
     * @covers \plagiarism_plugin_inspera::get_originality_status
     */
    public function test_score_display_originality_type_null_fallback(): void {
        $cmid = 6003;

        // NULL originality_score simulates a legacy submission that pre-dates the column.
        $record = $this->make_sub_record($cmid, 33.0, null);
        $html = $this->getoriginalitystatus->invoke($this->plugin, $record, 'originality');

        $this->assertStringContainsString(
            '33%',
            $html,
            'Expected similarity score (33%) as fallback when originality_score is NULL.'
        );
    }
}
