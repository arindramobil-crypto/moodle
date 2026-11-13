<?php
/**
 * CBT Kiosk - AJAX endpoint to record violation logs and verify Proctor PIN
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

// Must be logged in (students/participants)
if (!isloggedin() || isguestuser()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Read JSON input or POST data
$input = file_get_contents('php://input');
$data = [];
if (!empty($input)) {
    $data = json_decode($input, true) ?: [];
}
if (empty($data)) {
    $data = $_POST;
}

$action         = clean_param($data['action'] ?? '', PARAM_ALPHAEXT);
$violationCount = clean_param($data['violation_count'] ?? 0, PARAM_INT);
$reason         = clean_param($data['reason'] ?? '', PARAM_TEXT);
$quizid         = clean_param($data['quizid'] ?? 0, PARAM_INT);
$attemptid      = clean_param($data['attemptid'] ?? 0, PARAM_INT);
$pin            = trim(clean_param($data['pin'] ?? '', PARAM_TEXT));

// Auto-detect device type from User Agent
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool)preg_match('/(android|iphone|ipad|ipod|mobile|phone|tablet)/i', $ua);
$deviceType = $isMobile ? 'HP/Mobile' : 'PC/Desktop';
$ipAddress  = getremoteaddr();

// Fail-safe ensure table exists
$dbman = $DB->get_manager();
$tablename = 'local_cbt_kiosk_logs';
if (!$dbman->table_exists($tablename)) {
    $table = new xmldb_table($tablename);
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '');
    $table->add_field('violation_count', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('reason', XMLDB_TYPE_CHAR, '255', null, null, null, null);
    $table->add_field('device_type', XMLDB_TYPE_CHAR, '30', null, null, null, null);
    $table->add_field('ip_address', XMLDB_TYPE_CHAR, '45', null, null, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
    $table->add_index('quizid_idx', XMLDB_INDEX_NOTUNIQUE, ['quizid']);
    $dbman->create_table($table);
}

// Handler for unlocking
if ($action === 'unlocked') {
    // Determine configured Proctor PINs
    $customPin = trim((string)get_config('local_cbt_kiosk', 'proctor_pin'));
    if (!empty($customPin)) {
        $validPins = [strtoupper($customPin)];
    } else {
        $validPins = ['PROKTOR123'];
    }

    // STRICT CHECK: The Proctor PIN must NEVER be the quiz token!
    if ($quizid > 0) {
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, password');
        if ($quiz && !empty($quiz->password)) {
            $quizToken = strtoupper(trim((string)$quiz->password));
            // Remove quizToken if it somehow matches a proctor PIN
            $validPins = array_diff($validPins, [$quizToken]);
        }
    }

    $enteredPin = strtoupper($pin);
    $isCorrect = in_array($enteredPin, $validPins, true);

    if (!$isCorrect) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'PIN Pengawas salah! Token soal tidak dapat digunakan untuk membuka kunci.'
        ]);
        exit;
    }

    // Record unlocked log
    $log = new stdClass();
    $log->userid          = (int)$USER->id;
    $log->quizid          = (int)$quizid;
    $log->attemptid       = (int)$attemptid;
    $log->action          = 'unlocked';
    $log->violation_count = (int)$violationCount;
    $log->reason          = 'Ujian dibuka kembali oleh Pengawas (PIN: ' . substr($enteredPin, 0, 2) . '***)';
    $log->device_type     = $deviceType;
    $log->ip_address      = $ipAddress;
    $log->timecreated     = time();

    $DB->insert_record($tablename, $log);

    echo json_encode([
        'status'  => 'ok',
        'success' => true,
        'message' => 'Ujian berhasil dibuka kembali oleh Pengawas.'
    ]);
    exit;
}

// Handler for violation logs (warning_1, warning_2, locked)
if (in_array($action, ['warning_1', 'warning_2', 'locked'])) {
    $log = new stdClass();
    $log->userid          = (int)$USER->id;
    $log->quizid          = (int)$quizid;
    $log->attemptid       = (int)$attemptid;
    $log->action          = $action;
    $log->violation_count = (int)$violationCount;
    $log->reason          = $reason ?: 'Pelanggaran tab/fokus terdeteksi';
    $log->device_type     = $deviceType;
    $log->ip_address      = $ipAddress;
    $log->timecreated     = time();

    $DB->insert_record($tablename, $log);

    echo json_encode(['status' => 'ok', 'logged' => true]);
    exit;
}

echo json_encode(['status' => 'ignored']);

