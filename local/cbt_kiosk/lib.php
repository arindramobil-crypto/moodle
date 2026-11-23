<?php
/**
 * CBT Kiosk - lib.php (hook callbacks)
 *
 * Uses after_config hook (called early in every Moodle request) to enforce kiosk
 * mode for already-logged-in student sessions navigating away from quiz pages.
 *
 * This prevents students from accessing Moodle's dashboard, course pages, or
 * profile pages while a kiosk session is active.
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook: called after config is loaded but before page output.
 *
 * Enforces kiosk redirect for student users on every page load.
 */
function local_cbt_kiosk_after_config(): void {
    global $USER, $CFG, $DB;

    // Only enforce for authenticated, non-admin, non-guest users
    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return;
    }

    // Determine current page script path (relative to wwwroot)
    $script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
    $moodle_root = realpath($CFG->dirroot);

    // Pages that are always allowed for anyone (login, logout, waiting, quiz attempt, etc.)
    $always_allowed_patterns = [
        '/login/',             // login/index.php, login/logout.php
        '/mod/quiz/',          // quiz view, attempt, processattempt, summary, review
        '/local/cbt_kiosk/',   // this plugin's pages (waiting.php)
        '/pluginfile.php',     // media files for quiz questions
        '/theme/',             // themes
        '/lib/',               // core libs
        '/ajax/',              // any ajax endpoint
        '/admin/',             // allow admin panel (handled by siteadmin check anyway)
        '/webservice/',        // web services
        '/tokenpluginfile.php',// token plugin files
        '/draftfile.php',      // draft files
    ];

    // Build relative path
    $rel = '';
    if ($moodle_root && $script && strpos($script, $moodle_root) === 0) {
        $rel = str_replace('\\', '/', substr($script, strlen($moodle_root)));
    }

    foreach ($always_allowed_patterns as $pattern) {
        if (strpos($rel, $pattern) !== false) {

            // ★ FIX BUG 1: Blok waktu minimal HANYA untuk siswa biasa (bukan guru/admin)
            // Harus ada di sini karena /mod/quiz/ masuk always_allowed, jadi kita cek khusus processattempt
            if (strpos($rel, '/mod/quiz/processattempt.php') !== false) {
                $finishattempt = !empty($_POST['finishattempt']) || !empty($_GET['finishattempt']);
                $timeup = !empty($_POST['timeup']) || !empty($_GET['timeup']);
                if ($finishattempt && !$timeup) {
                    $attemptid = (int)($_POST['attempt'] ?? $_GET['attempt'] ?? 0);
                    if ($attemptid > 0) {
                        // Cek privilege dulu - jangan blok guru/admin
                        $isTeacher = false;
                        try {
                            if (function_exists('is_siteadmin') && !empty($USER->id) && is_siteadmin($USER->id)) {
                                $isTeacher = true;
                            } else {
                                $isTeacher = !empty($USER->id) && $DB->record_exists_sql(
                                    "SELECT ra.id FROM {role_assignments} ra WHERE ra.userid = :uid AND ra.roleid IN (1, 2, 3, 4)",
                                    ['uid' => $USER->id]
                                );
                            }
                        } catch (\Throwable $e) {}

                        if (!$isTeacher) {
                            $minMinutes = local_cbt_kiosk_get_min_finish_minutes();
                            if ($minMinutes > 0) {
                                try {
                                    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id,timestart,preview,userid');
                                    // Hanya blok jika attempt milik user ini dan bukan preview
                                    if ($attempt && empty($attempt->preview) && (int)$attempt->userid === (int)$USER->id) {
                                        $elapsed = time() - (int)$attempt->timestart;
                                        $minSeconds = $minMinutes * 60;
                                        if ($elapsed < $minSeconds) {
                                            $remaining = $minSeconds - $elapsed;
                                            $remMinutes = (int)ceil($remaining / 60);
                                            // Simpan pesan ke session agar tampil di summary page
                                            $_SESSION['cbt_min_finish_warning'] = "Anda belum dapat menyelesaikan ujian! Ketentuan waktu minimal pengerjaan adalah {$minMinutes} menit. Sisa waktu sekitar {$remMinutes} menit lagi.";
                                            $summaryUrl = new \moodle_url('/mod/quiz/summary.php', [
                                                'attempt'   => $attemptid,
                                                'cbt_early' => 1,
                                            ]);
                                            redirect($summaryUrl);
                                            exit;
                                        }
                                    }
                                } catch (\Throwable $e) {}
                            }
                        }
                    }
                }
            }

            return; // Allow
        }
    }

    // Check if user has teacher/manager role at any context (should not be in kiosk)
    try {
        $has_privilege = $DB->record_exists_sql(
            "SELECT ra.id FROM {role_assignments} ra WHERE ra.userid = :uid AND ra.roleid IN (1, 2, 3, 4)",
            ['uid' => $USER->id]
        );
        if ($has_privilege || is_siteadmin($USER->id)) {
            return; // Teachers/admin can navigate freely
        }
    } catch (\Throwable $e) {
        return; // On DB error, don't block
    }

    // Student user is navigating to a non-quiz, non-allowed page — enforce kiosk
    // Find active quiz (quiz with non-empty password token from exam-admin)
    try {
        $active_quizzes = $DB->get_records_sql(
            "SELECT q.id, q.name, q.course, cm.id as cmid
             FROM {quiz} q
             JOIN {course_modules} cm ON cm.instance = q.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
             WHERE q.password IS NOT NULL AND q.password != ''
             AND cm.visible = 1
             ORDER BY q.timemodified DESC",
            null, 0, 1
        );
        $active_quiz = !empty($active_quizzes) ? reset($active_quizzes) : null;

        if ($active_quiz) {
            $is_enrolled = is_enrolled(\context_course::instance($active_quiz->course), $USER->id, '', true);
            if ($is_enrolled) {
                $url = new \moodle_url('/mod/quiz/view.php', ['id' => $active_quiz->cmid]);
                redirect($url);
            }
        }
    } catch (\Throwable $e) {
        // Fall through to waiting room
    }

    // No active quiz — redirect to waiting page
    $waiting_url = new \moodle_url('/local/cbt_kiosk/waiting.php');

    // Avoid redirect loop
    if (strpos($rel, '/local/cbt_kiosk/') === false) {
        redirect($waiting_url);
    }
}

/**
 * Dapatkan PIN Pengawas aktif langsung dari basis data (by-pass stale MUC cache).
 *
 * @return string
 */
function local_cbt_kiosk_get_proctor_pin(): string {
    global $DB;

    // 1. Prioritas utama: query langsung dari tabel mdl_config_plugins
    try {
        $pin = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'local_cbt_kiosk',
            'name'   => 'proctor_pin'
        ]);
        if ($pin !== false && trim((string)$pin) !== '') {
            return strtoupper(trim((string)$pin));
        }
    } catch (\Throwable $e) {}

    // 2. Fallback: get_config bawaan Moodle
    try {
        $cfgPin = get_config('local_cbt_kiosk', 'proctor_pin');
        if (!empty($cfgPin)) {
            return strtoupper(trim((string)$cfgPin));
        }
    } catch (\Throwable $e) {}

    // 3. Fallback default
    return 'PROKTOR123';
}

/**
 * Dapatkan batas waktu minimal pengerjaan (dalam menit) sebelum siswa boleh menyelesaikan ujian.
 *
 * @return int
 */
function local_cbt_kiosk_get_min_finish_minutes(): int {
    global $DB;

    // 1. Prioritas utama: query langsung dari tabel mdl_config_plugins
    try {
        $val = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'local_cbt_kiosk',
            'name'   => 'min_finish_minutes'
        ]);
        if ($val !== false && is_numeric($val)) {
            return max(0, (int)$val);
        }
    } catch (\Throwable $e) {}

    // 2. Fallback: get_config bawaan Moodle
    try {
        $cfgVal = get_config('local_cbt_kiosk', 'min_finish_minutes');
        if ($cfgVal !== false && is_numeric($cfgVal)) {
            return max(0, (int)$cfgVal);
        }
    } catch (\Throwable $e) {}

    return 0;
}

/**
 * Hook before_footer: Injects anti-cheat tab-lockdown on quiz attempt pages.
 * Detects tab switching, minimizes, app blurring, and locks the quiz after 3 violations.
 * Fully optimized for both desktop and mobile phone (HP) browsers.
 *
 * @return string
 */
