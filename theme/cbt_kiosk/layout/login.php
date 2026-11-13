<?php
/**
 * CBT Kiosk Theme - Custom login page layout
 *
 * Renders a minimal, professional Computer-Based Test (CBT) login page
 * instead of the default Moodle login page. The layout bypasses the
 * standard Boost UI and outputs a fully custom HTML page.
 *
 * @package    theme_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $SITE, $OUTPUT, $PAGE, $DB;

// Get school name and logo from Moodle settings
$sitename = format_string($SITE->fullname ?? 'Portal Ujian CBT');
$siteshort = format_string($SITE->shortname ?? 'CBT');

// Get site logo if set
$logourl = $OUTPUT->get_logo_url(null, 90);

// Output the custom CBT kiosk login page
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Ujian CBT &mdash; <?= htmlspecialchars($siteshort) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php echo $OUTPUT->standard_head_html(); ?>
<style>
/* ===== CBT Kiosk Login Page ===== */
* { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    min-height: 100%;
    font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
    background: #0f172a;
    color: #e2e8f0;
}

/* Background pattern */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse at 20% 50%, rgba(99,102,241,0.12) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 50%, rgba(139,92,246,0.08) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

#page-wrapper { position: relative; z-index: 1; }

.cbt-login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
}

.cbt-login-card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 20px;
    padding: 44px 48px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.5);
}

.cbt-logo-wrap {
    text-align: center;
    margin-bottom: 24px;
}
.cbt-logo-wrap img {
    max-height: 72px;
    max-width: 120px;
    border-radius: 12px;
    margin-bottom: 12px;
    display: block;
    margin-left: auto;
    margin-right: auto;
}
.cbt-logo-initials {
    width: 72px;
    height: 72px;
    border-radius: 16px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 800;
    color: white;
    margin: 0 auto 12px;
    letter-spacing: 1px;
}

.cbt-site-name {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    text-align: center;
    line-height: 1.4;
    margin-bottom: 2px;
}

.cbt-site-sub {
    font-size: 12px;
    color: #6366f1;
    text-align: center;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 28px;
}

.cbt-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.cbt-input,
input.cbt-input,
#cbt-login-form input.cbt-input {
    width: 100% !important;
    padding: 12px 16px !important;
    background-color: #0f172a !important;
    background: #0f172a !important;
    border: 1px solid #334155 !important;
    border-radius: 10px !important;
    color: #ffffff !important;
    font-size: 15px !important;
    font-family: inherit !important;
    outline: none !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s, box-shadow 0.2s !important;
    margin-bottom: 16px !important;
    height: auto !important;
}

.cbt-input:focus,
#cbt-login-form input.cbt-input:focus {
    border-color: #6366f1 !important;
    background-color: #0f172a !important;
    color: #ffffff !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.25) !important;
}

.cbt-input::placeholder {
    color: #475569 !important;
}

/* Fix browser autofill styling */
.cbt-input:-webkit-autofill,
.cbt-input:-webkit-autofill:hover, 
.cbt-input:-webkit-autofill:focus,
.cbt-input:-webkit-autofill:active {
    -webkit-text-fill-color: #ffffff !important;
    -webkit-box-shadow: 0 0 0px 1000px #0f172a inset !important;
    box-shadow: 0 0 0px 1000px #0f172a inset !important;
    transition: background-color 5000s ease-in-out 0s !important;
    caret-color: #ffffff !important;
}

.cbt-btn {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border: none;
    border-radius: 10px;
    color: white;
    font-size: 15px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s, opacity 0.2s;
    margin-top: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    letter-spacing: 0.03em;
}
.cbt-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 25px rgba(99,102,241,0.4);
}
.cbt-btn:active { transform: translateY(0); }

.cbt-error {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.3);
    border-radius: 8px;
    color: #fca5a5;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 16px;
    text-align: center;
}

.cbt-footer-note {
    text-align: center;
    font-size: 11px;
    color: #94a3b8;
    margin-top: 24px;
    line-height: 1.6;
    border-top: 1px solid #334155;
    padding-top: 18px;
}

