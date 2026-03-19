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
 * Privacy Subsystem implementation for plagiarism_inspera.
 *
 * @package   plagiarism_inspera
 * @copyright 2026 Inspera AS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_inspera\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use core_plagiarism\privacy\plagiarism_provider;
use core_plagiarism\privacy\plagiarism_user_provider;
use core_privacy\local\metadata\provider as metadata_provider;

/**
 * Privacy subsystem for plagiarism_inspera.
 */
class provider implements plagiarism_provider, plagiarism_user_provider, metadata_provider {

    /**
     * Describe the data stored and transmitted by this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        // Link to Moodle's core files.
        $collection->link_subsystem('core_files', 'privacy:metadata:core_files');

        // Map the plagiarism_inspera_subs table fields.
        $collection->add_database_table(
            'plagiarism_inspera_subs',
            [
                'userid'            => 'privacy:metadata:plagiarism_inspera_subs:userid',
                'submissionid'      => 'privacy:metadata:plagiarism_inspera_subs:submissionid',
                'externalid'        => 'privacy:metadata:plagiarism_inspera_subs:externalid',
                'similarity'        => 'privacy:metadata:plagiarism_inspera_subs:similarity',
                'originality_score' => 'privacy:metadata:plagiarism_inspera_subs:originality_score',
                'translation_sim'   => 'privacy:metadata:plagiarism_inspera_subs:translation_similarity',
                'ai_index'          => 'privacy:metadata:plagiarism_inspera_subs:ai_index',
                'status'            => 'privacy:metadata:plagiarism_inspera_subs:status',
                'description'       => 'privacy:metadata:plagiarism_inspera_subs:description',
                'timecreated'       => 'privacy:metadata:plagiarism_inspera_subs:timecreated',
                'timemodified'      => 'privacy:metadata:plagiarism_inspera_subs:timemodified',
            ],
            'privacy:metadata:plagiarism_inspera_subs'
        );

        // Describe data sent to Inspera API (Satisfies Issue #3).
        $collection->link_external_location('inspera', [
            'filename' => 'privacy:metadata:inspera:filename',
            'fullname' => 'privacy:metadata:inspera:fullname',
            'content'  => 'privacy:metadata:inspera:content',
        ], 'privacy:metadata:inspera');

        return $collection;
    }

    /**
     * Export all plagiarism data for the specified userid and context.
     */
    public static function export_plagiarism_user_data(
        int $userid,
        \context $context,
        array $subcontext,
        array $linkarray
    ) {
        global $DB;
        if (empty($userid)) {
            return;
        }

        $params = ['userid' => $userid, 'cm' => $context->instanceid];
        $sql = "SELECT * FROM {plagiarism_inspera_subs} WHERE userid = :userid AND cm = :cm";

        $records = $DB->get_records_sql($sql, $params);
        foreach ($records as $record) {
            $currentsubcontext = $subcontext;

            if (!empty($record->storedfileid)) {
                $currentsubcontext[] = get_string('file') . '_' . $record->storedfileid;
            } else {
                $onlinestr = get_string('onlinetext', 'assignsubmission_onlinetext');
                $currentsubcontext[] = $onlinestr . '_' . $record->id;
            }

            writer::with_context($context)->export_data($currentsubcontext, (object)$record);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     */
    public static function delete_plagiarism_for_context(\context $context) {
        global $DB;
        if ($context instanceof \context_module) {
            $DB->delete_records('plagiarism_inspera_subs', ['cm' => $context->instanceid]);
        }
    }

    /**
     * Delete all user information for the provided user and context.
     */
    public static function delete_plagiarism_for_user(int $userid, \context $context) {
        global $DB;
        $DB->delete_records('plagiarism_inspera_subs', [
            'userid' => $userid,
            'cm'     => $context->instanceid,
        ]);
    }

    /**
     * Delete multiple users within a single context.
     * Required by \core_plagiarism\privacy\plagiarism_user_provider.
     */
    public static function delete_plagiarism_for_users(array $userids, \context $context) {
        global $DB;
        if (empty($userids) || !($context instanceof \context_module)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $inparams['cm'] = $context->instanceid;

        $DB->delete_records_select(
            'plagiarism_inspera_subs',
            "cm = :cm AND userid $insql",
            $inparams
        );
    }
}
