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
 * PHPUnit tests for the get_submission_status class.
 *
 * @package     plagiarism_inspera
 * @category    test
 * @copyright   2026 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\external;

use advanced_testcase;

/**
 * Unit tests for the get_submission_status external API.
 *
 * @package    plagiarism_inspera
 * @category   test
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_inspera\external\get_submission_status
 */
final class get_submission_status_test extends advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass The test teacher (grader). */
    private $teacher;

    /** @var \stdClass The test student (owner). */
    private $student;

    /** @var \stdClass Another test student (peer/attacker). */
    private $otherstudent;

    /** @var \stdClass The test assignment module. */
    private $cm;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

        // Create standard test environment.
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->otherstudent = $generator->create_and_enrol($this->course, 'student');

        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id);
    }

    /**
     * Helper to create a fake plagiarism record for the given user.
     */
    private function create_submission_record(int $userid): \stdClass {
        global $DB;
        $record = new \stdClass();
        $record->cm = $this->cm->id;
        $record->userid = $userid;
        $record->status = 'finished';
        $record->similarity = 45;
        $record->timecreated = time();
        $record->id = $DB->insert_record('plagiarism_inspera_subs', $record);
        return $record;
    }

    /**
     * Helper to mock the student visibility configuration for the module.
     */
    private function set_student_visibility(bool $isvisible): void {
        global $DB;
        $DB->delete_records('plagiarism_inspera_config', [
            'cm' => $this->cm->id,
            'name' => 'originality_show_student_report',
        ]);
        $DB->insert_record('plagiarism_inspera_config', [
            'cm' => $this->cm->id,
            'name' => 'originality_show_student_report',
            'value' => $isvisible ? '1' : '0',
        ]);
    }

    /**
     * Scenario 1: A Grader (Teacher) requests a student's report.
     * Expectation: Allowed unconditionally.
     *
     * @covers ::execute
     * @covers ::can_view_submission_status
     */
    public function test_execute_grader_can_view_any_submission(): void {
        $record = $this->create_submission_record($this->student->id);

        // Even if student visibility is off, the teacher should see it.
        $this->set_student_visibility(false);
        $this->setUser($this->teacher);

        $result = get_submission_status::execute($record->id, 'similarity');

        $this->assertEquals('finished', $result['status']);
        $this->assertStringContainsString('45', $result['html']);
    }

    /**
     * Scenario 2: A Student requests their OWN report, and settings ALLOW it.
     * Expectation: Allowed.
     *
     * @covers ::execute
     * @covers ::can_view_submission_status
     */
    public function test_execute_owner_can_view_when_allowed(): void {
        $record = $this->create_submission_record($this->student->id);

        $this->set_student_visibility(true);
        $this->setUser($this->student);

        $result = get_submission_status::execute($record->id, 'similarity');

        $this->assertEquals('finished', $result['status']);
        $this->assertStringContainsString('45', $result['html']);
    }

    /**
     * Scenario 3: A Student requests their OWN report, but settings DENY it.
     * Expectation: Exception thrown.
     *
     * @covers ::execute
     * @covers ::can_view_submission_status
     */
    public function test_execute_owner_cannot_view_when_denied(): void {
        $record = $this->create_submission_record($this->student->id);

        $this->set_student_visibility(false);
        $this->setUser($this->student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('nopermissions', 'error'));

        get_submission_status::execute($record->id, 'similarity');
    }

    /**
     * Scenario 4: A Student requests ANOTHER student's report (IDOR attempt).
     * Expectation: Exception thrown.
     *
     * @covers ::execute
     * @covers ::can_view_submission_status
     */
    public function test_execute_non_owner_access_denied(): void {
        $record = $this->create_submission_record($this->student->id);

        // Set visibility to true so we prove it's the IDOR check failing, not the visibility check.
        $this->set_student_visibility(true);
        $this->setUser($this->otherstudent);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('nopermissions', 'error'));

        get_submission_status::execute($record->id, 'similarity');
    }

    /**
     * Scenario 5: User requests a record ID that does not exist.
     * Expectation: Generic exception thrown to prevent ID enumeration.
     *
     * @covers ::execute
     * @covers ::can_view_submission_status
     */
    public function test_execute_missing_record_throws_generic_error(): void {
        $this->setUser($this->teacher);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('nopermissions', 'error'));

        get_submission_status::execute(999999, 'similarity');
    }
}
