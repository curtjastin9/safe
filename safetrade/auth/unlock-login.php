<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * unlock-login.php — dev-only utility.
 *
 * Visiting this page in the browser deletes every failed row from
 * login_attempts, instantly clearing every email/IP lockout, so you
 * don't have to open phpMyAdmin every time testing trips the limiter.
 *
 * IMPORTANT: this has no auth check on purpose (you're locked out, so
 * you can't log in to prove who you are). Delete this file — or move
 * it somewhere non-public — once you're done testing; do not deploy it
 * to a real/public server.
 * ---------------------------------------------------------------------
 */

$conn = db();
$conn->query('DELETE FROM login_attempts');
$cleared = $conn->affected_rows;

echo "Cleared {$cleared} failed/old login attempt row(s). All lockouts are reset.<br>";
echo '<a href="auth/login.php">Go to login</a>';
