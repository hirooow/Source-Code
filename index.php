<?php
// ============================================================
// index.php — Admin Login
// Features: Math CAPTCHA, login attempt limiting, OTP password reset
// ============================================================

ini_set('session.cookie_httponly',  1);
ini_set('session.use_strict_mode',  1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime',   28800);
ini_set('session.cookie_lifetime',  0);
ini_set('session.cookie_path',     '/');
session_name('dcms_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

require_once 'includes/db.php';

function validate_password(string $pw): ?string {
    if (strlen($pw) < 8 || strlen($pw) > 18)
        return 'Password must be between 8 and 18 characters.';
    if (!preg_match('/[A-Z]/', $pw))
        return 'Password must contain at least one uppercase letter (A–Z).';
    if (!preg_match('/[a-z]/', $pw))
        return 'Password must contain at least one lowercase letter (a–z).';
    if (!preg_match('/[0-9]/', $pw))
        return 'Password must contain at least one number (0–9).';
    if (!preg_match('/[^A-Za-z0-9]/', $pw))
        return 'Password must contain at least one special character (e.g. @, #, $, !).';
    return null;
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    300);
define('RESET_TOKEN_TTL',    600);
define('OTP_TTL',            300);
define('OTP_MAX_ATTEMPTS',     5);

// ============================================================
// MATH CAPTCHA — only generate once per session (bug fix)
// ============================================================
function generate_captcha() {
    $n1 = rand(1, 9);
    $n2 = rand(1, 9);
    $_SESSION['captcha_answer'] = $n1 + $n2;
    $_SESSION['captcha_n1']     = $n1;
    $_SESSION['captcha_n2']     = $n2;
}

if (!isset($_SESSION['captcha_answer'])) {
    generate_captcha();
}

$view  = $_GET['view'] ?? 'login';
$token = trim($_GET['token'] ?? '');

$error   = '';
$success = '';

// ============================================================
// VIEW: OTP VERIFY
// ============================================================
if ($view === 'otp_reset') {

    if (empty($_SESSION['pending_reset_otp'])) {
        ?><!DOCTYPE html><html lang="en"><head>
        <meta charset="UTF-8"><meta http-equiv="refresh" content="4;url=index.php">
        <title>Check your contacts | <?php echo APP_NAME; ?></title>
        <link rel="stylesheet" href="assets/css/style.css">
        </head><body class="login-page">
        <div style="text-align:center;padding:60px 20px;">
            <i class="bi bi-envelope-check" style="font-size:3rem;color:var(--blue-500);"></i>
            <h5 style="margin-top:16px;">Check your phone &amp; email</h5>
            <p style="color:var(--gray-500);font-size:0.9rem;">If an account with that username or email exists, an OTP has been sent.<br>Redirecting to login…</p>
        </div>
        <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
        </body></html><?php
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['resend_otp'])) {
            unset($_SESSION['pending_reset_otp'], $_SESSION['otp_reset_attempts']);
            header('Location: index.php?view=forgot'); exit();
        }

        $entered = trim($_POST['otp'] ?? '');
        $pending = $_SESSION['pending_reset_otp'];

        $_SESSION['otp_reset_attempts'] = ($_SESSION['otp_reset_attempts'] ?? 0) + 1;

        if ($_SESSION['otp_reset_attempts'] > OTP_MAX_ATTEMPTS) {
            unset($_SESSION['pending_reset_otp'], $_SESSION['otp_reset_attempts']);
            $error = 'Too many incorrect attempts. Please request a new OTP.';
            $view  = 'forgot';
        } elseif (time() > $pending['expires']) {
            unset($_SESSION['pending_reset_otp'], $_SESSION['otp_reset_attempts']);
            $error = 'Your OTP has expired. Please request a new one.';
            $view  = 'forgot';
        } elseif ($entered !== $pending['code']) {
            $remaining = OTP_MAX_ATTEMPTS - $_SESSION['otp_reset_attempts'];
            $error = "Incorrect OTP. $remaining attempt(s) remaining.";
        } else {
            unset($_SESSION['pending_reset_otp'], $_SESSION['otp_reset_attempts']);
            $token = $pending['token'];
            header('Location: index.php?view=reset&token=' . urlencode($token)); exit();
        }
    }

// ============================================================
// VIEW: RESET PASSWORD
// ============================================================
} elseif ($view === 'reset') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token      = trim($_POST['token'] ?? '');
        $new_pass   = $_POST['new_password'] ?? '';
        $conf_pass  = $_POST['confirm_password'] ?? '';

        if (empty($token) || empty($new_pass)) {
            $error = 'All fields are required.';
        } elseif ($pw_err = validate_password($new_pass)) {
            $error = $pw_err;
        } elseif ($new_pass !== $conf_pass) {
            $error = 'Passwords do not match.';
        } else {
            $now  = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > ? AND is_active = 1 LIMIT 1");
            if (!$stmt) {
                $error = 'Database error. Please try again.';
            } else {
                $stmt->bind_param('ss', $token, $now);
                $stmt->execute();
                $user_row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$user_row) {
                    $error = 'This reset link has expired or is invalid. Please request a new one.';
                } else {
                    $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                    $upd    = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param('si', $hashed, $user_row['id']);
                        $upd->execute();
                        $upd->close();
                        log_action($conn, $user_row['id'], 'System', 'Password Reset', 'auth', $user_row['id'], 'Password was reset via OTP.');
                        $success = 'Password updated successfully. You can now log in.';
                        $view    = 'login';
                    } else {
                        $error = 'Failed to update password. Please try again.';
                    }
                }
            }
        }
    }

