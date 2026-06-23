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
 * PHPUnit tests for the display_manager class.
 *
 * @package     plagiarism_inspera
 * @category    test
 * @copyright   2026 Inspera AS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera;

use advanced_testcase;
use plagiarism_inspera\services\display\display_manager;
use plagiarism_inspera\services\display\assign_handler;
use plagiarism_inspera\services\display\quiz_handler;
use plagiarism_inspera\services\display\workshop_handler;
use plagiarism_inspera\services\display\forum_handler;

/**
 * Unit tests for the display_manager orchestration service.
 *
 * @package    plagiarism_inspera
 * @category   test
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \plagiarism_inspera\services\display\display_manager
 */
final class display_manager_test extends advanced_testcase {
    /**
     * Helper to access private/protected methods via Reflection.
     *
     * @param string $name The method name to access.
     * @return \ReflectionMethod
     */
    protected function get_accessible_method(string $name): \ReflectionMethod {
        $class = new \ReflectionClass(display_manager::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Test that get_handler correctly routes to the dedicated module handlers
     * and rejects unsupported modules.
     *
     * @covers ::get_handler
     */
    public function test_get_handler_routes_correctly(): void {
        global $DB;
        $manager = new display_manager($DB);
        $method = $this->get_accessible_method('get_handler');

        $this->assertInstanceOf(assign_handler::class, $method->invoke($manager, 'assign'));
        $this->assertInstanceOf(quiz_handler::class, $method->invoke($manager, 'quiz'));
        $this->assertInstanceOf(workshop_handler::class, $method->invoke($manager, 'workshop'));

        // Assert our newly added forum support routes correctly!
        $this->assertInstanceOf(forum_handler::class, $method->invoke($manager, 'forum'));
        $this->assertInstanceOf(forum_handler::class, $method->invoke($manager, 'hsuforum'));

        // Unsupported modules should return null.
        $this->assertNull($method->invoke($manager, 'glossary'));
        $this->assertNull($method->invoke($manager, 'scorm'));
    }

    /**
     * Test the quiz execution gatekeeper.
     *
     * @covers ::should_process_quiz_link
     */
    public function test_should_process_quiz_link(): void {
        global $DB;
        $this->resetAfterTest(true);
        $manager = new display_manager($DB);
        $method = $this->get_accessible_method('should_process_quiz_link');

        // 1. Non-quiz components should always return true (pass through).
        $this->assertTrue($method->invoke($manager, ['component' => 'mod_assign']));

        // 2. Mod Quiz is disabled in plugin settings.
        set_config('enable_mod_quiz', 0, 'plagiarism_inspera');
        $this->assertFalse($method->invoke($manager, ['component' => 'mod_quiz']));

        // 3. Mod Quiz is enabled in plugin settings.
        set_config('enable_mod_quiz', 1, 'plagiarism_inspera');
        $this->assertTrue($method->invoke($manager, ['component' => 'mod_quiz']));

        // 4. Unsupported question type.
        $this->assertFalse($method->invoke($manager, ['component' => 'qtype_multichoice']));

        // 5. Supported question type (assuming 'essay' is in plagiarism_inspera_supported_qtypes).
        $this->assertTrue($method->invoke($manager, ['component' => 'qtype_essay']));
    }

    /**
     * Test that missing data in qtype_* payloads is successfully backfilled
     * by querying the Moodle Question Engine tables.
     *
     * @covers ::resolve_quiz_link_fields
     * @covers ::resolve_question_attempt_record
     */
    public function test_resolve_quiz_link_fields_backfills_data(): void {
        global $DB;
        $this->resetAfterTest(true);

        // 1. Setup a dummy course, quiz, and user.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $user = $this->getDataGenerator()->create_user();

        // 2. Insert dummy records into the quiz/question attempt tables.
        // We provide explicit values for NOT NULL columns to appease strict databases like PostgreSQL.
        $quizattempt = (object)[
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => 12345, // The question usage ID.
            'layout' => '1,0', // Required by Postgres.
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => time(),
            'timefinish' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('quiz_attempts', $quizattempt);

        // Fully populate the question_attempt record to satisfy PostgreSQL NOT NULL constraints.
        $qa = new \stdClass();
        $qa->questionusageid = 12345;
        $qa->slot = 1;
        $qa->behaviour = 'manualgraded';
        $qa->questionid = 1;
        $qa->variant = 1;
        $qa->maxmark = 1.0000000;
        $qa->minfraction = 0.0000000;
        $qa->maxfraction = 1.0000000;
        $qa->flagged = 0;
        $qa->questionsummary = '';
        $qa->rightanswer = '';
        $qa->responsesummary = 'This is the backfilled essay text.';
        $qa->timemodified = time();

        $qa->id = $DB->insert_record('question_attempts', $qa);

        // 3. Create a sparse linkarray mimicking an incomplete qtype_* hook payload.
        $linkarray = [
            'area'   => 12345,
            'itemid' => 1,
        ];

        // 4. Execute the backfill logic.
        $manager = new display_manager($DB);
        $method = $this->get_accessible_method('resolve_quiz_link_fields');

        // Pass by reference is required since the method modifies the array.
        $method->invokeArgs($manager, [&$linkarray]);

        // 5. Assert the orchestrator successfully found and injected the missing data.
        $this->assertEquals($user->id, $linkarray['userid']);
        $this->assertEquals('This is the backfilled essay text.', $linkarray['content']);
        $this->assertEquals($cm->id, $linkarray['cmid']);
    }
}
