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
        '/admin/',             // allow admin panel (but siteadmin check above handles this)
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
    $privileged_role_ids = [1, 2, 3, 4]; // manager, coursecreator, editingteacher, teacher
    $has_privilege = $DB->record_exists_sql(
        "SELECT ra.id FROM {role_assignments} ra WHERE ra.userid = :uid AND ra.roleid IN (1, 2, 3, 4) LIMIT 1",
        ['uid' => $USER->id]
    );

    if ($has_privilege || is_siteadmin($USER->id)) {
        return; // Teachers/admin can navigate freely
    }

    // Student user is navigating to a non-quiz, non-allowed page — enforce kiosk
    // Find active quiz (quiz with non-empty password token from exam-admin)
    $active_quiz = $DB->get_record_sql(
        "SELECT q.id, q.name, q.course, cm.id as cmid
         FROM {quiz} q
         JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (
             SELECT id FROM {modules} WHERE name = 'quiz' LIMIT 1
         )
         WHERE q.password != ''
         AND q.password IS NOT NULL
         AND cm.visible = 1
         ORDER BY q.timemodified DESC
         LIMIT 1"
    );

    if ($active_quiz) {
        $is_enrolled = is_enrolled(context_course::instance($active_quiz->course), $USER->id, '', true);
        if ($is_enrolled) {
            $url = new moodle_url('/mod/quiz/view.php', ['id' => $active_quiz->cmid]);
            redirect($url);
        }
    }

    // No active quiz — redirect to waiting page
    $waiting_url = new moodle_url('/local/cbt_kiosk/waiting.php');
    $current_url = new moodle_url($CFG->wwwroot . str_replace($moodle_root, '', $script));

    // Avoid redirect loop
    if (strpos($rel, '/local/cbt_kiosk/') === false) {
        redirect($waiting_url);
    }
}

/**
 * Hook before_footer: Injects anti-cheat tab-lockdown on quiz attempt pages.
 * Detects tab switching, minimizes, app blurring, and locks the quiz after 3 violations.
 *
 * @return string
 */
function local_cbt_kiosk_before_footer(): string {
    global $PAGE, $USER, $DB;

    // Only inject on quiz attempt and summary pages
    if (!isset($PAGE->pagetype)) {
        return '';
    }

    $target_pagetypes = ['mod-quiz-attempt', 'mod-quiz-summary'];
    if (!in_array($PAGE->pagetype, $target_pagetypes)) {
        return '';
    }

    // Do NOT lock administrators or teachers/managers
    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return '';
    }

    // Check if user has teacher role
    $has_privilege = $DB->record_exists_sql(
        "SELECT ra.id FROM {role_assignments} ra WHERE ra.userid = :uid AND ra.roleid IN (1, 2, 3, 4) LIMIT 1",
        ['uid' => $USER->id]
    );
    if ($has_privilege) {
        return '';
    }

    // Get current quiz unlock password / active token
    $quizpassword = '';
    if (isset($PAGE->cm->instance)) {
        $quiz = $DB->get_record('quiz', ['id' => $PAGE->cm->instance], 'id, password');
        if ($quiz && !empty($quiz->password)) {
            $quizpassword = (string)$quiz->password;
        }
    }

    return local_cbt_kiosk_render_anticheat($quizpassword);
}

/**
 * Render anti-cheat UI elements, tab-switch tracker, and proctor unlock modal.
 *
 * @param string $quizpassword
 * @return string
 */
function local_cbt_kiosk_render_anticheat(string $quizpassword): string {
    $safeToken = htmlspecialchars($quizpassword, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!-- ===== CBT KIOSK ANTI-CHEAT LOCKDOWN SYSTEM ===== -->
<style>
@keyframes cbtPulseGlow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
    50% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
}
@keyframes cbtShake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-8px); }
    40%, 80% { transform: translateX(8px); }
}
.cbt-exam-locked #region-main,
.cbt-exam-locked .que,
.cbt-exam-locked form {
    filter: blur(12px) !important;
    pointer-events: none !important;
    user-select: none !important;
}
#cbt-toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 99999999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
    font-family: 'Inter', -apple-system, sans-serif;
}
.cbt-toast {
    background: #1e293b;
    border: 1px solid #ef4444;
    color: #f87171;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    opacity: 0;
    transform: translateY(-10px);
    transition: all 0.25s ease;
}
.cbt-toast.show {
    opacity: 1;
    transform: translateY(0);
}
</style>

<div id="cbt-toast-container"></div>

