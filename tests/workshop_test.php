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
use stdClass;

global $CFG;
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * Workshop report visibility tests.
 *
 * @package    plagiarism_inspera
 * @category   test
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workshop_test extends advanced_testcase {
    /** @var stdClass */
    protected $course;
    /** @var stdClass */
    protected $student;
    /** @var stdClass */
    protected $teacher;
    /** @var stdClass */
    protected $workshop;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Use Admin permissions to set up the course and modules.
        $this->setAdminUser();
        global $DB;

        set_config('enableplagiarism', 1);
        set_config('enabled', 1, 'plagiarism_inspera');

        $generator = $this->getDataGenerator();
        $this->course  = $generator->create_course();
        $this->student = $generator->create_user();
        $this->teacher = $generator->create_user();

        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        // Create a Workshop.
        $this->workshop = $generator->create_module('workshop', [
            'course' => $this->course->id,
            'phase'  => 20, // PHASE_SUBMISSION.
        ]);

        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm'    => $this->workshop->cmid,
            'name'  => 'use_originality',
            'value' => '1',
        ]);
    }

    /**
     * Test: Verify report visibility logic for Workshop submissions.
     * @covers ::plagiarism_inspera_should_show_report
     */
    public function test_should_show_report_workshop_logic(): void {
        global $DB;
        $timenow = time();

        // 1. Create a dummy submission directly in the DB (mimicking the 'authorid' quirk).
        $subid = $DB->insert_record('workshop_submissions', [
            'workshopid'   => $this->workshop->id,
            'authorid'     => $this->student->id,
            'timecreated'  => $timenow,
            'timemodified' => $timenow,
            'title'        => 'Test Submission',
        ]);

        // Mock the finished Plagiarism record.
        $record = (object)[
            'cm'     => $this->workshop->cmid,
            'userid' => $this->student->id,
            'status' => 'finished',
        ];

        $this->setUser($this->student);

        // SCENARIO 1: After Grading (Mode 2).
        $settingsgraded = [
            'originality_show_student_report' => 2,
        ];

        // A. Ungraded (Assessment phase, no rubrics filled) -> Should be False.
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->workshop->cmid,
                (int)$this->student->id,
                $settingsgraded,
                $record
            )
        );

        // B. Assessment completed by teacher (timemodified > 0, grade is NULL) -> Should be True.
        $DB->insert_record('workshop_assessments', [
            'submissionid' => $subid,
            'reviewerid'   => $this->teacher->id,
            'weight'       => 1,
            'timecreated'  => $timenow - 60,
            'timemodified' => $timenow,
            'grade'        => null,
        ]);

        $this->assertTrue(
            plagiarism_inspera_should_show_report(
                (int)$this->workshop->cmid,
                (int)$this->student->id,
                $settingsgraded,
                $record
            ),
            'Report should be visible because timemodified > 0 on the assessment.'
        );

        // SCENARIO 2: Due Date (Mode 3).
        $settingsdue = [
            'originality_show_student_report' => 3,
        ];

        // A. Deadline is in the FUTURE -> Should be False.
        $DB->set_field('workshop', 'submissionend', $timenow + 3600, ['id' => $this->workshop->id]);
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->workshop->cmid,
                (int)$this->student->id,
                $settingsdue,
                $record
            )
        );

        // B. Deadline is in the PAST -> Should be True.
        $DB->set_field('workshop', 'submissionend', $timenow - 3600, ['id' => $this->workshop->id]);
        $this->assertTrue(
            plagiarism_inspera_should_show_report(
                (int)$this->workshop->cmid,
                (int)$this->student->id,
                $settingsdue,
                $record
            )
        );
    }
}