function local_cbt_kiosk_before_footer(): string {
    global $PAGE, $USER, $DB;

    // Detect if we are on quiz attempt or summary page
    $pagetype = '';
    try {
        $pagetype = (string)$PAGE->pagetype;
    } catch (\Throwable $e) {
        $pagetype = '';
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $is_quiz_attempt = ($pagetype === 'mod-quiz-attempt' || $pagetype === 'mod-quiz-summary'
        || strpos($script, '/mod/quiz/attempt.php') !== false
        || strpos($script, '/mod/quiz/summary.php') !== false);

    if (!$is_quiz_attempt) {
        return '';
    }

    // Must be logged in (not guest)
    if (!isloggedin() || isguestuser()) {
        return '';
    }

    // Check if user is teacher or admin (for test/preview mode)
    $isAdmin = is_siteadmin($USER->id);
    $has_privilege = false;
    try {
        $has_privilege = $DB->record_exists_sql(
            "SELECT ra.id FROM {role_assignments} ra WHERE ra.userid = :uid AND ra.roleid IN (1, 2, 3, 4)",
            ['uid' => $USER->id]
        );
    } catch (\Throwable $e) {}

    $isPrivileged = ($isAdmin || $has_privilege);

    // Bebaskan Admin dan Guru dari anti-cheat saat pratinjau (Preview) kuis
    if ($isPrivileged || !empty($_GET['preview']) || !empty($_POST['preview'])) {
        return '';
    }

    // Get quizid and attemptid
    $quizid    = 0;
    $attemptid = 0;
    try {
        $cm = $PAGE->cm;
        if ($cm && !empty($cm->instance)) {
            $quizid = (int)$cm->instance;
        }
    } catch (\Throwable $e) {}

    if (isset($_GET['attempt'])) {
        $attemptid = (int)$_GET['attempt'];
    } elseif (isset($_POST['attempt'])) {
        $attemptid = (int)$_POST['attempt'];
    }

    if ($attemptid > 0) {
        try {
            $isPreview = (int)$DB->get_field('quiz_attempts', 'preview', ['id' => $attemptid]);
            if ($isPreview === 1) {
                return ''; // Pratinjau kuis tidak dikenakan anti-cheat
            }
        } catch (\Throwable $e) {}
    }

    if ($quizid <= 0 && $attemptid > 0) {
        try {
            $quizid = (int)$DB->get_field('quiz_attempts', 'quiz', ['id' => $attemptid]);
        } catch (\Throwable $e) {}
    }

    // Ambil status pelanggaran & kunci terbaru dari basis data server untuk siswa ini
    $serverViolations = 0;
    $serverIsLocked   = false;
    if ($quizid > 0 && !empty($USER->id)) {
        try {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_cbt_kiosk_logs')) {
                $lastLog = $DB->get_record_sql(
                    "SELECT * FROM {local_cbt_kiosk_logs}
                     WHERE userid = :uid AND quizid = :qid
                     ORDER BY id DESC",
                    ['uid' => (int)$USER->id, 'qid' => (int)$quizid],
                    IGNORE_MULTIPLE
                );
                if ($lastLog) {
                    if ($lastLog->action === 'locked') {
                        $serverIsLocked = true;
                        $serverViolations = 3;
                    } elseif (in_array($lastLog->action, ['unlocked', 'remote_unlocked'], true)) {
                        $serverIsLocked = false;
                        $serverViolations = 2; // toleransi 1 kesempatan terakhir
                    } elseif (strpos($lastLog->action, 'warning_') === 0) {
                        $serverViolations = (int)$lastLog->violation_count;
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    // Get Master Proctor Unlock PIN (separate from quiz token, direct DB query)
    $proctorPin = local_cbt_kiosk_get_proctor_pin();

    $output = local_cbt_kiosk_render_anticheat($quizid, $attemptid, $proctorPin, $isPrivileged, $serverViolations, $serverIsLocked);

    // Kunci Waktu Minimal Pengerjaan (Khusus siswa sesungguhnya, bukan pratinjau guru/admin)
    if (!$isPrivileged && $attemptid > 0) {
        $minFinishMinutes = local_cbt_kiosk_get_min_finish_minutes();
        if ($minFinishMinutes > 0) {
            $remainingSeconds = 0;
            try {
                $timestart = (int)$DB->get_field('quiz_attempts', 'timestart', ['id' => $attemptid]);
                if ($timestart > 0) {
                    $targetTime = $timestart + ($minFinishMinutes * 60);
                    $remainingSeconds = max(0, $targetTime - time());
                }
            } catch (\Throwable $e) {}

            $output .= local_cbt_kiosk_render_min_finish_lock($minFinishMinutes, $remainingSeconds);
        }
    }

    return $output;
}

/**
 * Render kunci ketentuan waktu minimal pengerjaan (hitung mundur & blokir tombol kumpulkan kuis).
 *
 * @param int $minMinutes Batas waktu minimal dalam menit
 * @param int $remainingSeconds Sisa detik sebelum tombol selesai diizinkan
 * @return string
 */
function local_cbt_kiosk_render_min_finish_lock(int $minMinutes, int $remainingSeconds): string {
    if ($minMinutes <= 0) {
        return '';
    }

    $minMinutesSafe = (int)$minMinutes;
    $remainingSecondsSafe = max(0, (int)$remainingSeconds);

    return <<<HTML
<!-- ===== CBT KIOSK MINIMUM FINISH TIME LOCK ===== -->
<style>
@keyframes cbtPulseGreen {
    0% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        transform: scale(1);
    }
    50% {
        box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
        transform: scale(1.02);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
        transform: scale(1);
    }
}
.cbt-finish-locked {
    pointer-events: none !important;
    opacity: 0.45 !important;
    filter: grayscale(0.85) !important;
    cursor: not-allowed !important;
    user-select: none !important;
}
.cbt-finish-unlocked {
    animation: cbtPulseGreen 2s infinite !important;
    border-color: #10b981 !important;
}
</style>
<script>
(function() {
    'use strict';
    var minMinutes = {$minMinutesSafe};
    var remainingSeconds = {$remainingSecondsSafe};

    function formatTime(sec) {
        if (sec <= 0) return '00:00';
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        var h = Math.floor(m / 60);
        if (h > 0) {
            m = m % 60;
            return (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
        }
        return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
    }

    function getFinishButtons() {
        var buttons = [];

        // ★ FIX BUG 2: Moodle merender single_button sebagai:
        //   <div class="btn-finishattempt"><form id="frm-finishattempt"><button id="responseform" ...></form></div>
        // Jadi kita cari button di dalam form#frm-finishattempt, dan button di dalam .btn-finishattempt

        // 1. Cari button di dalam form#frm-finishattempt (paling akurat)
        var finishForm = document.getElementById('frm-finishattempt');
        if (finishForm) {
            var fBtns = finishForm.querySelectorAll('button[type="submit"], input[type="submit"]');
            for (var i = 0; i < fBtns.length; i++) {
                buttons.push(fBtns[i]);
            }
        }

        // 2. Cari button di dalam div.btn-finishattempt (Moodle single_button wrapper)
        var wrappers = document.querySelectorAll('.btn-finishattempt');
        for (var j = 0; j < wrappers.length; j++) {
            var innerBtns = wrappers[j].querySelectorAll('button[type="submit"], input[type="submit"]');
            for (var k = 0; k < innerBtns.length; k++) {
                if (buttons.indexOf(innerBtns[k]) === -1) {
                    buttons.push(innerBtns[k]);
                }
            }
        }

        // 3. Button langsung dengan id="responseform" (Moodle quiz finish button)
        var respBtn = document.getElementById('responseform');
        if (respBtn && (respBtn.tagName === 'BUTTON' || respBtn.tagName === 'INPUT') && buttons.indexOf(respBtn) === -1) {
            buttons.push(respBtn);
        }

        // 4. .submitbtns area — semua submit button kecuali "Kembali"
        var submitBtns = document.querySelectorAll('.submitbtns input[type="submit"], .submitbtns button[type="submit"]');
        for (var l = 0; l < submitBtns.length; l++) {
            var val = (submitBtns[l].value || submitBtns[l].textContent || '').toLowerCase().trim();
            if (val.indexOf('return') === -1 && val.indexOf('kembali') === -1 && val !== '') {
                if (buttons.indexOf(submitBtns[l]) === -1) {
                    buttons.push(submitBtns[l]);
                }
            }
        }

        return buttons;
    }

    function updateLockUI() {
        var finishBtns = getFinishButtons();
        var timerDisplay = document.getElementById('cbt-finish-timer-display');
        var banner = document.getElementById('cbt-min-finish-banner');

        if (remainingSeconds > 0) {
            // TERKUNCI
            for (var i = 0; i < finishBtns.length; i++) {
                var btn = finishBtns[i];
                btn.disabled = true;
                btn.classList.add('cbt-finish-locked', 'disabled');
                btn.setAttribute('aria-disabled', 'true');
                btn.setAttribute('title', 'Waktu minimal pengerjaan belum tercapai (Sisa: ' + formatTime(remainingSeconds) + ')');
                if (!btn._cbtClickAttached) {
                    btn._cbtClickAttached = true;
                    btn.addEventListener('click', function(e) {
                        if (remainingSeconds > 0) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            alert('Anda belum dapat menyelesaikan ujian!\n\nKetentuan waktu minimal pengerjaan adalah ' + minMinutes + ' menit.\nSisa waktu: ' + formatTime(remainingSeconds));
                            return false;
                        }
                    }, true);
                }
            }

            if (timerDisplay) {
                timerDisplay.textContent = formatTime(remainingSeconds);
            }

            // Lock modal button if modal is open
            var modalSaveBtn = document.querySelector('[data-region="modal"] [data-action="save"]');
            if (modalSaveBtn) {
                modalSaveBtn.disabled = true;
                modalSaveBtn.classList.add('cbt-finish-locked', 'disabled');
            }
        } else {
            // TERBUKA (WAKTU MINIMAL TERCAPAI)
            for (var j = 0; j < finishBtns.length; j++) {
                var uBtn = finishBtns[j];
                uBtn.disabled = false;
                uBtn.classList.remove('cbt-finish-locked', 'disabled');
                uBtn.removeAttribute('aria-disabled');
                uBtn.removeAttribute('title');
                uBtn.classList.add('cbt-finish-unlocked');
            }

            if (banner) {
                banner.className = 'alert alert-success';
                banner.style.border = '2px solid #10b981';
                banner.style.background = '#ecfdf5';
                banner.style.color = '#065f46';
                banner.style.boxShadow = '0 4px 12px rgba(16,185,129,0.15)';
                banner.innerHTML = '<div style="display:flex;align-items:center;gap:12px">' +
                    '<span style="font-size:26px;line-height:1">✅</span>' +
                    '<div style="flex:1">' +
                        '<div style="font-weight:700;font-size:15px;color:#065f46;margin-bottom:2px">' +
                            'Waktu Minimal Pengerjaan Telah Tercapai!' +
                        '</div>' +
                        '<div style="font-size:13px;color:#047857">' +
                            'Anda sekarang sudah diperbolehkan menekan tombol <strong>Kumpulkan semua dan selesai</strong> di bawah.' +
                        '</div>' +
                    '</div>' +
                '</div>';
            }

            var modalSaveBtnOpen = document.querySelector('[data-region="modal"] [data-action="save"]');
            if (modalSaveBtnOpen) {
                modalSaveBtnOpen.disabled = false;
                modalSaveBtnOpen.classList.remove('cbt-finish-locked', 'disabled');
            }

            var attemptBadge = document.getElementById('cbt-attempt-min-badge');
            if (attemptBadge) {
                attemptBadge.style.background = '#d1fae5';
                attemptBadge.style.border = '1px solid #a7f3d0';
                attemptBadge.style.color = '#065f46';
                attemptBadge.innerHTML = '✅ Siap Selesai';
            }
        }
    }

    function initSummaryBanner() {
        var finishBtns = getFinishButtons();
        var finishForm = document.getElementById('frm-finishattempt');
        var isSummaryUrl = (window.location.href.indexOf('summary.php') !== -1);

        if (!finishForm && finishBtns.length === 0 && !isSummaryUrl) {
            return;
        }

        if (!document.getElementById('cbt-min-finish-banner')) {
            var banner = document.createElement('div');
            banner.id = 'cbt-min-finish-banner';
            banner.className = (remainingSeconds > 0) ? 'alert alert-warning' : 'alert alert-success';
            banner.style.cssText = 'max-width:650px;margin:24px auto 16px auto;padding:16px 20px;border-radius:10px;text-align:left;';

            if (remainingSeconds > 0) {
                banner.style.border = '2px solid #f59e0b';
                banner.style.background = '#fffbeb';
                banner.style.color = '#92400e';
                banner.style.boxShadow = '0 4px 14px rgba(245,158,11,0.18)';
                banner.innerHTML = '<div style="display:flex;align-items:flex-start;gap:14px">' +
                    '<span style="font-size:28px;line-height:1">⏳</span>' +
                    '<div style="flex:1">' +
                        '<div style="font-weight:700;font-size:15px;color:#92400e;margin-bottom:4px">' +
                            'Ketentuan Waktu Minimal: ' + minMinutes + ' Menit' +
                        '</div>' +
                        '<div style="font-size:13px;color:#78350f;line-height:1.45">' +
                            'Sesuai tata tertib ujian, peserta <strong>belum dapat mengumpulkan ujian</strong> sebelum waktu pengerjaan mencapai minimal <strong>' + minMinutes + ' menit</strong>.' +
                        '</div>' +
                        '<div id="cbt-timer-row" style="margin-top:12px;display:inline-flex;align-items:center;gap:10px;background:#fef3c7;border:1px solid #fde68a;padding:8px 14px;border-radius:8px">' +
                            '<span style="font-size:13px;font-weight:600;color:#92400e">Tombol Selesai dapat ditekan dalam:</span>' +
                            '<strong id="cbt-finish-timer-display" style="font-family:\'Courier New\', monospace;font-size:22px;color:#b45309;letter-spacing:1px">' + formatTime(remainingSeconds) + '</strong>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            } else {
                banner.style.border = '2px solid #10b981';
                banner.style.background = '#ecfdf5';
                banner.style.color = '#065f46';
                banner.style.boxShadow = '0 4px 12px rgba(16,185,129,0.15)';
                banner.innerHTML = '<div style="display:flex;align-items:center;gap:12px">' +
                    '<span style="font-size:26px;line-height:1">✅</span>' +
                    '<div style="flex:1">' +
                        '<div style="font-weight:700;font-size:15px;color:#065f46;margin-bottom:2px">' +
                            'Waktu Minimal Pengerjaan Telah Tercapai (' + minMinutes + ' Menit)' +
                        '</div>' +
                        '<div style="font-size:13px;color:#047857">' +
                            'Anda sekarang sudah diperbolehkan menekan tombol <strong>Kumpulkan semua dan selesai</strong> di bawah.' +
                        '</div>' +
                    '</div>' +
                '</div>';
            }

            if (finishForm && finishForm.parentNode) {
                finishForm.parentNode.insertBefore(banner, finishForm);
            } else {
                var submitBtnsEl = document.querySelector('.submitbtns');
                if (submitBtnsEl && submitBtnsEl.parentNode) {
                    submitBtnsEl.parentNode.insertBefore(banner, submitBtnsEl);
                } else {
                    var mainContent = document.getElementById('region-main') || document.querySelector('[role="main"]') || document.body;
                    mainContent.appendChild(banner);
                }
            }
        }

        // Intercept form submit event (standar)
        if (finishForm && !finishForm._cbtLockAttached) {
            finishForm._cbtLockAttached = true;
            finishForm.addEventListener('submit', function(e) {
                if (remainingSeconds > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    alert('Anda belum dapat menyelesaikan ujian!\n\nKetentuan waktu minimal pengerjaan adalah ' + minMinutes + ' menit.\nSisa waktu: ' + formatTime(remainingSeconds));
                    return false;
                }
            }, true);
        }

        // Intercept form.submit() programmatic calls (Moodle AMD submission_confirmation.js bypass)
        if (finishForm && !finishForm._cbtSubmitOverridden) {
            finishForm._cbtSubmitOverridden = true;
            var origSubmit = finishForm.submit.bind(finishForm);
            finishForm.submit = function() {
                if (remainingSeconds > 0) {
                    alert('Anda belum dapat menyelesaikan ujian!\n\nKetentuan waktu minimal pengerjaan adalah ' + minMinutes + ' menit.\nSisa waktu: ' + formatTime(remainingSeconds));
                    return false;
                }
                return origSubmit();
            };
        }

        // Global capturing click listener to prevent Moodle AMD confirmation modal from even opening
        if (!window._cbtClickCaptureAttached) {
            window._cbtClickCaptureAttached = true;
            document.addEventListener('click', function(e) {
                if (remainingSeconds <= 0) return;
                var target = e.target;
                if (!target) return;
                var btn = target.closest('#responseform, #frm-finishattempt button, .btn-finishattempt button, [data-action="save"]');
                if (btn) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    alert('Anda belum dapat menyelesaikan ujian!\n\nKetentuan waktu minimal pengerjaan adalah ' + minMinutes + ' menit.\nSisa waktu: ' + formatTime(remainingSeconds));
                    return false;
                }
            }, true);
        }

        // Notifikasi jika dialihkan kembali dari server-side validation processattempt.php
        if (window.location.search.indexOf('cbt_early=1') !== -1 && !window._cbtEarlyAlertShown) {
            window._cbtEarlyAlertShown = true;
            setTimeout(function() {
                alert('PERINGATAN DARI SERVER:\n\nPengumpulan ujian ditolak karena ketentuan waktu minimal belum tercapai (' + minMinutes + ' menit).\nSilakan teliti kembali jawaban Anda hingga waktu minimal habis.');
            }, 300);
        }

        updateLockUI();
    }

    function initAttemptBadge() {
        var endTestLinks = document.querySelectorAll('.endtestlink a, a.endtestlink, a[href*="summary.php"]');
        if (endTestLinks.length > 0 && !document.getElementById('cbt-attempt-min-badge')) {
            var target = endTestLinks[0];
            var badge = document.createElement('span');
            badge.id = 'cbt-attempt-min-badge';
            badge.style.cssText = 'display:inline-block;font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;margin-left:6px;transition:all 0.3s;vertical-align:middle;';

            if (remainingSeconds > 0) {
                badge.style.background = '#fef3c7';
                badge.style.border = '1px solid #fde68a';
                badge.style.color = '#92400e';
                badge.innerHTML = '⏳ <span id="cbt-attempt-timer">' + formatTime(remainingSeconds) + '</span>';
            } else {
                badge.style.background = '#d1fae5';
                badge.style.border = '1px solid #a7f3d0';
                badge.style.color = '#065f46';
                badge.innerHTML = '✅ Siap Selesai';
            }
            target.appendChild(badge);
        }
    }

    function startTimer() {
        initSummaryBanner();
        initAttemptBadge();

        if (remainingSeconds > 0) {
            var timerInterval = setInterval(function() {
                remainingSeconds--;
                updateLockUI();

                var attemptTimer = document.getElementById('cbt-attempt-timer');
                if (attemptTimer) {
                    if (remainingSeconds > 0) {
                        attemptTimer.textContent = formatTime(remainingSeconds);
                    } else {
                        var badge = document.getElementById('cbt-attempt-min-badge');
                        if (badge) {
                            badge.style.background = '#d1fae5';
                            badge.style.border = '1px solid #a7f3d0';
                            badge.style.color = '#065f46';
                            badge.innerHTML = '✅ Siap Selesai';
                        }
                    }
                }

                if (remainingSeconds <= 0) {
                    clearInterval(timerInterval);
                    updateLockUI();
                }
            }, 1000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startTimer);
    } else {
        startTimer();
    }
})();
</script>
<!-- ===== END CBT KIOSK MINIMUM FINISH TIME LOCK ===== -->
HTML;
}

/**
 * Render anti-cheat UI elements, tab-switch tracker, and proctor unlock modal.
 *
 * @param int $quizid
 * @param int $attemptid
 * @param string $proctorPin Dedicated Master Proctor PIN (different from quiz token)
 * @param bool $isPrivileged True if user is teacher/admin testing the quiz
 * @param int $serverViolations
 * @param bool $serverIsLocked
 * @return string
 */
function local_cbt_kiosk_render_anticheat(int $quizid, int $attemptid, string $proctorPin = 'PROKTOR123', bool $isPrivileged = false, int $serverViolations = 0, bool $serverIsLocked = false): string {
    global $USER;
    $safeProctorPin = htmlspecialchars(strtoupper(trim($proctorPin)), ENT_QUOTES, 'UTF-8');
    $privilegedFlag = $isPrivileged ? 'true' : 'false';
    $serverLockedFlag = $serverIsLocked ? 'true' : 'false';

    // Data Siswa & Jaringan untuk Watermark Anti-Foto & Anti-AI
    $studentFullname = !empty($USER->id) ? fullname($USER) : 'Peserta Ujian';
    $studentUsername = !empty($USER->username) ? $USER->username : '';
    $studentIdNumber = !empty($USER->idnumber) ? $USER->idnumber : '';
    $studentIdentifier = $studentIdNumber ? $studentUsername . ' / ' . $studentIdNumber : $studentUsername;
    $clientIp = getremoteaddr();
    $timestamp = date('d/m/Y H:i');

    $wmLine1 = 'SMKN 1 KEMLAGI • ' . htmlspecialchars($studentFullname, ENT_QUOTES, 'UTF-8');
    $wmLine2 = htmlspecialchars($studentIdentifier . ' • ' . $clientIp . ' • ' . $timestamp, ENT_QUOTES, 'UTF-8');

    // Pola SVG Anti-Kamera Moiré & Anti-AI Prompt Poisoning
    // Menggunakan micro-grid garis bersilangan (sensor moiré interference)
    // dan teks watermark tebal dengan kontras tinggi (AI OCR scrambler)
    $svgRaw = '<svg xmlns="http://www.w3.org/2000/svg" width="380" height="220">' .
        '<defs>' .
          '<pattern id="moireGrid" width="6" height="6" patternUnits="userSpaceOnUse">' .
            '<path d="M 0 0 L 6 6 M 6 0 L 0 6" fill="none" stroke="rgba(239,68,68,0.08)" stroke-width="0.8"/>' .
          '</pattern>' .
        '</defs>' .
        '<rect width="100%" height="100%" fill="url(#moireGrid)"/>' .
        '<text x="20" y="45" fill="#dc2626" fill-opacity="0.18" font-size="11.5" font-family="-apple-system,BlinkMacSystemFont,Roboto,sans-serif" font-weight="900" letter-spacing="1" transform="rotate(-20 20 45)">DOKUMEN RAHASIA • SMKN 1 KEMLAGI • DILARANG DIFOTO</text>' .
        '<text x="20" y="110" fill="#dc2626" fill-opacity="0.22" font-size="14.5" font-family="-apple-system,BlinkMacSystemFont,Roboto,sans-serif" font-weight="900" transform="rotate(-20 20 110)">PESERTA: ' . $wmLine1 . '</text>' .
        '<text x="20" y="175" fill="#0284c7" fill-opacity="0.20" font-size="12" font-family="monospace" font-weight="800" transform="rotate(-20 20 175)">NIS: ' . $wmLine2 . '</text>' .
        '</svg>';
    $svgDataUri = 'data:image/svg+xml;utf8,' . rawurlencode($svgRaw);

    return <<<HTML
<!-- ===== CBT KIOSK ANTI-CHEAT LOCKDOWN SYSTEM ===== -->
<style>
/* ANTI-PHOTO & ANTI-AI WATERMARK: Cetak identitas siswa langsung di atas soal kuis Moodle */
.que .content,
.que .formulation {
    position: relative !important;
    background-image: url('{$svgDataUri}') !important;
    background-repeat: repeat !important;
}

#cbt-screen-watermark {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    height: 100%;
    pointer-events: none !important;
    -webkit-pointer-events: none !important;
    z-index: 9990;
    overflow: hidden;
    background-image: url('{$svgDataUri}');
    background-repeat: repeat;
}

/* MODE PRIVASI ANTI-FOTO HP KEDUA (TOUCH-TO-REVEAL) */
/* Soal kabur secara default. Saat disentuh/ditekan jari, teks menjadi jelas. Saat jari lepas, kembali kabur! */
body.cbt-privacy-mode .que .qtext,
body.cbt-privacy-mode .que .formulation,
body.cbt-privacy-mode .que .ablock {
    filter: blur(8.5px) !important;
    transition: filter 0.18s ease-out;
    user-select: none !important;
    -webkit-user-select: none !important;
}

body.cbt-privacy-mode .que.cbt-revealed .qtext,
body.cbt-privacy-mode .que.cbt-revealed .formulation,
body.cbt-privacy-mode .que.cbt-revealed .ablock,
body.cbt-privacy-mode .que:active .qtext,
body.cbt-privacy-mode .que:active .formulation,
body.cbt-privacy-mode .que:active .ablock {
    filter: none !important;
}

@keyframes cbtPulseGlow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
    50% { box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
}
@keyframes cbtShake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-10px); }
    40%, 80% { transform: translateX(10px); }
}

/* Exam content blurred when locked - DO NOT blur #page to prevent breaking child overlays */
.cbt-exam-locked #page-content,
.cbt-exam-locked #region-main,
.cbt-exam-locked .que,
.cbt-exam-locked form.mform,
.cbt-exam-locked .submitbtns,
.cbt-exam-locked .drawer-content,
.cbt-exam-locked .activity-header,
.cbt-exam-locked .secondary-navigation {
    filter: blur(14px) !important;
    pointer-events: none !important;
    user-select: none !important;
    -webkit-user-select: none !important;
}

/* ANTI-CHEAT & ANTI-AI: Cegah blok/seleksi teks, drag, dan pop-up context menu Android/iOS */
body,
#page,
#page-content,
#region-main,
.que,
.que .content,
.que .qtext,
.que .formulation,
.que .ablock,
.que .answer,
.que .info {
    -webkit-user-select: none !important;
    -moz-user-select: none !important;
    -ms-user-select: none !important;
    user-select: none !important;
    -webkit-touch-callout: none !important; /* Nonaktifkan popup menu long-press iOS & Android */
}

/* Izinkan input form & esai tetap bisa diketik normal */
input, textarea, select, [contenteditable="true"] {
    -webkit-user-select: auto !important;
    -moz-user-select: auto !important;
    user-select: auto !important;
    -webkit-touch-callout: default !important;
}

/* CRITICAL MOBILE FIX: Force overlays, modals, and inputs to be fully interactive and clickable on phones */
#cbt-lock-overlay,
#cbt-lock-overlay *,
#cbt-warning-modal,
#cbt-warning-modal *,
#cbt-status-badge,
#cbt-status-badge * {
    pointer-events: auto !important;
    filter: none !important;
    user-select: auto !important;
    -webkit-user-select: auto !important;
}

