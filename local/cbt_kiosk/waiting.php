<?php
/**
 * CBT Kiosk - Waiting page
 *
 * Displayed when student logs in but no active quiz token has been issued yet.
 * Auto-polls every 10 seconds to redirect as soon as a quiz becomes active.
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_login(null, false);

// If not a student, redirect to dashboard
if (is_siteadmin()) {
    redirect(new moodle_url('/'));
}

// Check if there is now an active quiz (token has been released by exam-admin)
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

// If quiz is active and student is enrolled, redirect immediately
if ($active_quiz) {
    $is_enrolled = is_enrolled(context_course::instance($active_quiz->course), $USER->id, '', true);
    if ($is_enrolled) {
        redirect(new moodle_url('/mod/quiz/view.php', ['id' => $active_quiz->cmid]));
    }
}

// No active quiz — show waiting page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cbt_kiosk/waiting.php');
$PAGE->set_title('Menunggu Ujian Dimulai — CBT');
$PAGE->set_pagelayout('popup'); // Minimal layout — no sidebar, no blocks
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ruang Tunggu Ujian CBT</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    height: 100%;
    background: #0f172a;
    font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #e2e8f0;
}
.waiting-card {
    text-align: center;
    padding: 48px 56px;
    background: #1e293b;
    border-radius: 20px;
    border: 1px solid #334155;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.school-logo {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    margin: 0 auto 20px;
    display: block;
}
h1 {
    font-size: 1.4rem;
    font-weight: 700;
    color: #fff;
    margin-bottom: 8px;
}
p.subtitle {
    font-size: 14px;
    color: #94a3b8;
    margin-bottom: 32px;
    line-height: 1.6;
}
.pulse-container {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 24px;
}
.pulse-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #6366f1;
    animation: pulse 1.4s ease-in-out infinite;
}
.pulse-dot:nth-child(2) { animation-delay: 0.2s; }
.pulse-dot:nth-child(3) { animation-delay: 0.4s; }
@keyframes pulse {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}
.status-text {
    font-size: 15px;
    color: #6366f1;
    font-weight: 600;
    margin-bottom: 24px;
}
.student-info {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #0f172a;
    border-radius: 10px;
    padding: 12px 16px;
    border: 1px solid #334155;
    justify-content: center;
    margin-bottom: 24px;
}
.avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    color: white;
    flex-shrink: 0;
}
.student-name {
    font-weight: 600;
    font-size: 14px;
    color: #fff;
}
.counter {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 16px;
}
.btn-logout {
    display: inline-block;
    padding: 8px 20px;
    background: transparent;
    border: 1px solid #475569;
    border-radius: 8px;
    color: #94a3b8;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.btn-logout:hover {
    background: #334155;
    color: #e2e8f0;
}
#refresh-countdown {
    font-size: 11px;
    color: #475569;
    margin-top: 16px;
}
</style>
</head>
<body>
<div class="waiting-card">
    <h1>&#127979; Ruang Tunggu Ujian CBT</h1>
    <p class="subtitle">
        Halaman ini akan otomatis mengarahkan Anda ke soal ujian<br>
        begitu pengawas mengaktifkan token ujian.
    </p>

    <?php
    // Display student name initial
    $nama = fullname($USER);
    $initial = mb_strtoupper(mb_substr($nama, 0, 1));
    ?>

    <div class="student-info">
        <div class="avatar"><?= htmlspecialchars($initial) ?></div>
        <div>
            <div class="student-name"><?= htmlspecialchars($nama) ?></div>
        </div>
    </div>

    <div class="pulse-container">
        <div class="pulse-dot"></div>
        <div class="pulse-dot"></div>
        <div class="pulse-dot"></div>
    </div>

    <div class="status-text">Menunggu pengawas membuka soal ujian&hellip;</div>
    <div class="counter">Halaman otomatis diperbarui setiap <strong>10 detik</strong></div>

    <div id="refresh-countdown">Memuat ulang dalam <span id="secs">10</span> detik&hellip;</div>

    <br>
    <a href="<?= (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out() ?>"
       class="btn-logout">&#x2715; Keluar / Logout</a>
</div>

<script>
var secs = 10;
var el = document.getElementById('secs');
var t = setInterval(function() {
    secs--;
    el.textContent = secs;
    if (secs <= 0) {
        clearInterval(t);
        window.location.reload();
    }
}, 1000);
</script>
</body>
</html>
<?php
// No OUTPUT->header/footer needed since we output raw HTML in popup layout
// (Moodle popup layout is minimal)