/* Hide Moodle's standard login UI elements we don't want */
.login-container .login-form,
.login-container .login-heading,
.login-container .login-body,
.login-wrapper {
    display: none !important;
}

/* Active quiz indicator banner */
.quiz-active-banner {
    background: rgba(16,185,129,0.1);
    border: 1px solid rgba(16,185,129,0.3);
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #6ee7b7;
}
.quiz-active-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    flex-shrink: 0;
    animation: glow 1.5s ease-in-out infinite;
}
@keyframes glow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); }
    50% { box-shadow: 0 0 0 5px rgba(16,185,129,0); }
}

@media (max-width: 480px) {
    .cbt-login-card { padding: 32px 24px; }
}
</style>
</head>
<body <?php echo $OUTPUT->body_attributes(); ?>>
<?php echo $OUTPUT->standard_top_of_body_html(); ?>

<div id="page-wrapper">
<div class="cbt-login-wrap">
<div class="cbt-login-card">

    <!-- Logo & School Name -->
    <div class="cbt-logo-wrap">
        <?php if ($logourl): ?>
            <img src="<?= $logourl ?>" alt="Logo Sekolah">
        <?php else: ?>
            <div class="cbt-logo-initials">CBT</div>
        <?php endif; ?>
        <div class="cbt-site-name"><?= htmlspecialchars($sitename) ?></div>
        <div class="cbt-site-sub">&#127979; Portal Ujian Berbasis Komputer</div>
    </div>

    <?php
    // Check if there is an active quiz (non-empty password = token active)
    $active_quiz = $DB->get_record_sql(
        "SELECT q.id, q.name FROM {quiz} q WHERE q.password != '' AND q.password IS NOT NULL ORDER BY q.timemodified DESC LIMIT 1"
    );
    if ($active_quiz): ?>
    <div class="quiz-active-banner">
        <div class="quiz-active-dot"></div>
        <span>Ujian <strong><?= htmlspecialchars($active_quiz->name) ?></strong> sedang berlangsung</span>
    </div>
    <?php endif; ?>

    <!-- Custom CBT Login Form -->
    <form method="POST" action="<?= $CFG->wwwroot ?>/login/index.php" id="cbt-login-form" autocomplete="off">
        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
        <input type="hidden" name="logintoken" value="<?= \core\session\manager::get_login_token() ?>">

        <?php
        // Show error from previous failed attempt
        $errormsg = '';
        if (!empty($SESSION->loginerrormsg)) {
            $errormsg = $SESSION->loginerrormsg;
            unset($SESSION->loginerrormsg);
        }
        if ($errormsg): ?>
        <div class="cbt-error">&#x26A0; <?= htmlspecialchars($errormsg) ?></div>
        <?php endif; ?>

        <label class="cbt-label" for="cbt-username">Username / NIS / NISN</label>
        <input type="text" id="cbt-username" name="username"
               class="cbt-input" placeholder="Masukkan username Anda"
               autocomplete="username" autofocus required
               value="<?= isset($frm->username) ? htmlspecialchars($frm->username) : '' ?>">

        <label class="cbt-label" for="cbt-password">Password</label>
        <input type="password" id="cbt-password" name="password"
               class="cbt-input" placeholder="Masukkan password Anda"
               autocomplete="current-password" required>

        <button type="submit" class="cbt-btn" id="cbt-submit-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/>
                <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Masuk &amp; Mulai Ujian
        </button>
    </form>

    <div class="cbt-footer-note">
        Sistem Ujian Berbasis Komputer (CBT)<br>
        Hubungi pengawas jika mengalami masalah login
    </div>

    <!-- Required Moodle placeholder (hidden to keep custom CBT login UI) -->
    <div style="display:none;">
        <?php echo $OUTPUT->main_content(); ?>
    </div>

</div>
</div>
</div>

<script>
// Auto-disable button on submit to prevent double submit
document.getElementById('cbt-login-form').addEventListener('submit', function() {
    var btn = document.getElementById('cbt-submit-btn');
    btn.disabled = true;
    btn.style.opacity = '0.7';
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Memproses...';
});
</script>
<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

<?php echo $OUTPUT->standard_end_of_body_html(); ?>
</body>
</html>