#cbt-toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 2147483647;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 90vw;
}
.cbt-toast {
    background: #1e293b;
    border: 1px solid #ef4444;
    color: #fca5a5;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    box-shadow: 0 12px 30px rgba(0,0,0,0.6);
    opacity: 0;
    transform: translateY(-12px);
    transition: all 0.25s ease;
}
.cbt-toast.show {
    opacity: 1;
    transform: translateY(0);
}

/* Modal Overlay base - scrollable on mobile when virtual keyboard opens */
.cbt-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100vw;
    height: 100%;
    min-height: 100vh;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Card inside modal */
.cbt-modal-card {
    background: #1e293b;
    border: 2px solid #ef4444;
    border-radius: 22px;
    max-width: 480px;
    width: 100%;
    padding: 32px 24px;
    text-align: center;
    box-shadow: 0 25px 70px rgba(0,0,0,0.85);
    box-sizing: border-box;
    margin: auto;
}

/* Mobile responsive adjustments */
@media (max-width: 600px) {
    .cbt-modal-overlay {
        padding: 12px 10px !important;
        align-items: flex-start !important;
    }
    .cbt-modal-card {
        padding: 22px 16px !important;
        border-radius: 16px !important;
        margin-top: 10px !important;
        margin-bottom: 20px !important;
    }
    .cbt-card-icon {
        width: 54px !important;
        height: 54px !important;
        font-size: 28px !important;
        margin-bottom: 12px !important;
    }
    .cbt-card-title {
        font-size: 18px !important;
        margin-bottom: 8px !important;
    }
    .cbt-card-desc {
        font-size: 13px !important;
        margin-bottom: 16px !important;
        line-height: 1.5 !important;
    }
    #cbt-status-badge {
        bottom: 10px !important;
        right: 10px !important;
        padding: 6px 12px !important;
        font-size: 11px !important;
        border-radius: 16px !important;
    }
}
</style>