// ============================================================
// VIEW: FORGOT PASSWORD
// ============================================================
} elseif ($view === 'forgot') {

    $db_res  = $conn->query("SELECT DATABASE()");
    $db_name = $db_res ? $db_res->fetch_row()[0] : '';
    if ($db_name) {
        $r1 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='".addslashes($db_name)."' AND TABLE_NAME='users' AND COLUMN_NAME='reset_token' LIMIT 1");
        $r2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='".addslashes($db_name)."' AND TABLE_NAME='users' AND COLUMN_NAME='reset_expires' LIMIT 1");
        if ($r1 && $r1->num_rows === 0) $conn->query("ALTER TABLE users ADD COLUMN reset_token   VARCHAR(64) DEFAULT NULL");
        if ($r2 && $r2->num_rows === 0) $conn->query("ALTER TABLE users ADD COLUMN reset_expires DATETIME    DEFAULT NULL");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $identifier = trim($_POST['identifier'] ?? '');

        if (empty($identifier)) {
            $error = 'Please enter your username or email.';
        } else {
            $stmt = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
            if (!$stmt) {
                $error = 'DB Error: ' . $conn->error;
            } else {
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $user_row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user_row) {
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL);
                    $upd     = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param('ssi', $token, $expires, $user_row['id']);
                        $upd->execute();
                        $upd->close();

                        $otp = generate_otp();
                        $_SESSION['pending_reset_otp'] = [
                            'code'    => $otp,
                            'expires' => time() + OTP_TTL,
                            'token'   => $token,
                            'user_id' => $user_row['id'],
                        ];
                        unset($_SESSION['otp_reset_attempts']);

                        send_otp_sms($user_row['phone'], $otp);
                        send_otp_email($user_row['email'], $otp, $user_row['full_name']);

                        log_action($conn, $user_row['id'], $user_row['full_name'], 'Password Reset OTP Sent', 'auth', $user_row['id'], 'OTP sent via SMS and Email.');
                        header('Location: index.php?view=otp_reset'); exit();
                    } else {
                        $error = 'Failed to generate reset token. Please try again.';
                    }
                }
                if (empty($error)) {
                    header('Location: index.php?view=otp_reset'); exit();
                }
            }
        }
    }

