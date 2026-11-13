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
    }

    // Get Master Proctor Unlock PIN (separate from quiz token)
    $proctorPin = (string)get_config('local_cbt_kiosk', 'proctor_pin');
    if (empty($proctorPin)) {
        $proctorPin = 'PROKTOR123';
    }

    return local_cbt_kiosk_render_anticheat($quizid, $attemptid, $proctorPin, $isPrivileged);
}

/**
 * Render anti-cheat UI elements, tab-switch tracker, and proctor unlock modal.
 *
 * @param int $quizid
 * @param int $attemptid
 * @param string $proctorPin Dedicated Master Proctor PIN (different from quiz token)
 * @param bool $isPrivileged True if user is teacher/admin testing the quiz
 * @return string
 */
function local_cbt_kiosk_render_anticheat(int $quizid, int $attemptid, string $proctorPin = 'PROKTOR123', bool $isPrivileged = false): string {
    $safeProctorPin = htmlspecialchars(strtoupper(trim($proctorPin)), ENT_QUOTES, 'UTF-8');
    $privilegedFlag = $isPrivileged ? 'true' : 'false';

    return <<<HTML
<!-- ===== CBT KIOSK ANTI-CHEAT LOCKDOWN SYSTEM ===== -->
<style>
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

<div id="cbt-toast-container"></div>

<!-- Floating Status Badge -->
<div id="cbt-status-badge" style="position:fixed;bottom:16px;right:16px;z-index:2147483640;background:#1e293b;border:1px solid #334155;border-radius:24px;padding:8px 16px;display:flex;align-items:center;gap:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:12px;color:#e2e8f0;box-shadow:0 8px 24px rgba(0,0,0,0.5);user-select:none;">
    <span style="width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
    <span style="font-weight:600;">Anti-Cheat Aktif</span>
    <span style="color:#64748b;">|</span>
    <span id="cbt-tab-counter" style="color:#10b981;font-weight:700;">Pelanggaran Tab: 0/3</span>
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
    var MAX_VIOLATIONS   = 3;
    var attemptKey      = window.location.pathname + window.location.search;
    var STORAGE_COUNT   = 'cbt_tab_violations_' + attemptKey;
    var STORAGE_LOCKED  = 'cbt_locked_' + attemptKey;

    var currentViolations = parseInt(sessionStorage.getItem(STORAGE_COUNT) || '0', 10);
    var isLocked          = sessionStorage.getItem(STORAGE_LOCKED) === 'true';
    var lastTriggerTime   = 0;
    var originalTitle     = document.title;
    var titleInterval     = null;
    var pageLoadTime      = Date.now();
    var isUnloadingOrReloading = false;
    var blurTimer         = null;
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
        // Beri siswa 1 kesempatan terakhir (pelanggaran disetel ke 2/3)
        currentViolations = MAX_VIOLATIONS - 1;
        sessionStorage.setItem(STORAGE_COUNT, currentViolations.toString());

        if (lockOverlay) lockOverlay.style.display = 'none';
        document.body.classList.remove('cbt-exam-locked');
        if (unlockErr) unlockErr.style.display = 'none';
        if (pinInput) pinInput.value = '';
        stopTitleAlert();
        updateBadge();
        showToast(msg || 'Ujian berhasil dibuka kembali oleh Pengawas. Harap fokus pada ujian!');
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
            validPins.push(masterProctorPin.toUpperCase());
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

    // 2. Detect Window Blur (Aplikasi lain terbuka / Alt+Tab / Split Screen)
    // Menggunakan delay 1.2s agar klik pada tombol reload atau address bar TIDAK dianggap pelanggaran
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
                triggerViolation('Fokus jendela ujian hilang (membuka aplikasi lain)');
            }
        }, 1200);
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

})();
</script>
<!-- ===== END CBT KIOSK ANTI-CHEAT ===== -->
HTML;
}
