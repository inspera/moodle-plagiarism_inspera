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
use context_user;
use context_module;

// Required for quiz manipulation.
global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * Quiz queuing and report visibility tests.
 *
 * @package    plagiarism_inspera
 * @category   test
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_test extends advanced_testcase {
    /** @var stdClass */
    protected $course;

    /** @var stdClass */
    protected $student;

    /** @var stdClass */
    protected $quiz;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;

        set_config('enableplagiarism', 1);

        // Configure the Inspera plagiarism plugin globally so event_handler() proceeds.
        set_config('enabled', 1, 'plagiarism_inspera');
        set_config('baseurl', 'https://api.example.com', 'plagiarism_inspera');
        set_config('enable_mod_quiz', 1, 'plagiarism_inspera');
        set_config('charcount', 1, 'plagiarism_inspera');

        $this->course  = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        // Create a Quiz.
        $this->quiz = $this->getDataGenerator()->create_module('quiz', [
            'course'    => $this->course->id,
            'gradepass' => 50,
        ]);

        // Explicitly enable Inspera for this quiz in the plugin config table.
        // This ensures every test starts with the module correctly "hooked."
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm'    => $this->quiz->cmid,
            'name'  => 'use_originality',
            'value' => '1',
        ]);
    }

    /**
     * Test: Verify that an essay response in a quiz creates a row in the subs table.
     * @covers ::plagiarism_inspera_quiz_attempt_submitted
     */
    public function test_quiz_essay_queuing(): void {
        global $DB;

        // Quiz & Question Setup.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat   = $questiongenerator->create_question_category();
        $essay = $questiongenerator->create_question('essay', null, ['category' => $cat->id]);

        // Add question to quiz with a 10.0 mark.
        quiz_add_quiz_question($essay->id, $this->quiz, 0, 10.0);

        // Sync grades using the modern Grade Calculator API.
        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $gradecalculator = \mod_quiz\grade_calculator::create($quizobj);
        $gradecalculator->recompute_quiz_sumgrades();
        $gradecalculator->update_quiz_maximum_grade(10.0);

        // Attempt Creation.
        $this->setUser($this->student);
        $timenow = time();

        // Use the data generator, which safely writes the record and bypasses web-only capability checks.
        /** @var mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attemptrecord = $quizgenerator->create_attempt($this->quiz->id, $this->student->id);

        if (!$attemptrecord) {
            $this->fail('Failed to find the quiz attempt in the database.');
        }

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptrecord->id);

        // Submission Logic.
        // We do NOT call start_attempt() here because the Data Generator.
        // Already created the attempt in the 'inprogress' state.

        // Simulate the essay response (Slot 1).
        $tosubmit = [
            1 => [
                'answer'       => 'This is my plagiarized essay content.',
                'answerformat' => FORMAT_HTML,
            ],
        ];
        // Process the typed text into the database.
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish and submit the quiz.
        $attemptobj->process_finish($timenow, false);

        // Reload the attempt record to get the fully populated fields (like 'state' = 'finished').
        $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attemptrecord->id], '*', MUST_EXIST);

        // Trigger Observer Logic.
        // Call your excellent helper function to simulate the event trigger.
        plagiarism_inspera_quiz_attempt_submitted($attemptrecord);

        // Assertions.
        $record = $DB->get_record('plagiarism_inspera_subs', [
            'cm'     => $this->quiz->cmid,
            'userid' => $this->student->id,
        ]);

        $this->assertNotEmpty($record, 'Expected a record in plagiarism_inspera_subs but none was found.');
        $this->assertEquals('report_requested', $record->status);

        // The identifier is the temp-file path generated for the quiz essay response.
        $this->assertStringContainsString('quiz_', $record->identifier);
    }

    /**
     * Test: Verify that an essay response with a file attachment queues the file.
     * @covers ::plagiarism_inspera_quiz_attempt_submitted
     */
    public function test_quiz_attachment_queuing(): void {
        global $DB;

        // Quiz & Question Setup (Requiring Attachments).
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat   = $questiongenerator->create_question_category();

        // Configure the essay to ALLOW and REQUIRE at least 1 attachment.
        $essay = $questiongenerator->create_question('essay', null, [
            'category' => $cat->id,
            'attachments' => 1,
            'attachmentsrequired' => 1,
        ]);

        quiz_add_quiz_question($essay->id, $this->quiz, 0, 10.0);

        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $gradecalculator = \mod_quiz\grade_calculator::create($quizobj);
        $gradecalculator->recompute_quiz_sumgrades();
        $gradecalculator->update_quiz_maximum_grade(10.0);

        // Attempt Creation.
        $this->setUser($this->student);
        $timenow = time();

        /** @var mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attemptrecord = $quizgenerator->create_attempt($this->quiz->id, $this->student->id);

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptrecord->id);

        // Mocking the File Upload (Draft Area).
        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($this->student->id);

        // Create a fake PDF file in the student's draft area.
        $filerecord = [
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'plagiarized_document.pdf',
        ];
        $fs->create_file_from_string($filerecord, 'Dummy PDF content for testing');

        // Submission Logic.
        // Pass the draftitemid to the 'attachments' key so the Question Engine grabs it.
        $tosubmit = [
            1 => [
                'answer'       => 'Please see the attached file.',
                'answerformat' => FORMAT_HTML,
                'attachments'  => $draftitemid, // This triggers the file move!
            ],
        ];

        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);
        $attemptobj->process_finish($timenow, false);

        $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attemptrecord->id], '*', MUST_EXIST);

        // Trigger Observer Logic.
        plagiarism_inspera_quiz_attempt_submitted($attemptrecord);

        // Assertions.
        // Since both text and files might be queued, we get all records for this attempt.
        $records = $DB->get_records('plagiarism_inspera_subs', [
            'cm'     => $this->quiz->cmid,
            'userid' => $this->student->id,
        ]);

        $this->assertNotEmpty($records, 'Expected records in plagiarism_inspera_subs but none were found.');

        // Loop through to assert that the FILE specifically was queued.
        $foundfile = false;
        foreach ($records as $record) {
            $hasfilename = is_string($record->identifier) &&
                strpos($record->identifier, 'plagiarized_document.pdf') !== false;
            $hasfileid   = !empty($record->storedfileid);

            if ($hasfilename || $hasfileid) {
                $foundfile = true;
                $this->assertEquals('report_requested', $record->status);
            }
        }

        $this->assertTrue($foundfile, 'Could not find the attachment queued in plagiarism_inspera_subs.');
    }

    /**
     * Test: Verify report visibility logic for Quiz attempts.
     * @covers ::plagiarism_inspera_should_show_report
     */
    public function test_should_show_report_quiz_logic(): void {
        global $DB;

        $this->setUser($this->student);
        $timenow = time();

        // Ensure quiz has no close date initially.
        $DB->set_field('quiz', 'timeclose', 0, ['id' => $this->quiz->id]);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $essay = $questiongenerator->create_question(
            'essay',
            null,
            [
                'category' => $cat->id,
                'attachments' => 1,
                'attachmentsrequired' => 1,
            ]
        );
        quiz_add_quiz_question($essay->id, $this->quiz, 0, 10.0);

        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $gradecalculator = \mod_quiz\grade_calculator::create($quizobj);
        $gradecalculator->recompute_quiz_sumgrades();
        $gradecalculator->update_quiz_maximum_grade(10.0);

        // Create Attempt and Submit Text + File.
        /** @var mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attemptrecord = $quizgenerator->create_attempt($this->quiz->id, $this->student->id);

        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $fs->create_file_from_string([
            'contextid' => context_user::instance($this->student->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftitemid,
            'filepath' => '/', 'filename' => 'visibility_test.pdf',
        ], 'PDF content');

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptrecord->id);

        $attemptobj->process_submitted_actions($timenow, false, [
            1 => ['answer' => 'Text response', 'answerformat' => FORMAT_HTML, 'attachments' => $draftitemid],
        ]);
        $attemptobj->process_finish($timenow, false);

        $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attemptrecord->id], '*', MUST_EXIST);

        // Trigger Observer to generate REAL records.
        plagiarism_inspera_quiz_attempt_submitted($attemptrecord);

        // Fetch the REAL Text and File Records.
        $records = $DB->get_records(
            'plagiarism_inspera_subs',
            [
                'cm' => $this->quiz->cmid,
                'userid' => $this->student->id,
            ]
        );
        $textrecord = null;
        $filerecord = null;

        foreach ($records as $rec) {
            $rec->status = 'finished';

            if (!empty($rec->storedfileid)) {
                $filerecord = $rec;
            } else if (is_string($rec->identifier)) {
                $textrecord = $rec;
            }
        }

        $this->assertNotNull($textrecord, 'Text record was not generated.');
        $this->assertNotNull($filerecord, 'File record was not generated.');

        // TEST SCENARIO 1: After Grading (Mode 2).
        $settings = [
            'use_originality' => 1,
            'originality_show_student_report' => 2,
        ];

        // A. Ungraded -> Should be False.
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $textrecord
            )
        );
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $filerecord
            )
        );

        // B. Grade the attempt (Set sumgrades AND insert overall quiz grade).
        $DB->set_field('quiz_attempts', 'sumgrades', 10.0, ['id' => $attemptrecord->id]);
        $DB->set_field('quiz_attempts', 'state', \mod_quiz\quiz_attempt::FINISHED, ['id' => $attemptrecord->id]);

        $grade = new stdClass();
        $grade->userid   = $this->student->id;
        $grade->rawgrade = 10.0;
        quiz_grade_item_update($quizobj->get_quiz(), $grade);

        // C. Graded -> Should be True.
        $this->assertTrue(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $textrecord
            )
        );
        $this->assertTrue(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $filerecord
            )
        );

        // 6. TEST SCENARIO 2: After Close Date (Mode 3).
        $settings = [
            'use_originality' => 1,
            'originality_show_student_report' => 3,
        ];

        // A. Quiz closes in the FUTURE -> Should be False.
        $DB->set_field('quiz', 'timeclose', $timenow + 3600, ['id' => $this->quiz->id]);
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $textrecord
            )
        );

        // B. Quiz closed in the PAST -> Should be True.
        $DB->set_field('quiz', 'timeclose', $timenow - 3600, ['id' => $this->quiz->id]);
        $this->assertTrue(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $textrecord
            )
        );

        // TEST SCENARIO 3: User Override.
        $DB->insert_record('quiz_overrides', [
            'quiz' => $this->quiz->id,
            'userid' => $this->student->id,
            'timeclose' => $timenow + 7200, // Extended 2 hours into the future.
        ]);

        // Global quiz is closed, but override is active in the future -> Should be False.
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $textrecord
            )
        );
        $this->assertFalse(
            plagiarism_inspera_should_show_report(
                (int)$this->quiz->cmid,
                (int)$this->student->id,
                $settings,
                $filerecord
            )
        );
    }

    /**
     * Test: Verify the file rehydration fallback logic.
     * @covers ::plagiarism_inspera_rehydrate_file
     */
    public function test_rehydrate_file_logic(): void {
        global $DB, $CFG;

        $generator = $this->getDataGenerator();

        // SCENARIO 1: Security Guard (Path Traversal).
        $dummyrecord = (object)['storedfileid' => null, 'submissionid' => 999];
        // Attempt to write outside the plugin's temp directory.
        $unsafepath = $CFG->tempdir . '/plagiarism_inspera/../malicious_override.php';

        // Capture the mtrace() output to prevent PHPUnit from flagging it as "Risky".
        ob_start();
        $result = plagiarism_inspera_rehydrate_file($dummyrecord, $unsafepath);
        $output = ob_get_clean();

        // Assert the function rejects the path and returns false.
        $this->assertFalse($result);

        // Bonus: Assert our security log actually successfully printed!
        $this->assertStringContainsString('Security block: Path traversal attempt', $output);

        // SCENARIO 2: Assignment Rehydration.
        // Mock an assignment text submission directly in the database for speed.
        $assignsubid = $DB->insert_record('assign_submission', (object)['assignment' => 1, 'userid' => 2]);
        $DB->insert_record('assignsubmission_onlinetext', (object)[
            'assignment' => 1,
            'submission' => $assignsubid,
            'onlinetext' => 'Hello Assignment Rehydration',
        ]);

        $assignrecord = (object)['storedfileid' => null, 'submissionid' => $assignsubid];
        $assignfilepath = $CFG->tempdir . '/plagiarism_inspera/assign_test_1_2.html';

        // Clean up the file if it somehow already exists.
        if (file_exists($assignfilepath)) {
            unlink($assignfilepath);
        }

        // Assert the file was successfully written.
        $this->assertTrue(plagiarism_inspera_rehydrate_file($assignrecord, $assignfilepath));
        $this->assertFileExists($assignfilepath);

        // Assert it contains the wrapped HTML and the expected text.
        $assigncontent = file_get_contents($assignfilepath);
        $this->assertStringContainsString('<!DOCTYPE html>', $assigncontent);
        $this->assertStringContainsString('Hello Assignment Rehydration', $assigncontent);

        // SCENARIO 3: Quiz Rehydration.
        // Reuse the globally prepared student and quiz!
        $this->setUser($this->student);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $essay = $questiongenerator->create_question('essay', null, ['category' => $cat->id]);

        // Add the question to our global quiz.
        quiz_add_quiz_question($essay->id, $this->quiz, 0, 10.0);

        // Sync the grades using the exact same logic from the other tests.
        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $gradecalculator = \mod_quiz\grade_calculator::create($quizobj);
        $gradecalculator->recompute_quiz_sumgrades();
        $gradecalculator->update_quiz_maximum_grade(10.0);

        // Create the attempt for our global student.
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $attemptrecord = $quizgenerator->create_attempt($this->quiz->id, $this->student->id);

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptrecord->id);
        $attemptobj->process_submitted_actions(time(), false, [
            1 => ['answer' => 'Hello Quiz Rehydration', 'answerformat' => FORMAT_HTML],
        ]);
        $attemptobj->process_finish(time(), false);

        // We need the specific Question Attempt ID ($qaid) to build the filename.
        $quba = \question_engine::load_questions_usage_by_activity($attemptrecord->uniqueid);
        $qaiterator = $quba->get_attempt_iterator();
        $qaiterator->rewind();
        $qaid = $qaiterator->current()->get_database_id();

        $quizrecord = (object)['storedfileid' => null];

        // The function relies on this specific filename pattern: quiz_{cmid}_{userid}_{qaid}.html.
        $quizfilename = "quiz_{$this->quiz->cmid}_{$this->student->id}_{$qaid}.html";
        $quizfilepath = $CFG->tempdir . '/plagiarism_inspera/' . $quizfilename;

        if (file_exists($quizfilepath)) {
            unlink($quizfilepath);
        }

        // Assert the file was successfully written.
        $this->assertTrue(plagiarism_inspera_rehydrate_file($quizrecord, $quizfilepath));
        $this->assertFileExists($quizfilepath);

        // Assert it contains the expected text.
        $quizcontent = file_get_contents($quizfilepath);
        $this->assertStringContainsString('Hello Quiz Rehydration', $quizcontent);
    }
}