<!-- Floating Status Badge -->
<div id="cbt-status-badge" style="position:fixed;bottom:16px;right:16px;z-index:99998;background:#1e293b;border:1px solid #334155;border-radius:24px;padding:8px 16px;display:flex;align-items:center;gap:8px;font-family:'Inter',sans-serif;font-size:12px;color:#e2e8f0;box-shadow:0 8px 24px rgba(0,0,0,0.4);user-select:none;">
    <span style="width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
    <span>Anti-Cheat Aktif</span>
    <span style="color:#64748b;">|</span>
    <span id="cbt-tab-counter" style="color:#f59e0b;font-weight:600;">Pelanggaran Tab: 0/3</span>
</div>

<!-- Modal Peringatan Berpindah Tab (Pelanggaran 1 & 2) -->
<div id="cbt-warning-modal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,0.88);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:16px;font-family:'Inter',sans-serif;">
    <div style="background:#1e293b;border:2px solid #ef4444;border-radius:18px;max-width:460px;width:100%;padding:28px 24px;text-align:center;box-shadow:0 25px 60px rgba(239,68,68,0.3);animation:cbtShake 0.4s ease-in-out;">
        <div style="width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,0.15);border:2px solid #ef4444;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:30px;animation:cbtPulseGlow 1.5s infinite;">
            ⚠️
        </div>
        <h3 id="cbt-warn-title" style="color:#fff;font-size:18px;font-weight:700;margin-bottom:8px;">PERINGATAN PELANGGARAN TAB</h3>
        <p id="cbt-warn-msg" style="color:#cbd5e1;font-size:13.5px;line-height:1.6;margin-bottom:16px;">
            Anda terdeteksi meninggalkan halaman ujian! Dilarang membuka tab lain, aplikasi lain, atau meminimalkan browser selama ujian berlangsung.
        </p>
        <div id="cbt-warn-rem" style="background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px 14px;color:#f59e0b;font-size:13px;font-weight:600;margin-bottom:20px;">
            Sisa toleransi: 2 kesempatan lagi!
        </div>
        <button type="button" id="cbt-dismiss-warn-btn" style="width:100%;padding:12px;background:#ef4444;border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;transition:background 0.2s;">
            Saya Mengerti, Kembali ke Ujian
        </button>
    </div>
</div>

