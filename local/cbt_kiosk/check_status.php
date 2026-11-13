<?php
/**
 * CBT Kiosk - Check lock / remote unlock status for current student
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

if (!isloggedin() || isguestuser()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$quizid = optional_param('quizid', 0, PARAM_INT);
if ($quizid <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid quiz ID']);
    exit;
}

$dbman = $DB->get_manager();
$tablename = 'local_cbt_kiosk_logs';
if (!$dbman->table_exists($tablename)) {
    echo json_encode(['status' => 'ok', 'locked' => false]);
    exit;
}

// Find the latest kiosk action for this student on this quiz
$lastLog = $DB->get_record_sql(
    "SELECT * FROM {local_cbt_kiosk_logs}
     WHERE userid = :uid AND quizid = :qid
     ORDER BY id DESC",
    ['uid' => (int)$USER->id, 'qid' => (int)$quizid],
    IGNORE_MULTIPLE
);

if (!$lastLog) {
    echo json_encode(['status' => 'ok', 'locked' => false]);
    exit;
}

// If the latest logged action is 'unlocked' or 'remote_unlocked', student is unlocked!
if (in_array($lastLog->action, ['unlocked', 'remote_unlocked'], true)) {
    echo json_encode([
        'status'  => 'ok',
        'locked'  => false,
        'action'  => $lastLog->action,
        'message' => 'Ujian telah dibuka kembali oleh Pengawas.'
    ]);
    exit;
}

// If latest action is 'locked', student is still locked
if ($lastLog->action === 'locked') {
    echo json_encode([
        'status' => 'ok',
        'locked' => true,
        'action' => 'locked'
    ]);
    exit;
}

// Any other action (warning_1, warning_2) means not locked
echo json_encode([
    'status' => 'ok',
    'locked' => false,
    'action' => $lastLog->action
]);

