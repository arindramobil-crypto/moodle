<?php
/**
 * Event observer class for CBT Kiosk Mode
 *
 * Listens to user_loggedin event and redirects Student role users
 * to the active quiz, based on quiz password set by exam-admin CBT token system.
 *
 * Logic:
 *  1. If the logged-in user is siteadmin, manager, teacher, or coursecreator → no redirect, go to Moodle dashboard.
 *  2. If the logged-in user is a student → find the first quiz with a non-empty password (token).
 *     - If found  → redirect to that quiz's view page.
 *     - If not found → redirect to the kiosk waiting page (local/cbt_kiosk/waiting.php).
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cbt_kiosk;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Handle user_loggedin event and redirect students to the active CBT quiz.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB, $CFG;

        // Event data
        $userid = $event->userid;
        if (!$userid || $userid < 1) {
            return;
        }

        // Skip site administrators — never redirect them
        if (is_siteadmin($userid)) {
            return;
        }

        // Determine if user has any "privileged" role at system context
        // Privileged = manager (1), coursecreator (2), editingteacher (3), teacher (4)
        // Student (5), Guest (6) = kiosk candidates
        $privileged_role_ids = [1, 2, 3, 4]; // manager, coursecreator, editingteacher, teacher

        $systemctx = \context_system::instance();
        $user_roles = get_user_roles($systemctx, $userid, true);

        foreach ($user_roles as $role) {
            if (in_array($role->roleid, $privileged_role_ids)) {
                // This user is a teacher/manager/admin — do NOT redirect
                return;
            }
        }

        // Also check at course level (student enrolled in course)
        // If user has teacher role in any course, skip kiosk
        $teacher_check = $DB->get_records_sql(
            "SELECT ra.id FROM {role_assignments} ra
             JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid = :uid
             AND ra.roleid IN (1, 2, 3, 4)
             AND ctx.contextlevel >= :courselevel
             LIMIT 1",
            ['uid' => $userid, 'courselevel' => CONTEXT_COURSE]
        );

        if (!empty($teacher_check)) {
            return;
        }

        // User is student/guest — find the active quiz (quiz with non-empty password token)
        // Active quiz = quiz where password is not empty (set by exam-admin CBT)
        $active_quiz = $DB->get_record_sql(
            "SELECT q.id, q.name, q.course, cm.id as cmid
             FROM {quiz} q
             JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (
                 SELECT id FROM {modules} WHERE name = 'quiz'
             )
             WHERE q.password != ''
             AND q.password IS NOT NULL
             AND cm.visible = 1
             ORDER BY q.timemodified DESC
             LIMIT 1"
        );

        if ($active_quiz) {
            // Check if student is enrolled in that course
            $is_enrolled = is_enrolled(\context_course::instance($active_quiz->course), $userid, '', true);

            if ($is_enrolled) {
                // Redirect to the quiz view page
                $quizurl = new \moodle_url('/mod/quiz/view.php', ['id' => $active_quiz->cmid]);

                // Store redirect target so Moodle login process can use it
                // We set wantsurl in session - Moodle will redirect there after login
                global $SESSION;
                $SESSION->wantsurl = $quizurl->out(false);
                return;
            }
        }

        // No active quiz found — redirect to waiting page
        global $SESSION;
        $SESSION->wantsurl = $CFG->wwwroot . '/local/cbt_kiosk/waiting.php';
    }
}

