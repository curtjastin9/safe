<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Don't show errors in output (break JSON)

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/face_recognition.php';
require_once __DIR__ . '/includes/image_helper.php';

/**
 * Safe JSON response that won't break if there are any warnings
 */
function jexit(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Error handler to prevent HTML output
 */
function error_handler($errno, $errstr, $errfile, $errline)
{
    error_log("[Face API] $errstr in $errfile:$errline");
    // Don't display - just log
}
set_error_handler('error_handler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jexit(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// Get raw input as JSON or form data
$raw = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $input = file_get_contents('php://input');
    if ($input) {
        $raw = json_decode($input, true) ?? [];
    }
} else {
    $raw = $_POST;
}

// Get CSRF token
$csrfToken = $raw['csrf_token'] ?? ($_POST['csrf_token'] ?? null);
if (!$csrfToken) {
    jexit(['ok' => false, 'error' => 'no_csrf'], 419);
}

if (!verify_csrf($csrfToken)) {
    jexit(['ok' => false, 'error' => 'csrf'], 419);
}

$action = $raw['action'] ?? ($_POST['action'] ?? '');

if (!$action) {
    jexit(['ok' => false, 'error' => 'no_action'], 400);
}

try {
    $conn = db();
} catch (Exception $e) {
    error_log('[Face API] Database connection failed: ' . $e->getMessage());
    jexit(['ok' => false, 'error' => 'db_error', 'message' => 'Database connection failed.'], 500);
}

switch ($action) {

    case 'register_face': {
        try {
            // In the new registration flow, the account doesn't exist yet.
            // We store the face embedding in the session so that
            // when register.php step 3 finally creates the account, it can
            // insert the face data at the same time.

            if (empty($_SESSION['pending_registration_data'])) {
                jexit(['ok' => false, 'error' => 'no_pending_registration',
                       'message' => 'Your registration session expired — please sign up again.'], 400);
            }

            $embedding = $raw['embedding'] ?? null;
            if (!is_array($embedding) || count($embedding) !== 128) {
                jexit(['ok' => false, 'error' => 'bad_embedding'], 422);
            }

            // Check if this face already exists in the database
            $match = find_closest_face_match($conn, $embedding, FACE_MATCH_THRESHOLD);
            if ($match) {
                jexit([
                    'ok' => false,
                    'error' => 'duplicate_face',
                    'message' => 'Face already registered. This face is already associated with another SafeTrade account.',
                ]);
            }

            // Store the face embedding in the session temporarily
            $_SESSION['pending_face_embedding'] = $embedding;
            $_SESSION['pending_face_snapshot'] = $raw['snapshot'] ?? null;

            audit_log(0, 'user.face.registered_step', 'system', 0);
            jexit(['ok' => true]);

        } catch (Exception $e) {
            error_log('[Face API] register_face error: ' . $e->getMessage());
            jexit(['ok' => false, 'error' => 'exception', 'message' => 'An error occurred during face verification.'], 500);
        }
    }

    case 'login_with_face': {
        try {
            $embedding = $raw['embedding'] ?? null;
            if (!is_array($embedding) || count($embedding) !== 128) {
                jexit(['ok' => false, 'error' => 'bad_embedding'], 422);
            }

            // FIX: this key is scoped to face attempts and is checked
            // with $matchIp = false. Previously (matchIp defaulting to
            // true) every failed face-login attempt wrote a row with
            // this machine's real IP, which then ALSO satisfied the
            // `ip_address = ?` clause used by password-login's lockout
            // check for this same IP — so retrying face login kept
            // silently re-arming (and refreshing the timer on) the
            // password lockout too, even though the person's password
            // was never actually wrong. Password login is completely
            // unaffected by this change.
            $rateKey = 'face:' . client_ip();
            if (is_login_rate_limited($rateKey, false)) {
                jexit(['ok' => false, 'error' => 'rate_limited',
                       'message' => 'Too many failed attempts. Please try again later, or use email + password.']);
            }

            $match = find_closest_face_match($conn, $embedding);
            if (!$match) {
                record_login_attempt($rateKey, false, false);
                jexit(['ok' => false, 'error' => 'no_match', 'message' => 'Face not recognized.']);
            }

            $stmt = $conn->prepare(
                'SELECT id, first_name, last_name, email, role, status, verification_status
                 FROM users WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param('i', $match['user_id']);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                record_login_attempt($rateKey, false, false);
                jexit(['ok' => false, 'error' => 'no_match', 'message' => 'Face not recognized.']);
            }

            $statusError = login_status_error($user);
            if ($statusError !== null) {
                record_login_attempt($rateKey, false, false);
                jexit(['ok' => false, 'error' => 'account_blocked', 'message' => $statusError]);
            }

            record_login_attempt($rateKey, true, false);
            login_start_session($user);
            audit_log((int) $user['id'], 'user.login.face', 'user', (int) $user['id'], 'distance=' . round($match['distance'], 4));

            jexit(['ok' => true, 'redirect' => home_path_for($user)]);

        } catch (Exception $e) {
            error_log('[Face API] login_with_face error: ' . $e->getMessage());
            jexit(['ok' => false, 'error' => 'exception', 'message' => 'An error occurred during face login.'], 500);
        }
    }

    default:
        jexit(['ok' => false, 'error' => 'unknown_action'], 400);
}
