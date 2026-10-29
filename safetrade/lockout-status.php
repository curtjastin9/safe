<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * lockout-status.php?email=<email>
 *
 * Read-only JSON endpoint the login page polls every second so the
 * countdown always reflects the SERVER's own clock and the SERVER's
 * own login_attempts table — not just a number computed once on page
 * load and then ticked down blindly in JavaScript. This is what makes
 * it a real, live countdown: if the tab was backgrounded, the system
 * clock drifted, or (most importantly) another attempt/lockout event
 * happened server-side, the number shown always matches what
 * attempt_login() would actually decide right now.
 *
 * GET only, no state changes, no CSRF needed. Same rate-limit rules
 * that already gate login (email OR IP) apply here — this endpoint
 * exposes nothing that a failed login attempt on the real form
 * wouldn't already reveal.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$email = strtolower(clean_input($_GET['email'] ?? ''));

if ($email === '') {
    echo json_encode(['locked' => false, 'seconds_left' => 0]);
    exit;
}

$locked = is_login_rate_limited($email);
$secondsLeft = $locked ? login_lockout_seconds_remaining($email) : 0;

echo json_encode([
    'locked'       => $locked,
    'seconds_left' => $secondsLeft,
]);
