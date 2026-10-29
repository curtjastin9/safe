<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * check-auth-version.php — visit this in the browser.
 *
 * Tells you definitively whether the live server is running the
 * updated includes/auth.php (the one with the countdown fix) or a
 * stale copy — no more guessing.
 *
 * Delete this file once you're done debugging.
 */

header('Content-Type: text/plain');

echo "includes/auth.php path:  " . realpath(BASE_PATH . '/includes/auth.php') . "\n";
echo "Last modified:           " . date('Y-m-d H:i:s', filemtime(BASE_PATH . '/includes/auth.php')) . "\n";
echo "Server's current time:   " . date('Y-m-d H:i:s') . "\n\n";

if (defined('SAFETRADE_AUTH_VERSION')) {
    echo "Version marker found:    " . SAFETRADE_AUTH_VERSION . "\n";
    echo "=> The server IS running the updated file.\n\n";
} else {
    echo "Version marker: NOT FOUND.\n";
    echo "=> The server is running an OLD copy of includes/auth.php.\n";
    echo "   Re-copy the fixed file to the path shown above and restart Apache.\n\n";
}

echo "function login_lockout_seconds_remaining() exists: "
    . (function_exists('login_lockout_seconds_remaining') ? 'YES' : 'NO') . "\n\n";

$conn = db();
$res = $conn->query('SELECT email, ip_address, success, attempted_at FROM login_attempts ORDER BY attempted_at DESC LIMIT 10');
echo "Last 10 login_attempts rows (newest first):\n";
while ($row = $res->fetch_assoc()) {
    echo "  " . $row['attempted_at'] . "  success=" . $row['success'] . "  email=" . $row['email'] . "  ip=" . $row['ip_address'] . "\n";
}

if (function_exists('login_lockout_seconds_remaining')) {
    $testEmail = $_GET['email'] ?? 'admin@safetrade.local';
    echo "\nis_login_rate_limited('$testEmail'): " . (is_login_rate_limited($testEmail) ? 'TRUE (blocked)' : 'FALSE (not blocked)') . "\n";
    echo "login_lockout_seconds_remaining('$testEmail'): " . login_lockout_seconds_remaining($testEmail) . " seconds\n";
}