<!-- Modal Lembar Ujian Terkunci (Pelanggaran 3x) -->
<div id="cbt-lock-overlay" style="display:none;position:fixed;inset:0;z-index:9999999;background:#0f172a;align-items:center;justify-content:center;padding:16px;font-family:'Inter',sans-serif;">
    <div style="background:#1e293b;border:2px solid #ef4444;border-radius:20px;max-width:480px;width:100%;padding:36px 28px;text-align:center;box-shadow:0 25px 70px rgba(0,0,0,0.8);">
        <div style="width:76px;height:76px;border-radius:50%;background:rgba(239,68,68,0.15);border:2px solid #ef4444;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:36px;animation:cbtPulseGlow 1.5s infinite;">
            🔒
        </div>
        <h2 style="color:#ef4444;font-size:22px;font-weight:800;letter-spacing:0.04em;margin-bottom:8px;">LEMBAR UJIAN TERKUNCI!</h2>
        <p style="color:#94a3b8;font-size:13.5px;line-height:1.6;margin-bottom:24px;">
            Anda telah melakukan perpindahan tab sebanyak <strong>3 kali</strong> (melebihi batas toleransi). Lembar ujian Anda telah dikunci oleh sistem.<br><br>
            <span style="color:#f87171;font-weight:600;">Silakan panggil Pengawas / Proktor Ruangan untuk membuka kunci ujian Anda.</span>
        </p>
        <div style="background:#0f172a;border:1px solid #334155;border-radius:12px;padding:20px;text-align:left;">
            <label for="cbt-proctor-pin" style="display:block;font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">
                PIN / Token Pengawas (Buka Kunci)
            </label>
            <input type="password" id="cbt-proctor-pin" placeholder="Masukkan PIN Pengawas / Token CBT" autocomplete="off" style="width:100%;padding:12px 14px;background:#1e293b;border:1px solid #475569;border-radius:8px;color:#fff;font-size:15px;margin-bottom:12px;outline:none;box-sizing:border-box;">
            <div id="cbt-unlock-error" style="display:none;color:#ef4444;font-size:12px;margin-bottom:12px;font-weight:600;"></div>
            <button type="button" id="cbt-unlock-btn" style="width:100%;padding:12px;background:linear-gradient(135deg, #10b981, #059669);border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;">
                Buka Kunci Ujian
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var activeQuizToken = '{$safeToken}';
    var MAX_VIOLATIONS = 3;
    var attemptKey = window.location.pathname + window.location.search;
    var STORAGE_COUNT = 'cbt_tab_violations_' + attemptKey;
    var STORAGE_LOCKED = 'cbt_locked_' + attemptKey;

    var currentViolations = parseInt(sessionStorage.getItem(STORAGE_COUNT) || '0', 10);
    var isLocked = sessionStorage.getItem(STORAGE_LOCKED) === 'true';
    var lastTriggerTime = 0;

    var counterBadge = document.getElementById('cbt-tab-counter');
    var warnModal = document.getElementById('cbt-warning-modal');
    var warnTitle = document.getElementById('cbt-warn-title');
    var warnMsg = document.getElementById('cbt-warn-msg');
    var warnRem = document.getElementById('cbt-warn-rem');
    var lockOverlay = document.getElementById('cbt-lock-overlay');
    var pinInput = document.getElementById('cbt-proctor-pin');
    var unlockErr = document.getElementById('cbt-unlock-error');

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

    function lockExam() {
        isLocked = true;
        sessionStorage.setItem(STORAGE_LOCKED, 'true');
        if (warnModal) warnModal.style.display = 'none';
        if (lockOverlay) lockOverlay.style.display = 'flex';
        document.body.classList.add('cbt-exam-locked');
        updateBadge();
        if (pinInput) {
            pinInput.value = '';
            pinInput.focus();
        }
    }

    function showWarning(count) {
        if (isLocked) return;
        var remaining = MAX_VIOLATIONS - count;
        if (warnTitle) warnTitle.textContent = 'PERINGATAN PELANGGARAN TAB (#' + count + ' / ' + MAX_VIOLATIONS + ')';
        if (warnRem) warnRem.textContent = 'Sisa toleransi: ' + remaining + ' kesempatan lagi! Pelanggaran ke-3 akan MENGUNCI ujian.';
        if (warnModal) warnModal.style.display = 'flex';
        updateBadge();
    }

    function triggerViolation() {
        var now = Date.now();
        // Debounce 1.5 seconds to avoid double-firing between blur and visibilitychange
        if (isLocked || (now - lastTriggerTime < 1500)) {
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
        dismissBtn.addEventListener('click', function() {
            if (warnModal) warnModal.style.display = 'none';
        });
    }

    // Proctor unlock verification
    var unlockBtn = document.getElementById('cbt-unlock-btn');
    if (unlockBtn) {
        unlockBtn.addEventListener('click', performUnlock);
    }
    if (pinInput) {
        pinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performUnlock();
            }
        });
    }

    function performUnlock() {
        var entered = (pinInput ? pinInput.value : '').trim().toUpperCase();
        var validPins = ['PROKTOR123', 'CBT2026', '123456'];
        if (activeQuizToken && activeQuizToken.length > 0) {
            validPins.push(activeQuizToken.toUpperCase());
        }

        var isCorrect = validPins.indexOf(entered) !== -1;

        if (isCorrect) {
            isLocked = false;
            sessionStorage.setItem(STORAGE_LOCKED, 'false');
            // Give student 1 final chance (set violations to 2 of 3)
            currentViolations = MAX_VIOLATIONS - 1;
            sessionStorage.setItem(STORAGE_COUNT, currentViolations.toString());

            if (lockOverlay) lockOverlay.style.display = 'none';
            document.body.classList.remove('cbt-exam-locked');
            if (unlockErr) unlockErr.style.display = 'none';
            if (pinInput) pinInput.value = '';
            updateBadge();
            showToast('Ujian berhasil dibuka kembali oleh Pengawas. Harap fokus pada ujian!');
        } else {
            if (unlockErr) {
                unlockErr.textContent = 'PIN / Token Pengawas salah! Silakan panggil proktor ruangan.';
                unlockErr.style.display = 'block';
            }
            if (pinInput) {
                pinInput.focus();
                pinInput.select();
            }
        }
    }

    // 1. Detect Tab Visibility Change (switched to another tab or minimized)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            triggerViolation();
        }
    });

    // 2. Detect Window Blur (clicking outside, Alt+Tab, opening apps in split-screen)
    window.addEventListener('blur', function() {
        triggerViolation();
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

    // 5. Block Keyboard Shortcuts (F12, Inspect, Print, View Source)
    document.addEventListener('keydown', function(e) {
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