<!-- Watermark Identitas Siswa Anti-Foto & Anti-AI (Overlay) -->
<div id="cbt-screen-watermark" aria-hidden="true"></div>

<div id="cbt-toast-container"></div>

<!-- Floating Status Badge -->
<div id="cbt-status-badge" style="position:fixed;bottom:16px;right:16px;z-index:2147483640;background:#1e293b;border:1px solid #334155;border-radius:24px;padding:8px 16px;display:flex;align-items:center;gap:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:12px;color:#e2e8f0;box-shadow:0 8px 24px rgba(0,0,0,0.5);user-select:none;">
    <span style="width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
    <span style="font-weight:600;">Anti-Cheat Aktif</span>
    <span style="color:#64748b;">|</span>
    <span id="cbt-tab-counter" style="color:#10b981;font-weight:700;">Pelanggaran Tab: 0/3</span>
    <button type="button" id="cbt-privacy-toggle" onclick="window.cbtTogglePrivacy()" style="background:rgba(239,68,68,0.2);border:1px solid rgba(239,68,68,0.5);border-radius:12px;color:#f87171;font-size:11px;font-weight:700;padding:2px 8px;cursor:pointer;display:inline-flex;align-items:center;gap:4px" title="Klik untuk mengaktifkan/menonaktifkan Mode Anti-Foto (Sentuh untuk Membaca)">
        <span id="cbt-privacy-icon">🔒</span> <span id="cbt-privacy-label">Anti-Foto: ON</span>
    </button>
    <span id="cbt-admin-tag" style="display:none;background:#3b82f6;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">MODE PENGUJI</span>