// ============================================================
// VIEW: LOGIN (default)
// ============================================================
} else {

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $math_ans = trim($_POST['math_answer'] ?? '');

        // Honeypot bot detection
        if (!empty($_POST['website'])) {
            error_log('[HONEYPOT] Bot detected from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            sleep(3);
            exit();
        }

        // hCaptcha verification (only runs if keys are set)
        $hcaptcha_secret = $_ENV['HCAPTCHA_SECRET'] ?? '';
        if (!empty($hcaptcha_secret)) {
            $hcaptcha_token = $_POST['h-captcha-response'] ?? '';
            if (empty($hcaptcha_token)) {
                $error = 'Please complete the CAPTCHA.';
            } else {
                $hc = curl_init('https://api.hcaptcha.com/siteverify');
                curl_setopt($hc, CURLOPT_POST, true);
                curl_setopt($hc, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($hc, CURLOPT_TIMEOUT, 5);
                curl_setopt($hc, CURLOPT_POSTFIELDS, http_build_query([
                    'secret'   => $hcaptcha_secret,
                    'response' => $hcaptcha_token,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ]));
                $hc_result = curl_exec($hc);
                curl_close($hc);
                $hc_data = json_decode($hc_result, true);
                if (empty($hc_data['success'])) {
                    $error = 'CAPTCHA verification failed. Please try again.';
                    generate_captcha();
                }
            }
        }

        $attempts  = $_SESSION['login_attempts']  ?? 0;
        $last_fail = $_SESSION['last_fail_time']  ?? 0;

        if (empty($error) && $attempts >= MAX_LOGIN_ATTEMPTS) {
            $wait = LOCKOUT_SECONDS - (time() - $last_fail);
            if ($wait > 0) {
                $error = 'Too many failed attempts. Please wait ' . ceil($wait / 60) . ' minute(s) before trying again.';
                generate_captcha();
            } else {
                $_SESSION['login_attempts'] = 0;
                $attempts = 0;
            }
        }

        if (empty($error)) {
            if (empty($username) || empty($password)) {
                $error = 'Please enter your username and password.';
            } elseif (!isset($_SESSION['captcha_answer']) || (int)$math_ans !== (int)$_SESSION['captcha_answer']) {
                $error = 'Incorrect answer to the security question. Please try again.';
                generate_captcha();
                $_SESSION['login_attempts'] = $attempts + 1;
                $_SESSION['last_fail_time'] = time();
            } else {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user && password_verify($password, $user['password'])) {
                    unset($_SESSION['login_attempts'], $_SESSION['last_fail_time'],
                          $_SESSION['captcha_answer'], $_SESSION['captcha_n1'], $_SESSION['captcha_n2']);

                    $_SESSION['user_id']       = $user['id'];
                    $_SESSION['full_name']     = $user['full_name'];
                    $_SESSION['username']      = $user['username'];
                    $_SESSION['role']          = $user['role'];
                    $_SESSION['last_activity'] = time();

                    log_action($conn, $user['id'], $user['full_name'], 'Logged In', 'auth', $user['id'],
                        'Successful login from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                    header('Location: dashboard.php');
                    exit();
                } else {
                    sleep(2); // tarpitting — slows brute-force bots
                    $_SESSION['login_attempts'] = $attempts + 1;
                    $_SESSION['last_fail_time'] = time();
                    $remaining = MAX_LOGIN_ATTEMPTS - $_SESSION['login_attempts'];
                    $error = $remaining > 0
                        ? "Invalid username or password. {$remaining} attempt(s) remaining."
                        : 'Too many failed attempts. Account temporarily locked for 5 minutes.';
                    generate_captcha();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        $titles = ['forgot'=>'Forgot Password','reset'=>'Reset Password','otp_reset'=>'Verify OTP'];
        echo htmlspecialchars($titles[$view] ?? 'Login') . ' | ' . APP_NAME;
        ?>
    </title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if (!empty($_ENV['HCAPTCHA_SITE_KEY'])): ?>
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <?php endif; ?>
    <script>
    (function(){
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.setAttribute('data-theme','dark');
            document.documentElement.setAttribute('data-bs-theme','dark');
        }
    })();
    </script>
    <style>
    /* ── LOGIN PAGE ─────────────────────────────────────────── */
    body.login-page {
        display: flex; align-items: center; justify-content: center;
        min-height: 100vh; margin-left: 0;
        background-color: #1e3a8a;
        background-image:
            linear-gradient(135deg, rgba(15,23,42,0.72) 0%, rgba(30,64,175,0.65) 100%),
            url('assets/images/cap.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
        position: relative;
        overflow: hidden;
    }

    /* Animated glow bubbles */
    body.login-page::before {
        content: '';
        position: fixed; top: -180px; left: -180px;
        width: 520px; height: 520px; border-radius: 50%;
        background: radial-gradient(circle, rgba(37,99,235,0.18) 0%, transparent 70%);
        pointer-events: none;
        animation: floatBubble1 8s ease-in-out infinite;
    }
    body.login-page::after {
        content: '';
        position: fixed; bottom: -150px; right: -150px;
        width: 420px; height: 420px; border-radius: 50%;
        background: radial-gradient(circle, rgba(14,165,233,0.14) 0%, transparent 70%);
        pointer-events: none;
        animation: floatBubble2 10s ease-in-out infinite;
    }
    @keyframes floatBubble1 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        50%       { transform: translate(30px, 20px) scale(1.05); }
    }
    @keyframes floatBubble2 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        50%       { transform: translate(-20px, -30px) scale(1.04); }
    }

    .login-wrap {
        display: flex; width: 100%; max-width: 860px;
        min-height: 540px; border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.35);
        overflow: hidden;
        animation: fadeUp 0.4s ease;
        position: relative; z-index: 1;
    }
    @keyframes fadeUp {
        from { opacity:0; transform:translateY(24px); }
        to   { opacity:1; transform:translateY(0); }
    }

    /* Left branding panel */
    .login-panel {
        width: 42%; background: linear-gradient(160deg,#1d4ed8 0%,#1e40af 50%,#1e3a8a 100%);
        display: flex; flex-direction: column; align-items: center;
        justify-content: center; padding: 48px 36px; position: relative; overflow: hidden;
    }
    .login-panel::before {
        content:''; position:absolute; width:280px; height:280px;
        background:rgba(255,255,255,0.05); border-radius:50%; top:-60px; left:-80px;
    }
    .login-panel::after {
        content:''; position:absolute; width:200px; height:200px;
        background:rgba(255,255,255,0.05); border-radius:50%; bottom:-50px; right:-60px;
    }
    .panel-icon {
        width:72px; height:72px; background:rgba(255,255,255,0.15);
        border-radius:20px; display:flex; align-items:center;
        justify-content:center; font-size:36px; margin-bottom:24px;
        backdrop-filter:blur(4px);
    }
    .panel-title {
        font-size:1.6rem; font-weight:800; color:#fff;
        text-align:center; margin-bottom:10px; letter-spacing:-0.02em;
    }
    .panel-subtitle {
        font-size:0.82rem; color:rgba(255,255,255,0.65);
        text-align:center; line-height:1.6;
    }
    .panel-dots { display:flex; gap:7px; margin-top:32px; }
    .panel-dots span {
        width:8px; height:8px; border-radius:50%;
        background:rgba(255,255,255,0.3);
    }
    .panel-dots span.active { background:#fff; width:22px; border-radius:4px; }

    /* Right form panel */
    .login-form-panel {
        flex:1; background:#fff; padding:48px 44px;
        display:flex; flex-direction:column; justify-content:center;
    }
    .form-heading  { font-size:1.3rem; font-weight:700; color:#111827; margin-bottom:4px; }
    .form-subheading { font-size:0.8rem; color:#9ca3af; margin-bottom:28px; }

    @media (max-width: 640px) {
        /* Hide left blue branding panel */
        .login-panel { display: none; }

        /* Card fills screen with small margin */
        body.login-page { padding: 16px; align-items: flex-start; padding-top: 32px; }
        .login-wrap { max-width: 100%; width: 100%; border-radius: 16px; min-height: 0; }
        .login-form-panel { padding: 32px 22px; }
        .form-heading { font-size: 1.15rem; }

        /* Security check: stack math box above input on small phones */
        .captcha-row { flex-direction: column !important; align-items: stretch !important; }
        .captcha-row > div:first-child { min-width: 0 !important; width: 100% !important; }
        .captcha-row input { max-width: 100% !important; }

        /* Sign In button: taller for fingers */
        .btn.btn-primary.btn-lg { min-height: 48px; font-size: 1rem; }

        /* Fix iOS Safari background-attachment: fixed bug */
        body.login-page { background-attachment: scroll !important; }
    }

    /* ── DARK MODE ──────────────────────────────────────────── */
    /* Keep the background image visible in dark mode, just darken the overlay */
    [data-theme="dark"] body.login-page {
    background-color: #0f172a;
    background-image:
        linear-gradient(135deg, rgba(5,8,20,0.55) 0%, rgba(10,20,50,0.50) 100%),
        url('assets/images/cap.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
  }
    [data-theme="dark"] .login-form-panel { background: #1e293b; }
    [data-theme="dark"] .form-heading     { color: #f1f5f9; }
    [data-theme="dark"] .form-subheading  { color: #64748b; }
    </style>
</head>
<body class="login-page">

<div class="login-wrap">

    <!-- LEFT BRANDING PANEL -->
    <div class="login-panel">
        <div class="panel-icon">🦷</div>
        <div class="panel-title">DentalCare</div>
        <div class="panel-subtitle">Clinic Management System<br>Secure · Reliable · Fast</div>
        <div class="panel-dots">
            <span class="<?php echo $view === 'login' ? 'active' : ''; ?>"></span>
            <span class="<?php echo in_array($view,['forgot','otp_reset']) ? 'active' : ''; ?>"></span>
            <span class="<?php echo $view === 'reset' ? 'active' : ''; ?>"></span>
        </div>
    </div>

    <!-- RIGHT FORM PANEL -->
    <div class="login-form-panel">

    <?php if ($view === 'otp_reset'): ?>
    <div class="form-heading"><i class="bi bi-shield-lock" style="color:#1d4ed8;"></i> Verify OTP</div>
    <div class="form-subheading">Enter the 6-digit code sent to your phone and email</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST" action="index.php?view=otp_reset">
        <div style="margin-bottom:20px;">
            <label class="form-label">Verification Code</label>
            <input type="text" name="otp" class="form-control"
                   maxlength="6" placeholder="• • • • • •"
                   style="text-align:center;font-size:1.8rem;font-weight:700;letter-spacing:0.4em;padding:12px;"
                   required autofocus autocomplete="one-time-code">
            <div style="font-size:0.75rem;color:#9ca3af;margin-top:6px;text-align:center;">
                <i class="bi bi-clock"></i> Code expires in 5 minutes
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;margin-bottom:12px;">
            <i class="bi bi-shield-check"></i> Verify Code
        </button>
    </form>
    <div style="display:flex;gap:12px;margin-top:4px;">
        <a href="index.php" style="flex:1;text-align:center;font-size:0.82rem;color:#6b7280;text-decoration:none;padding:8px;border:1px solid #e5e7eb;border-radius:8px;display:block;">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
        <a href="index.php?view=forgot" style="flex:1;text-align:center;font-size:0.82rem;color:#1d4ed8;text-decoration:none;padding:8px;border:1px solid #dbeafe;border-radius:8px;display:block;background:#eff6ff;">
            <i class="bi bi-arrow-repeat"></i> Request New OTP
        </a>
    </div>

    <?php elseif ($view === 'forgot'): ?>
    <div class="form-heading"><i class="bi bi-key" style="color:#1d4ed8;"></i> Forgot Password</div>
    <div class="form-subheading">Enter your username or email to receive an OTP</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <form method="POST" action="index.php?view=forgot">
        <div style="margin-bottom:18px;">
            <label class="form-label">Username or Email</label>
            <div style="position:relative;">
                <i class="bi bi-person" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="text" name="identifier" class="form-control"
                    placeholder="Enter your username or email"
                    style="padding-left:36px;" required autofocus>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
            <i class="bi bi-send"></i> Send OTP
        </button>
    </form>
    <p style="text-align:center;margin-top:18px;">
        <a href="index.php" style="font-size:0.82rem;color:#6b7280;">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
    </p>

    <?php elseif ($view === 'reset'): ?>
    <div class="form-heading"><i class="bi bi-lock-fill" style="color:#1d4ed8;"></i> Set New Password</div>
    <div class="form-subheading">Choose a strong new password for your account</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php
    $now = date('Y-m-d H:i:s');
    $chk = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > ? AND is_active = 1 LIMIT 1");
    $chk->bind_param('ss', $token, $now);
    $chk->execute();
    $token_valid = (bool)$chk->get_result()->fetch_assoc();
    $chk->close();
    ?>
    <?php if (!$token_valid && !$error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle-fill"></i>
            This reset link is <strong>invalid or has expired</strong>.
            <a href="index.php?view=forgot" style="color:var(--danger);font-weight:600;">Request a new one</a>.
        </div>
    <?php else: ?>
    <form method="POST" action="index.php?view=reset">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <div style="margin-bottom:14px;">
            <label class="form-label">New Password</label>
            <div style="position:relative;">
                <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="password" name="new_password" id="newPassInput" class="form-control"
                       placeholder="Enter new password" style="padding-left:36px;" required minlength="8" autofocus>
                <i class="bi bi-eye" id="toggleNew" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;"></i>
            </div>
        </div>
        <div style="margin-bottom:22px;">
            <label class="form-label">Confirm New Password</label>
            <div style="position:relative;">
                <i class="bi bi-lock-fill" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="password" name="confirm_password" id="confPassInput" class="form-control"
                       placeholder="Repeat new password" style="padding-left:36px;" required>
            </div>
            <div id="matchMsg" style="font-size:0.75rem;margin-top:4px;display:none;"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
            <i class="bi bi-shield-lock-fill"></i> Set New Password
        </button>
    </form>
    <?php endif; ?>
    <p style="text-align:center;margin-top:16px;">
        <a href="index.php" style="font-size:0.82rem;color:#6b7280;">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
    </p>

    <?php else: ?>
    <!-- LOGIN VIEW -->
    <div class="form-heading">Welcome back 👋</div>
    <div class="form-subheading">Sign in to your admin account</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-warning"><i class="bi bi-clock"></i> Your session expired. Please log in again.</div>
    <?php endif; ?>
    <?php
    $attempts  = $_SESSION['login_attempts']  ?? 0;
    $last_fail = $_SESSION['last_fail_time']  ?? 0;
    $locked    = ($attempts >= MAX_LOGIN_ATTEMPTS) && ((time() - $last_fail) < LOCKOUT_SECONDS);
    ?>
    <?php if ($locked): ?>
        <?php $wait_min = ceil((LOCKOUT_SECONDS - (time() - $last_fail)) / 60); ?>
        <div class="alert alert-warning">
            <i class="bi bi-shield-exclamation"></i>
            Account locked. Wait <strong><?php echo $wait_min; ?> minute(s)</strong>.
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php" <?php echo $locked ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
        <!-- Honeypot — invisible to humans, bots fill it -->
        <div style="display:none;visibility:hidden;position:absolute;left:-9999px;" aria-hidden="true">
            <label for="website">Leave this empty</label>
            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
        </div>

        <div style="margin-bottom:14px;">
            <label class="form-label">Username</label>
            <div style="position:relative;">
                <i class="bi bi-person" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="text" name="username" class="form-control"
                       placeholder="Enter username" style="padding-left:36px;"
                       required autofocus
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
        </div>

        <div style="margin-bottom:14px;">
            <label class="form-label">Password</label>
            <div style="position:relative;">
                <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                <input type="password" name="password" id="passwordInput" class="form-control"
                       placeholder="Enter password" style="padding-left:36px;" required>
                <i class="bi bi-eye" id="togglePw"
                   style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;"></i>
            </div>
        </div>

        <div style="margin-bottom:18px;">
            <label class="form-label">
                Security Check
                <span style="font-size:0.72rem;color:#9ca3af;font-weight:400;">— prove you're human</span>
            </label>
            <div style="display:flex;align-items:center;gap:10px;" class="captcha-row">
                <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;
                            padding:10px 18px;font-weight:800;font-size:1.1rem;color:#1d4ed8;
                            white-space:nowrap;flex-shrink:0;min-width:120px;text-align:center;letter-spacing:0.05em;">
                    <?php echo (int)$_SESSION['captcha_n1']; ?> + <?php echo (int)$_SESSION['captcha_n2']; ?> = ?
                </div>
                <input type="number" name="math_answer" class="form-control"
                       placeholder="Answer" required autocomplete="off"
                       style="max-width:110px;text-align:center;font-size:1rem;font-weight:700;">
            </div>
        </div>

        <?php if (!empty($_ENV['HCAPTCHA_SITE_KEY'])): ?>
        <div style="margin-bottom:18px;">
            <div class="h-captcha" data-sitekey="<?php echo htmlspecialchars($_ENV['HCAPTCHA_SITE_KEY']); ?>"></div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
    </form>

    <p style="text-align:center;margin-top:18px;">
        <a href="index.php?view=forgot" style="font-size:0.82rem;color:#1d4ed8;">
            <i class="bi bi-key"></i> Forgot your password?
        </a>
    </p>
    <?php endif; ?>

    <p style="text-align:center;font-size:0.72rem;color:#d1d5db;margin-top:24px;border-top:1px solid #f3f4f6;padding-top:16px;">
        <?php echo APP_NAME; ?> &copy; <?php echo date('Y'); ?>
    </p>

    </div><!-- end .login-form-panel -->
</div><!-- end .login-wrap -->

<script>
var togPw = document.getElementById('togglePw');
if (togPw) togPw.addEventListener('click', function() {
    var inp = document.getElementById('passwordInput');
    var txt = inp.type === 'text';
    inp.type = txt ? 'password' : 'text';
    this.className = txt ? 'bi bi-eye' : 'bi bi-eye-slash';
    this.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;';
});

var togNew = document.getElementById('toggleNew');
if (togNew) togNew.addEventListener('click', function() {
    var inp = document.getElementById('newPassInput');
    var txt = inp.type === 'text';
    inp.type = txt ? 'password' : 'text';
    this.className = txt ? 'bi bi-eye' : 'bi bi-eye-slash';
    this.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;cursor:pointer;';
});

var confPass = document.getElementById('confPassInput');
var newPass  = document.getElementById('newPassInput');
var matchMsg = document.getElementById('matchMsg');
if (confPass && newPass && matchMsg) {
    function checkMatch() {
        var match = newPass.value === confPass.value;
        matchMsg.style.display = confPass.value ? 'block' : 'none';
        matchMsg.textContent   = match ? '✓ Passwords match' : '✗ Passwords do not match';
        matchMsg.style.color   = match ? 'var(--success)' : 'var(--danger)';
        matchMsg.style.fontWeight = '600';
    }
    confPass.addEventListener('input', checkMatch);
    newPass.addEventListener('input', checkMatch);
}
</script>
</body>
</html>
