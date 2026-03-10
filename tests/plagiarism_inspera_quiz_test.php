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

defined('MOODLE_INTERNAL') || die();

// Required for quiz manipulation.
global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/plagiarism/inspera/lib.php');

/**
 * PHPUnit tests for the Inspera plagiarism plugin quiz integration.
 *
 * @package    plagiarism_inspera
 * @copyright  2025 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_inspera_quiz_test extends advanced_testcase {

    /** @var stdClass */
    protected $course;

    /** @var stdClass */
    protected $student;

    /** @var stdClass */
    protected $quiz;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Configure the Inspera plagiarism plugin globally so event_handler() proceeds.
        set_config('enabled',       1,                         'plagiarism_inspera');
        set_config('baseurl',       'https://api.example.com', 'plagiarism_inspera');
        set_config('enable_mod_quiz', 1,                       'plagiarism_inspera');
        // Lower the char-count threshold so the short essay text in the test is processed.
        set_config('charcount', 1, 'plagiarism_inspera');

        $this->course  = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        // Create a Quiz.
        $this->quiz = $this->getDataGenerator()->create_module('quiz', [
            'course'    => $this->course->id,
            'gradepass' => 50,
        ]);
    }

    /**
     * Test: Verify that an essay response in a quiz creates a row in the subs table.
     */
    public function test_quiz_essay_queuing() {
        global $DB;

        // --- 1. Enable Inspera for this specific course module ---
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm'    => $this->quiz->cmid,
            'name'  => 'use_originality',
            'value' => '1',
        ]);

        // --- 2. Quiz & Question Setup ---
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

        // --- 3. Attempt Creation ---
        // CRITICAL FIX: Tell PHPUnit to act as the student!
        $this->setUser($this->student);
        $timenow = time();

        // CRITICAL FIX: Use the data generator, which safely writes the record and bypasses web-only capability checks.
        /** @var mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attemptrecord = $quizgenerator->create_attempt($this->quiz->id, $this->student->id);

        if (!$attemptrecord) {
            $this->fail('Failed to find the quiz attempt in the database.');
        }

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptrecord->id);

        // --- 4. Submission Logic ---
        // We do NOT call start_attempt() here because the Data Generator
        // already created the attempt in the 'inprogress' state.

        // Simulate the essay response (Slot 1).
        $tosubmit = [
            1 => [
                'answer'       => 'This is my plagiarized essay content.',
                'answerformat' => FORMAT_HTML
            ]
        ];
        // Process the typed text into the database.
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish and submit the quiz.
        $attemptobj->process_finish($timenow, false);

        // Reload the attempt record to get the fully populated fields (like 'state' = 'finished').
        $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attemptrecord->id], '*', MUST_EXIST);

        // --- 5. Trigger Observer Logic ---
        // Call your excellent helper function to simulate the event trigger
        plagiarism_inspera_quiz_attempt_submitted($attemptrecord);

        // --- 6. Assertions ---
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
     */
    public function test_quiz_attachment_queuing() {
        global $DB, $USER;

        // --- 1. Enable Inspera for this module ---
        $DB->insert_record('plagiarism_inspera_config', (object) [
            'cm'    => $this->quiz->cmid,
            'name'  => 'use_originality',
            'value' => '1',
        ]);

        // --- 2. Quiz & Question Setup (Requiring Attachments) ---
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat   = $questiongenerator->create_question_category();

        // Configure the essay to ALLOW and REQUIRE at least 1 attachment.
        $essay = $questiongenerator->create_question('essay', null, [
            'category' => $cat->id,
            'attachments' => 1,
            'attachmentsrequired' => 1
        ]);

        quiz_add_quiz_question($essay->id, $this->quiz, 0, 10.0);

        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $gradecalculator = \mod_quiz\grade_calculator::create($quizobj);
        $gradecalculator->recompute_quiz_sumgrades();
        $gradecalculator->update_quiz_maximum_grade(10.0);

        // --- 3. Attempt Creation ---
        $this->setUser($this->student);
        $timenow = time();

        /** @var mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attemptrecord = $quizgenerator->create_attempt($this->quiz->id, $this->student->id);

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptrecord->id);

        // --- 4. Mocking the File Upload (Draft Area) ---
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

        // --- 5. Submission Logic ---
        // Pass the draftitemid to the 'attachments' key so the Question Engine grabs it.
        $tosubmit = [
            1 => [
                'answer'       => 'Please see the attached file.',
                'answerformat' => FORMAT_HTML,
                'attachments'  => $draftitemid // This triggers the file move!
            ]
        ];

        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);
        $attemptobj->process_finish($timenow, false);

        $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attemptrecord->id], '*', MUST_EXIST);

        // --- 6. Trigger Observer Logic ---
        plagiarism_inspera_quiz_attempt_submitted($attemptrecord);

        // --- 7. Assertions ---
        // Since both text and files might be queued, we get all records for this attempt.
        $records = $DB->get_records('plagiarism_inspera_subs', [
            'cm'     => $this->quiz->cmid,
            'userid' => $this->student->id,
        ]);

        $this->assertNotEmpty($records, 'Expected records in plagiarism_inspera_subs but none were found.');

        // Loop through to assert that the FILE specifically was queued.
        $foundfile = false;
        foreach ($records as $record) {
            $has_filename = is_string($record->identifier) && strpos($record->identifier, 'plagiarized_document.pdf') !== false;
            $has_fileid   = !empty($record->storedfileid);

            if ($has_filename || $has_fileid) {
                $foundfile = true;
                $this->assertEquals('report_requested', $record->status);
            }
        }

        $this->assertTrue($foundfile, 'Could not find the attachment queued in plagiarism_inspera_subs.');
    }
}