</div>

<!-- Modal Peringatan Berpindah Tab (Pelanggaran 1 & 2) -->
<div id="cbt-warning-modal" class="cbt-modal-overlay" style="z-index:2147483645;background:rgba(15,23,42,0.92);backdrop-filter:blur(8px);">
    <div class="cbt-modal-card" style="animation:cbtShake 0.4s ease-in-out;">
        <div class="cbt-card-icon" style="width:70px;height:70px;border-radius:50%;background:rgba(239,68,68,0.15);border:2px solid #ef4444;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:34px;animation:cbtPulseGlow 1.5s infinite;">
            ⚠️
        </div>
        <h3 id="cbt-warn-title" class="cbt-card-title" style="color:#fff;font-size:20px;font-weight:800;margin:0 0 10px;letter-spacing:0.02em;">PERINGATAN PELANGGARAN TAB</h3>
        <p id="cbt-warn-msg" class="cbt-card-desc" style="color:#cbd5e1;font-size:14px;line-height:1.6;margin:0 0 16px;">
            Anda terdeteksi meninggalkan halaman ujian! Dilarang membuka tab lain, membuka aplikasi lain, atau meminimalkan browser selama ujian berlangsung.
        </p>
        <div id="cbt-warn-rem" style="background:#0f172a;border:1px solid #334155;border-radius:12px;padding:12px 14px;color:#f59e0b;font-size:13.5px;font-weight:700;margin-bottom:18px;">
            Sisa toleransi: 2 kesempatan lagi!
        </div>
        <button type="button" id="cbt-dismiss-warn-btn" style="width:100%;padding:14px;background:#ef4444;border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:background 0.2s;box-shadow:0 4px 14px rgba(239,68,68,0.4);touch-action:manipulation;">
            Saya Mengerti, Kembali ke Ujian
        </button>
        <div id="cbt-admin-dismiss-box" style="display:none;margin-top:10px;">
            <button type="button" id="cbt-admin-dismiss-btn" style="width:100%;padding:10px;background:#334155;border:1px solid #475569;border-radius:8px;color:#94a3b8;font-size:12px;font-weight:600;cursor:pointer;touch-action:manipulation;">
                [Mode Admin/Guru: Abaikan Peringatan]
            </button>
        </div>
    </div>
</div>

