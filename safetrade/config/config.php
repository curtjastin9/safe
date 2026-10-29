<?php
/**
 * Global app configuration + hardened session bootstrap.
 * Include this at the very top of every entry-point page.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak errors to the browser
ini_set('log_errors', '1');

define('APP_NAME', 'SafeTrade');
define('APP_ENV', getenv('SAFETRADE_ENV') ?: 'development');
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads');

// Session idle timeout: user is auto-logged-out after this many seconds
// of inactivity.
//
// FIX: this was previously 3 minutes (60 * 3), which is far too short
// for the registration wizard (account form -> optional face capture ->
// required ID upload). Anyone who took longer than 3 minutes on any of
// those steps had their whole $_SESSION wiped by the idle-timeout check
// below — including pending_registration_user_id / pending_face_user_id
// — which made it *look* like their registration "didn't save" (the
// user row was actually created, but the wizard could no longer find it
// and the account was orphaned, invisible to the rest of the flow).
// 30 minutes gives real people enough room to find their ID, take a
// selfie, etc. without weakening logged-in-session security meaningfully.
define('SESSION_LIFETIME', 60 * 30);

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 3);

// ---- Base URL of the app on this server ----
// redirect()/asset paths are written as absolute paths starting with "/"
// (e.g. redirect('/admin/index.php')). If the project sits at your web
// server's document root, leave this as ''. If it's in a subfolder
// (e.g. http://192.168.1.8/safetrade/...), set it to '/safetrade' (no
// trailing slash) so those redirects land in the right place.
define('BASE_URL', getenv('SAFETRADE_BASE_URL') ?: '/safetrade');

// ---- Future image-pipeline constants (used once ID verification ships) ----
define('MAX_UPLOAD_MB', 8);              // reject anything larger before processing
define('TARGET_IMAGE_MAX_DIMENSION', 1600); // px, longest side after compression
define('TARGET_JPEG_QUALITY', 78);        // GD quality 0-100, tuned for face-match fidelity

// ---- Secure session cookie params (must run before session_start) ----
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('safetrade_sid');
    session_start();
}

// Idle timeout — if the session has been inactive longer than
// SESSION_LIFETIME, wipe it and force a fresh (logged-out) session.
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/validation.php';
require_once BASE_PATH . '/includes/auth.php';
// ---- Outgoing email (Gmail SMTP, via a Google App Password) ----
// NOTE: this is a real credential sitting in a plain PHP file. Fine for
// local XAMPP development, but before this ever goes anywhere public
// (a shared repo, a real server), move these into actual environment
// variables (Apache SetEnv, or your host's env var settings) instead
// and delete the putenv() calls below.
putenv('SAFETRADE_SMTP_HOST=smtp.gmail.com');
putenv('SAFETRADE_SMTP_PORT=587');
putenv('SAFETRADE_SMTP_USER=jastincurt9@gmail.com');
putenv('SAFETRADE_SMTP_PASS=zzlvyefwurbwtewt');
putenv('SAFETRADE_SMTP_FROM=jastincurt9@gmail.com');
putenv('SAFETRADE_SMTP_FROM_NAME=SafeTrade');

// FIX: a missing or misplaced includes/mailer.php previously took the
// ENTIRE site down with a 500 on every single page (login, browse,
// everything) the instant config.php ran, since a plain require_once
// on a file that doesn't exist is a fatal error. Guard it: if the file
// is present, load it as normal and email works; if it's missing,
// SKIP IT and log why, so every other page keeps working — email
// notifications just quietly no-op instead of taking down the site.
$mailerPath = BASE_PATH . '/includes/mailer.php';
if (is_file($mailerPath)) {
    require_once $mailerPath;
} else {
    error_log('[SafeTrade] includes/mailer.php not found at ' . $mailerPath . ' — email sending disabled.');
    // Stand-in no-op versions so any code calling these still runs
    // instead of crashing with "call to undefined function".
    if (!function_exists('send_email')) {
        function send_email(string $toEmail, string $toName, string $subject, string $bodyText, ?string $bodyHtml = null): bool { return false; }
    }
    if (!function_exists('send_branded_email')) {
        function send_branded_email(string $toEmail, string $toName, string $subject, string $heading, string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): bool { return false; }
    }
    if (!function_exists('smtp_last_error')) {
        function smtp_last_error(): ?string { return 'includes/mailer.php is missing'; }
    }
}

// ---- Marketplace UI v4: inject stylesheet into the user dashboard only ----
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php' && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') === false) {
    ob_start(function (string $buf): string {
        if (stripos($buf, '</head>') === false || stripos($buf, 'marketplace-ui.css') !== false) return $buf;
        $href = rtrim(BASE_URL, '/') . '/assets/css/marketplace-ui.css?v=' . (is_file(BASE_PATH . '/assets/css/marketplace-ui.css') ? filemtime(BASE_PATH . '/assets/css/marketplace-ui.css') : 1);
        return preg_replace('~</head>~i', '<link rel="stylesheet" href="' . $href . '"></head>', $buf, 1);
    });
}

// ---- Admin UI v1: inject stylesheet into admin pages only ----
if (PHP_SAPI !== 'cli' && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) {
    ob_start(function (string $buf): string {
        if (stripos($buf, '</head>') === false || stripos($buf, 'admin-ui.css') !== false) return $buf;
        $f = BASE_PATH . '/assets/css/admin-ui.css';
        $href = rtrim(BASE_URL, '/') . '/assets/css/admin-ui.css?v=' . (is_file($f) ? filemtime($f) : 1);
        return preg_replace('~</head>~i', '<link rel="stylesheet" href="' . $href . '"></head>', $buf, 1);
    });
}