<!-- Modal Lembar Ujian Terkunci (Pelanggaran 3x) -->
<div id="cbt-lock-overlay" class="cbt-modal-overlay" style="z-index:2147483646;background:#0f172a;">
    <div class="cbt-modal-card">
        <div class="cbt-card-icon" style="width:76px;height:76px;border-radius:50%;background:rgba(239,68,68,0.15);border:2px solid #ef4444;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:38px;animation:cbtPulseGlow 1.5s infinite;">
            🔒
        </div>
        <h2 class="cbt-card-title" style="color:#ef4444;font-size:23px;font-weight:900;letter-spacing:0.04em;margin:0 0 10px;">LEMBAR UJIAN TERKUNCI!</h2>
        <p class="cbt-card-desc" style="color:#94a3b8;font-size:14px;line-height:1.6;margin:0 0 20px;">
            Anda telah melakukan perpindahan tab sebanyak <strong style="color:#f87171;">3 kali</strong> (melebihi batas toleransi). Lembar ujian Anda telah dikunci oleh sistem.<br><br>
            <span style="color:#f87171;font-weight:700;">Silakan panggil Pengawas / Proktor Ruangan untuk membuka kunci ujian Anda.</span>
        </p>

        <div style="background:#0f172a;border:1px solid #334155;border-radius:14px;padding:18px;text-align:left;">
            <label for="cbt-proctor-pin" style="display:block;font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">
                PIN / Token Pengawas (Buka Kunci)
            </label>

            <!-- Input Container with Password Visibility Toggle for Mobile/HP -->
            <div style="position:relative;margin-bottom:12px;">
                <input type="password"
                       id="cbt-proctor-pin"
                       placeholder="Masukkan PIN / Token Pengawas"
                       autocomplete="off"
                       autocorrect="off"
                       autocapitalize="characters"
                       spellcheck="false"
                       inputmode="text"
                       style="width:100%;padding:14px 44px 14px 14px;background:#1e293b;border:2px solid #475569;border-radius:10px;color:#fff;font-size:16px !important;outline:none;box-sizing:border-box;touch-action:manipulation;-webkit-appearance:none;">
                <button type="button"
                        id="cbt-toggle-pwd"
                        title="Tampilkan PIN"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;padding:8px;line-height:1;touch-action:manipulation;">
                    👁️
                </button>
            </div>

            <div id="cbt-unlock-error" style="display:none;color:#ef4444;font-size:13px;margin-bottom:12px;font-weight:600;"></div>

            <button type="button" id="cbt-unlock-btn" style="width:100%;padding:14px;background:linear-gradient(135deg, #10b981, #059669);border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(16,185,129,0.35);touch-action:manipulation;">
                Buka Kunci Ujian
            </button>

            <div id="cbt-admin-unlock-box" style="display:none;margin-top:12px;">
                <button type="button" id="cbt-admin-unlock-btn" style="width:100%;padding:10px;background:#334155;border:1px solid #475569;border-radius:8px;color:#38bdf8;font-size:12px;font-weight:700;cursor:pointer;touch-action:manipulation;">
                    [Buka Kunci Instan (Mode Penguji/Admin)]
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var currentQuizId    = {$quizid};
    var currentAttemptId = {$attemptid};
    var masterProctorPin = '{$safeProctorPin}';
    var isPrivileged     = {$privilegedFlag};
    var serverViolations = {$serverViolations};
    var serverIsLocked   = {$serverLockedFlag};
    var MAX_VIOLATIONS   = 3;

    // KUNCI PENYIMPANAN HARUS PER-ATEMPT / PER-QUIS, BUKAN PER-HALAMAN NOMOR SOAL!
    // Di Moodle, setiap nomor soal memiliki parameter URL berbeda (&page=0, &page=1, dst.)
    // Dengan kunci 'quiz_X_att_Y', jumlah pelanggaran & status kunci tetap sama di nomor soal manapun!
    var attemptKey = 'quiz_' + currentQuizId + (currentAttemptId > 0 ? '_att_' + currentAttemptId : '');
    var STORAGE_COUNT   = 'cbt_tab_violations_' + attemptKey;
    var STORAGE_LOCKED  = 'cbt_locked_' + attemptKey;

    var localCount = parseInt(sessionStorage.getItem(STORAGE_COUNT) || '0', 10);

    // Jika di database server status pelanggaran sudah direset/bersih (0), sinkronkan dan bersihkan sessionStorage lokal siswa
    if (serverViolations === 0 && !serverIsLocked) {
        sessionStorage.removeItem(STORAGE_COUNT);
        sessionStorage.removeItem(STORAGE_LOCKED);
        localCount = 0;
    }

    var currentViolations = Math.max(localCount, serverViolations);
    var isLocked          = (sessionStorage.getItem(STORAGE_LOCKED) === 'true') || serverIsLocked || (currentViolations >= MAX_VIOLATIONS);

    // Sinkronkan kembali ke sessionStorage
    sessionStorage.setItem(STORAGE_COUNT, currentViolations.toString());
    sessionStorage.setItem(STORAGE_LOCKED, isLocked ? 'true' : 'false');
    var lastTriggerTime   = 0;
    var originalTitle     = document.title;
    var titleInterval     = null;
    var pageLoadTime      = Date.now();
    var isUnloadingOrReloading = false;
    var blurTimer         = null;
    var remoteUnlockTimer = null;
    var GRACE_PERIOD_MS   = 2500; // 2.5 detik grace period saat halaman baru dimuat

    var counterBadge = document.getElementById('cbt-tab-counter');
    var adminTag     = document.getElementById('cbt-admin-tag');
    var warnModal    = document.getElementById('cbt-warning-modal');
    var warnTitle    = document.getElementById('cbt-warn-title');
    var warnRem      = document.getElementById('cbt-warn-rem');
    var lockOverlay  = document.getElementById('cbt-lock-overlay');
    var pinInput     = document.getElementById('cbt-proctor-pin');
    var togglePwd    = document.getElementById('cbt-toggle-pwd');
    var unlockErr    = document.getElementById('cbt-unlock-error');
    var adminDismiss = document.getElementById('cbt-admin-dismiss-box');
    var adminUnlock  = document.getElementById('cbt-admin-unlock-box');

    // CRITICAL FIX FOR MOBILE: Move modals directly under <body> so they escape #page pointer-events or blur
    if (lockOverlay && lockOverlay.parentElement !== document.body) {
        document.body.appendChild(lockOverlay);
    }
    if (warnModal && warnModal.parentElement !== document.body) {
        document.body.appendChild(warnModal);
    }
    var statusBadge = document.getElementById('cbt-status-badge');
    if (statusBadge && statusBadge.parentElement !== document.body) {
        document.body.appendChild(statusBadge);
    }
    var screenWatermark = document.getElementById('cbt-screen-watermark');
    if (screenWatermark && screenWatermark.parentElement !== document.body) {
        document.body.appendChild(screenWatermark);
    }
    // Anti-tamper watermark: pastikan tidak bisa di-remove via inspect element
    if (window.MutationObserver && screenWatermark) {
        var wmObserver = new MutationObserver(function() {
            if (!document.getElementById('cbt-screen-watermark')) {
                document.body.appendChild(screenWatermark);
            }
            screenWatermark.style.display = 'block';
            screenWatermark.style.visibility = 'visible';
            screenWatermark.style.opacity = '1';
        });
        try {
            wmObserver.observe(document.body, { childList: true, subtree: true });
        } catch (e) {}
    }

    // ─── Mode Privasi Anti-Foto HP (Touch-to-Reveal) ───────────────────────────
    var privacyEnabled = (sessionStorage.getItem('cbt_privacy_mode') !== 'false');

    function updatePrivacyModeUI() {
        var icon = document.getElementById('cbt-privacy-icon');
        var label = document.getElementById('cbt-privacy-label');
        var btn = document.getElementById('cbt-privacy-toggle');
        if (privacyEnabled) {
            document.body.classList.add('cbt-privacy-mode');
            if (icon) icon.textContent = '🔒';
            if (label) label.textContent = 'Anti-Foto: ON';
            if (btn) {
                btn.style.background = 'rgba(239,68,68,0.2)';
                btn.style.borderColor = 'rgba(239,68,68,0.5)';
                btn.style.color = '#f87171';
            }
        } else {
            document.body.classList.remove('cbt-privacy-mode');
            if (icon) icon.textContent = '👁️';
            if (label) label.textContent = 'Anti-Foto: OFF';
            if (btn) {
                btn.style.background = 'rgba(100,116,139,0.2)';
                btn.style.borderColor = 'rgba(100,116,139,0.4)';
                btn.style.color = '#94a3b8';
            }
        }
    }

    window.cbtTogglePrivacy = function() {
        privacyEnabled = !privacyEnabled;
        sessionStorage.setItem('cbt_privacy_mode', privacyEnabled ? 'true' : 'false');
        updatePrivacyModeUI();
    };

    updatePrivacyModeUI();

    // Event listener touch/press untuk meng-unblur soal saat jari menempel
    document.querySelectorAll('.que').forEach(function(queEl) {
        var blurTimer = null;

        function reveal() {
            if (!privacyEnabled) return;
            clearTimeout(blurTimer);
            queEl.classList.add('cbt-revealed');
        }

        function conceal() {
            if (!privacyEnabled) return;
            blurTimer = setTimeout(function() {
                queEl.classList.remove('cbt-revealed');
            }, 1200);
        }

        queEl.addEventListener('touchstart', reveal, { passive: true });
        queEl.addEventListener('touchmove', reveal, { passive: true });
        queEl.addEventListener('touchend', conceal, { passive: true });
        queEl.addEventListener('touchcancel', conceal, { passive: true });
        queEl.addEventListener('mousedown', reveal);
        queEl.addEventListener('mouseup', conceal);
        queEl.addEventListener('mouseleave', conceal);
    });

    if (isPrivileged) {
        if (adminTag) adminTag.style.display = 'inline-block';
        if (adminDismiss) adminDismiss.style.display = 'block';
        if (adminUnlock) adminUnlock.style.display = 'block';
    }

    // Toggle password visibility (useful for touchscreen/HP)
    if (togglePwd && pinInput) {
        togglePwd.addEventListener('click', function(e) {
            e.preventDefault();
            if (pinInput.type === 'password') {
                pinInput.type = 'text';
                togglePwd.textContent = '🔒';
                togglePwd.title = 'Sembunyikan PIN';
            } else {
                pinInput.type = 'password';
                togglePwd.textContent = '👁️';
                togglePwd.title = 'Tampilkan PIN';
            }
        });
    }

    function playBeep() {
        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            var ctx = new AudioContext();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(440, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.3);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        } catch (e) {}
    }

    function startTitleAlert() {
        if (titleInterval) return;
        var toggle = false;
        titleInterval = setInterval(function() {
            document.title = toggle ? '⚠️ PERINGATAN KEMBALI KE UJIAN!' : '🚨 DILARANG BUKA TAB LAIN!';
            toggle = !toggle;
        }, 800);
    }

    function stopTitleAlert() {
        if (titleInterval) {
            clearInterval(titleInterval);
            titleInterval = null;
        }
        document.title = originalTitle;
    }

    function updateBadge() {
        if (counterBadge) {
            counterBadge.textContent = 'Pelanggaran Tab: ' + currentViolations + '/' + MAX_VIOLATIONS;
            if (currentViolations === 0) {
                counterBadge.style.color = '#10b981';
            } else if (currentViolations === 1) {
                counterBadge.style.color = '#f59e0b';
            } else {
                counterBadge.style.color = '#ef4444';
            }
        }
    }

    function showToast(message) {
        var container = document.getElementById('cbt-toast-container');
        if (!container) return;
        var toast = document.createElement('div');
        toast.className = 'cbt-toast';
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function() { toast.classList.add('show'); }, 10);
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 300);
        }, 3000);
    }

    // Kirim catatan audit log pelanggaran ke server secara asinkron
    function sendLogToServer(action, count, reason, pin) {
        try {
            var www = (window.M && window.M.cfg && window.M.cfg.wwwroot) ? window.M.cfg.wwwroot : '';
            var logUrl = www + '/local/cbt_kiosk/log_violation.php';
            var payload = {
                action: action,
                violation_count: count,
                reason: reason || '',
                quizid: currentQuizId,
                attemptid: currentAttemptId,
                pin: pin || ''
            };
            if (window.fetch) {
                fetch(logUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).catch(function() {});
            } else if (navigator.sendBeacon) {
                navigator.sendBeacon(logUrl, JSON.stringify(payload));
            }
        } catch(e) {}
    }

    function startRemoteUnlockPoll() {
        if (remoteUnlockTimer || !currentQuizId) return;
        // Cek status buka kunci jarak jauh (Remote Unlock) setiap 3.5 detik
        remoteUnlockTimer = setInterval(function() {
            if (!isLocked) {
                stopRemoteUnlockPoll();
                return;
            }
            var www = (window.M && window.M.cfg && window.M.cfg.wwwroot) ? window.M.cfg.wwwroot : '';
            var url = www + '/local/cbt_kiosk/check_status.php?quizid=' + currentQuizId + '&t=' + Date.now();
            if (window.fetch) {
                fetch(url)
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data && data.status === 'ok' && data.locked === false) {
                            stopRemoteUnlockPoll();
                            unlockSuccess(data.message || 'Ujian telah dibuka kembali oleh Pengawas dari Dashboard.');
                        }
                    })
                    .catch(function() {});
            }
        }, 3500);
    }

    function stopRemoteUnlockPoll() {
        if (remoteUnlockTimer) {
            clearInterval(remoteUnlockTimer);
            remoteUnlockTimer = null;
        }
    }

    function lockExam() {
        isLocked = true;
        sessionStorage.setItem(STORAGE_LOCKED, 'true');
        if (warnModal) warnModal.style.display = 'none';
        if (lockOverlay) {
            lockOverlay.style.display = 'flex';
            lockOverlay.scrollTop = 0;
        }
        document.body.classList.add('cbt-exam-locked');
        updateBadge();
        playBeep();
        sendLogToServer('locked', MAX_VIOLATIONS, 'Ujian terkunci otomatis (3x pelanggaran tab/aplikasi)');
        startRemoteUnlockPoll();
        if (pinInput) {
            pinInput.value = '';
            // Only auto-focus on non-touch devices to avoid unexpected viewport jumping on HP
            if (!('ontouchstart' in window) && window.innerWidth > 768) {
                pinInput.focus();
            }
        }
    }

    function showWarning(count) {
        if (isLocked) return;
        var remaining = MAX_VIOLATIONS - count;
        if (warnTitle) warnTitle.textContent = 'PERINGATAN PELANGGARAN TAB (#' + count + ' / ' + MAX_VIOLATIONS + ')';
        if (warnRem) warnRem.textContent = 'Sisa toleransi: ' + remaining + ' kesempatan lagi! Pelanggaran ke-3 akan MENGUNCI ujian.';
        if (warnModal) {
            warnModal.style.display = 'flex';
            warnModal.scrollTop = 0;
        }
        updateBadge();
        playBeep();
        sendLogToServer('warning_' + count, count, 'Peringatan #' + count + ' pelanggaran tab/aplikasi');
    }

    function triggerViolation(reason) {
        if (isUnloadingOrReloading || isLocked) {
            return;
        }
        // Jangan catat pelanggaran selama masa toleransi awal setelah reload/load halaman
        if (Date.now() - pageLoadTime < GRACE_PERIOD_MS) {
            return;
        }

        var now = Date.now();
        // Debounce 1.5 detik agar tidak memicu pelanggaran berulang kali dalam 1 aksi
        if (now - lastTriggerTime < 1500) {
            return;
        }
        lastTriggerTime = now;

        currentViolations++;
        sessionStorage.setItem(STORAGE_COUNT, currentViolations.toString());

        if (currentViolations >= MAX_VIOLATIONS) {
            lockExam();
        } else {
            showWarning(currentViolations);
        }
    }

    // Check state on load
    if (isLocked || currentViolations >= MAX_VIOLATIONS) {
        lockExam();
    } else {
        updateBadge();
    }

    // Dismiss warning button
    var dismissBtn = document.getElementById('cbt-dismiss-warn-btn');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (warnModal) warnModal.style.display = 'none';
            stopTitleAlert();
        });
    }

    // Admin direct dismiss button
    var adminDismissBtn = document.getElementById('cbt-admin-dismiss-btn');
    if (adminDismissBtn) {
        adminDismissBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (warnModal) warnModal.style.display = 'none';
            stopTitleAlert();
            showToast('Peringatan diabaikan (Mode Penguji/Admin).');
        });
    }

    // Admin instant unlock button
    var adminUnlockBtn = document.getElementById('cbt-admin-unlock-btn');
    if (adminUnlockBtn) {
        adminUnlockBtn.addEventListener('click', function(e) {
            e.preventDefault();
            unlockSuccess('Kunci dibuka via Mode Penguji/Admin.');
        });
    }

    // Proctor unlock verification
    var unlockBtn = document.getElementById('cbt-unlock-btn');
    if (unlockBtn) {
        unlockBtn.addEventListener('click', function(e) {
            e.preventDefault();
            performUnlock();
        });
    }
    if (pinInput) {
        pinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performUnlock();
            }
        });
    }

    function unlockSuccess(msg) {
        isLocked = false;
        sessionStorage.setItem(STORAGE_LOCKED, 'false');
        stopRemoteUnlockPoll();
        // Beri siswa 1 kesempatan terakhir (pelanggaran disetel ke 2/3)
        currentViolations = MAX_VIOLATIONS - 1;
        sessionStorage.setItem(STORAGE_COUNT, currentViolations.toString());

        if (lockOverlay) lockOverlay.style.display = 'none';
        if (splitModal) splitModal.style.display = 'none';
        document.body.classList.remove('cbt-exam-locked');
        if (unlockErr) unlockErr.style.display = 'none';
        if (pinInput) pinInput.value = '';
        stopTitleAlert();
        updateBadge();
        showToast(msg || 'Ujian berhasil dibuka kembali oleh Pengawas. Harap fokus pada ujian!');
        if (fsSupported && !isFsActive()) {
            if (fsModal) fsModal.style.display = 'flex';
        }
    }

    function performUnlock() {
        var entered = (pinInput ? pinInput.value : '').trim().toUpperCase();
        if (!entered) {
            if (unlockErr) {
                unlockErr.textContent = 'Silakan masukkan PIN Pengawas Ruangan.';
                unlockErr.style.display = 'block';
            }
            return;
        }

        // Daftar PIN Pengawas yang sah (Master PIN)
        var validPins = ['PROKTOR123', 'CBT2026', '123456'];
        if (masterProctorPin && masterProctorPin.length > 0) {
            var normPin = masterProctorPin.toUpperCase();
            if (validPins.indexOf(normPin) === -1) {
                validPins.push(normPin);
            }
        }

        // CATATAN PENTING: Token soal TIDAK DIIZINKAN untuk membuka kunci!
        // Siswa tidak bisa menggunakan token kuis untuk membuka layar sendiri.

        var isCorrect = validPins.indexOf(entered) !== -1;

        if (isCorrect) {
            sendLogToServer('unlocked', MAX_VIOLATIONS, 'Ujian dibuka kembali oleh Pengawas (PIN)', entered);
            unlockSuccess('Ujian berhasil dibuka kembali oleh Pengawas. Harap fokus!');
        } else {
            // Verifikasi lanjutan ke server (siapa tahu ada PIN kustom terbaru di DB)
            verifyUnlockWithServer(entered);
        }
    }

    function verifyUnlockWithServer(entered) {
        var www = (window.M && window.M.cfg && window.M.cfg.wwwroot) ? window.M.cfg.wwwroot : '';
        var logUrl = www + '/local/cbt_kiosk/log_violation.php';

        if (!window.fetch) {
            showUnlockError('PIN Pengawas salah! Token soal tidak dapat digunakan untuk membuka kunci.');
            return;
        }

        fetch(logUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'unlocked',
                quizid: currentQuizId,
                attemptid: currentAttemptId,
                pin: entered,
                violation_count: MAX_VIOLATIONS
            })
        }).then(function(res) {
            return res.json();
        }).then(function(data) {
            if (data && data.success) {
                unlockSuccess('Ujian berhasil dibuka kembali oleh Pengawas.');
            } else {
                showUnlockError((data && data.message) ? data.message : 'PIN Pengawas salah! Token soal tidak dapat digunakan untuk membuka kunci.');
            }
        }).catch(function() {
            showUnlockError('PIN Pengawas salah! Token soal tidak dapat digunakan untuk membuka kunci.');
        });
    }

    function showUnlockError(msg) {
        if (unlockErr) {
            unlockErr.textContent = msg;
            unlockErr.style.display = 'block';
        }
        if (pinInput) {
            pinInput.focus();
            pinInput.select();
        }
    }

    // Tangani reload, refresh, form submit, dan navigasi legal agar TIDAK memicu pelanggaran
    window.addEventListener('beforeunload', function() {
        isUnloadingOrReloading = true;
        clearTimeout(blurTimer);
    });
    window.addEventListener('pagehide', function() {
        isUnloadingOrReloading = true;
        clearTimeout(blurTimer);
    });
    document.addEventListener('submit', function() {
        isUnloadingOrReloading = true;
        clearTimeout(blurTimer);
    });

    // Tangani klik pada tombol navigasi soal kuis Moodle (Next, Prev, No Soal, Selesai)
    document.addEventListener('click', function(e) {
        var target = e.target;
        if (!target) return;
        var navEl = (target.closest) ? target.closest('a, button, input[type="submit"], .qnbutton, .submitbtns, .mod_quiz-next-nav') : null;
        if (navEl) {
            var href = navEl.getAttribute('href') || '';
            if (href.indexOf('mod/quiz') !== -1 || navEl.type === 'submit' || (navEl.classList && navEl.classList.contains('qnbutton'))) {
                isUnloadingOrReloading = true;
                clearTimeout(blurTimer);
            }
        }
    }, true);

    // 1. Detect Tab Visibility Change (Pindah Tab / Minimize Browser)
    document.addEventListener('visibilitychange', function() {
        if (isUnloadingOrReloading) return;

        if (document.hidden) {
            startTitleAlert();
            triggerViolation('Pindah tab atau meminimalkan browser');
        } else {
            stopTitleAlert();
            clearTimeout(blurTimer);
            // Saat kembali ke tab ujian, pastikan modal peringatan/kunci langsung tampil
            if (isLocked) {
                if (lockOverlay) lockOverlay.style.display = 'flex';
            } else if (currentViolations > 0 && currentViolations < MAX_VIOLATIONS) {
                if (warnModal) warnModal.style.display = 'flex';
            }
        }
    });

    // 2. Detect Window Blur (Aplikasi lain terbuka / Alt+Tab / Floating Window / Sidebar)
    // Delay 700ms aman dari jeda klik navigasi tombol soal tapi tetap responsif menangkap floating app
    window.addEventListener('blur', function() {
        if (isUnloadingOrReloading || isLocked) return;
        if (Date.now() - pageLoadTime < GRACE_PERIOD_MS) return;

        // Jika tab benar-benar tersembunyi, visibilitychange yang akan menanganinya
        if (document.hidden) {
            triggerViolation('Aplikasi berpindah ke latar belakang');
            return;
        }

        clearTimeout(blurTimer);
        blurTimer = setTimeout(function() {
            if (!isUnloadingOrReloading && !document.hasFocus() && !isLocked) {
                triggerViolation('Fokus jendela ujian hilang (membuka aplikasi lain / floating window)');
            }
        }, 700);
    });

    window.addEventListener('focus', function() {
        clearTimeout(blurTimer);
        stopTitleAlert();
        if (isLocked) {
            if (lockOverlay) lockOverlay.style.display = 'flex';
        } else if (currentViolations > 0 && currentViolations < MAX_VIOLATIONS) {
            if (warnModal) warnModal.style.display = 'flex';
        }
    });

    // 3. Block Right-Click Context Menu
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        showToast('Klik kanan dinonaktifkan demi integritas ujian.');
        return false;
    });

    // 4. Block Copy, Cut, Paste
    document.addEventListener('copy', function(e) {
        e.preventDefault();
        showToast('Fungsi salin (Copy) dinonaktifkan.');
        return false;
    });
    document.addEventListener('cut', function(e) { e.preventDefault(); return false; });
    document.addEventListener('paste', function(e) {
        e.preventDefault();
        showToast('Fungsi tempel (Paste) dinonaktifkan.');
        return false;
    });

    // 5. Block Keyboard Shortcuts (F12, Inspect, Print, View Source, Save) tapi izinkan Reload F5/Ctrl+R
    document.addEventListener('keydown', function(e) {
        // Izinkan F5 dan Ctrl+R untuk refresh tanpa memicu pelanggaran
        if (e.key === 'F5' || e.keyCode === 116 || ((e.ctrlKey || e.metaKey) && (e.key === 'r' || e.key === 'R'))) {
            isUnloadingOrReloading = true;
            clearTimeout(blurTimer);
            return; // Biarkan browser me-reload secara wajar
        }

        // F12 Developer Tools
        if (e.key === 'F12' || e.keyCode === 123) {
            e.preventDefault();
            showToast('Fitur Developer Tools dinonaktifkan.');
            return false;
        }
        // Ctrl+Shift+I, J, C (Inspect Elements)
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) {
            e.preventDefault();
            showToast('Fitur Inspeksi Elemen dinonaktifkan.');
            return false;
        }
        // Ctrl+U (View Source)
        if (e.ctrlKey && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }
        // Ctrl+P (Print)
        if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }
        // Ctrl+S (Save Page)
        if (e.ctrlKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            return false;
        }
    });

    // 6. Block SelectStart & DragStart (Anti-AI Copy, Highlight & Drag)
    document.addEventListener('selectstart', function(e) {
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable)) {
            return true;
        }
        e.preventDefault();
        return false;
    });
    document.addEventListener('dragstart', function(e) {
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable)) {
            return true;
        }
        e.preventDefault();
        return false;
    });

})();
</script>
<!-- ===== END CBT KIOSK ANTI-CHEAT ===== -->
HTML;
}
