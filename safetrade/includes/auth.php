<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * SafeTrade authentication core.
 *
 * Account lifecycle (matches the admin approval flow):
 *  - register_user() creates the account as status='pending_verification',
 *    verification_status='pending' — it lands in the admin Verifications
 *    queue automatically.
 *  - The account CANNOT log in (attempt_login blocks anything that isn't
 *    status='active') until an admin approves it (Verifications tab ->
 *    Approve, or Users tab -> Reactivate), which flips status to 'active'.
 *  - Rejected accounts keep status='pending_manual_review' and
 *    verification_status='rejected' — also blocked from login, per the
 *    flowchart's "please register a new account again" branch.
 *
 * FACE LOGIN NOTE: login_status_error() and login_start_session() below
 * are split out of attempt_login() so face-verify-api.php's
 * "login_with_face" action runs a matched user through the EXACT same
 * suspended/banned/pending gate and session setup as a normal password
 * login — a face match alone never bypasses account status checks.
 *
 * LOCKOUT FIX (this version): is_login_rate_limited(),
 * login_lockout_seconds_remaining(), and record_login_attempt() now take
 * a $matchIp / $trackByIp flag. Face-login failures are keyed as
 * 'face:<ip>' and used to ALWAYS match on ip_address = ? too — which
 * meant every failed face-login attempt silently re-armed the IP-wide
 * password lockout for that machine, so the countdown never reached 0
 * even though the person never mistyped their password. Face-channel
 * attempts now record with no ip_address value and are checked by email
 * key only, so they can never satisfy another lockout's `ip_address = ?`
 * clause. Password login behavior (email OR ip_address) is unchanged.
 *
 * MARKER: SAFETRADE_AUTH_VERSION_4 — used by check-auth-version.php to
 * confirm the server is actually running this file and not a stale copy.
 * ---------------------------------------------------------------------
 */

define('SAFETRADE_AUTH_VERSION', 'SAFETRADE_AUTH_VERSION_4');

/* ===================== Registration ===================== */

/**
 * @return array{success:bool, errors:string[], user_id:?int}
 */
function register_user(string $firstName, string $lastName, string $email, string $phone, string $password, string $passwordConfirm): array
{
    $errors = [];

    $firstName = clean_input($firstName);
    $lastName  = clean_input($lastName);
    $email     = strtolower(clean_input($email));
    $phone     = clean_input($phone);

    if (!is_valid_name($firstName)) $errors[] = 'Please enter a valid first name.';
    if (!is_valid_name($lastName))  $errors[] = 'Please enter a valid last name.';
    if (!is_valid_email($email))    $errors[] = 'Please enter a valid email address.';
    if (!is_valid_phone($phone))    $errors[] = 'Please enter a valid phone number.';
    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';
    $errors = array_merge($errors, password_policy_errors($password));

    if ($errors) {
        return ['success' => false, 'errors' => $errors, 'user_id' => null];
    }

    $conn = db();

    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        return ['success' => false, 'errors' => ['An account with that email already exists.'], 'user_id' => null];
    }
    $stmt->close();

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $uuid = uuid_v4();

    // Account starts pending — an admin must approve it (Verifications or
    // Users tab) before this person can log in.
    $stmt = $conn->prepare(
        "INSERT INTO users (uuid, first_name, last_name, email, phone, password_hash, role, status, verification_status)
         VALUES (?, ?, ?, ?, ?, ?, 'user', 'pending_verification', 'pending')"
    );
    $stmt->bind_param('ssssss', $uuid, $firstName, $lastName, $email, $phone, $hash);

    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        error_log('[SafeTrade] register_user failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Something went wrong. Please try again.'], 'user_id' => null];
    }

    $userId = $conn->insert_id;
    $stmt->close();

    audit_log($userId, 'user.register', 'user', $userId);

    if (function_exists('inbox_push')) {
        inbox_push((int) $userId, 'account', 'Welcome to SafeTrade',
            'Your account is awaiting verification. Trade updates, announcements and maintenance notices will show up here.', null, null, 'welcome');
        inbox_push_staff('account', 'New registration: ' . trim($firstName . ' ' . $lastName),
            'A new account is waiting for verification.', '/admin/dashboard.php?tab=verifications', (int) $userId, 'staff_registration');
    }

    return ['success' => true, 'errors' => [], 'user_id' => $userId];
}

/* ===================== Rate limiting ===================== */

/**
 * REWRITE: the previous version picked a specific "offset" row (the
 * Nth-most-recent failure) to decide when the lockout expires, with a
 * fallback chain that — if that offset row ever came back empty for any
 * reason — silently returned a hardcoded "$lockoutMinutes * 60" as a
 * last resort. That meant it could report a full, fresh lockout window
 * indefinitely, no matter how much real time had passed: exactly the
 * "still says 3 minutes no matter how long I wait" bug.
 *
 * This version has ONE rule, computed in a single query, always on
 * MySQL's own clock: the lockout is in effect only while there have
 * been at least MAX_LOGIN_ATTEMPTS failures AND the single MOST RECENT
 * failure is still within the last LOGIN_LOCKOUT_MINUTES minutes. The
 * remaining time is always "that most recent failure's timestamp +
 * LOGIN_LOCKOUT_MINUTES − now", clamped to zero. There is no other code
 * path and no fallback that can return anything else — once
 * LOGIN_LOCKOUT_MINUTES minutes have actually passed since the last
 * failed attempt, this always returns 0, guaranteed.
 *
 * @param string $key     The rate-limit key — a real email for password
 *                         login, or 'face:<ip>' for face login.
 * @param bool   $matchIp  When true (password login), a row also counts
 *                         if it matches this request's IP, even if its
 *                         email differs (catches someone hammering
 *                         different emails from the same machine). When
 *                         false (face login), ONLY the exact key/email
 *                         match counts — face attempts never spill over
 *                         into an IP-wide password lockout.
 */
function login_lockout_seconds_remaining(string $key, bool $matchIp = true): int
{
    $conn = db();
    $lockoutMinutes = LOGIN_LOCKOUT_MINUTES;

    // GREATEST(0, ...) means a stale/aged-out most-recent-failure can
    // never come back negative-then-misread; it just reads as 0.
    if ($matchIp) {
        $ip = client_ip();
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS attempts,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ? MINUTE))) AS secs_left
             FROM login_attempts
             WHERE success = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND (email = ? OR ip_address = ?)'
        );
        $stmt->bind_param('iiss', $lockoutMinutes, $lockoutMinutes, $key, $ip);
    } else {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS attempts,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ? MINUTE))) AS secs_left
             FROM login_attempts
             WHERE success = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND email = ?'
        );
        $stmt->bind_param('iis', $lockoutMinutes, $lockoutMinutes, $key);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attempts = (int) ($row['attempts'] ?? 0);
    $secsLeft = (int) ($row['secs_left'] ?? 0);

    // Not locked at all unless BOTH the count threshold is met AND
    // there's genuinely time left on the most recent failure's window.
    return ($attempts >= MAX_LOGIN_ATTEMPTS && $secsLeft > 0) ? $secsLeft : 0;
}

/**
 * Thin wrapper kept for readability at call sites — "is this key
 * currently locked out" is just "is there time left on it".
 */
function is_login_rate_limited(string $key, bool $matchIp = true): bool
{
    return login_lockout_seconds_remaining($key, $matchIp) > 0;
}

/**
 * @param string $key        Real email for password login, or
 *                            'face:<ip>' for face login.
 * @param bool   $trackByIp   When true (password login), the real client
 *                             IP is stored on the row, which is what lets
 *                             is_login_rate_limited($matchIp=true) catch
 *                             the same machine hammering different
 *                             emails. When false (face login), the
 *                             ip_address column is left blank so this
 *                             row can never satisfy some OTHER key's
 *                             `ip_address = ?` lockout clause.
 */
function record_login_attempt(string $key, bool $success, bool $trackByIp = true): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)');
    $ip = $trackByIp ? client_ip() : '';
    $successInt = $success ? 1 : 0;
    $stmt->bind_param('ssi', $key, $ip, $successInt);
    $stmt->execute();
    $stmt->close();
}

/* ===================== Login / logout ===================== */

/**
 * Returns a user-facing error message if this account isn't allowed to
 * log in right now (suspended / banned / still pending admin review),
 * or null if it's clear to proceed. Shared by password login and face
 * login so neither path can skip the other's account-status rules.
 */
function login_status_error(array $user): ?string
{
    if ($user['status'] === 'suspended') {
        return 'Your account is suspended. Contact support for help.';
    }
    if ($user['status'] === 'banned') {
        return 'Your account has been banned.';
    }
    if ($user['status'] === 'pending_verification') {
        return 'Your account is awaiting admin verification. You\'ll be able to log in once it\'s approved.';
    }
    if ($user['status'] === 'pending_manual_review') {
        return 'Your verification was not approved. Please contact support or register a new account.';
    }
    if ($user['status'] !== 'active') {
        return 'Your account is not active yet. Please contact support.';
    }
    return null;
}

/**
 * Establishes the logged-in session for an already-authenticated user
 * (password verified, or a face match that passed login_status_error()).
 * Session fixation is prevented and, where the user_sessions table
 * exists, a revocable session row is recorded — identical to what
 * attempt_login() always did for password logins.
 */
function login_start_session(array $user, bool $remember = false): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']             = (int) $user['id'];
    $_SESSION['user_first_name']     = $user['first_name'];
    $_SESSION['user_last_name']      = $user['last_name'];
    $_SESSION['user_email']          = $user['email'];
    $_SESSION['user_role']           = $user['role'];
    $_SESSION['verification_status'] = $user['verification_status'] ?? 'not_started';

    $sessionToken = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + ($remember ? 60 * 60 * 24 * 30 : SESSION_LIFETIME));
    $ip = client_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    try {
        $stmt = db()->prepare(
            'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issss', $user['id'], $sessionToken, $ip, $ua, $expires);
        $stmt->execute();
        $stmt->close();
        $_SESSION['session_token'] = $sessionToken;
    } catch (Throwable $e) {
        // user_sessions table may not exist in this DB — login still
        // works without server-side session revocation tracking.
        error_log('[SafeTrade] user_sessions insert skipped: ' . $e->getMessage());
    }

    try {
        $stmt = db()->prepare('UPDATE users SET failed_login_attempts = 0 WHERE id = ?');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] failed_login_attempts reset skipped: ' . $e->getMessage());
    }
}

/**
 * @return array{success:bool, error:?string, lockout_seconds?:int}
 */
function attempt_login(string $email, string $password, bool $remember = false): array
{
    $email = strtolower(clean_input($email));

    if ($email === '' || $password === '') {
        return ['success' => false, 'error' => 'Please enter your email and password.'];
    }

    if (is_login_rate_limited($email)) {
        return [
            'success'         => false,
            'error'           => 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.',
            'lockout_seconds' => login_lockout_seconds_remaining($email),
        ];
    }

    $conn = db();
    $stmt = $conn->prepare(
        'SELECT id, first_name, last_name, email, password_hash, role, status, verification_status
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($email, false);
        return ['success' => false, 'error' => 'Incorrect email or password.'];
    }

    $statusError = login_status_error($user);
    if ($statusError !== null) {
        record_login_attempt($email, false);
        return ['success' => false, 'error' => $statusError];
    }

    record_login_attempt($email, true);
    login_start_session($user, $remember);
    audit_log((int) $user['id'], 'user.login', 'user', (int) $user['id']);

    return ['success' => true, 'error' => null];
}

function logout_user(): void
{
    if (!empty($_SESSION['session_token'])) {
        try {
            $stmt = db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_token = ?');
            $stmt->bind_param('s', $_SESSION['session_token']);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[SafeTrade] user_sessions revoke skipped: ' . $e->getMessage());
        }
    }
    if (!empty($_SESSION['user_id'])) {
        audit_log((int) $_SESSION['user_id'], 'user.logout', 'user', (int) $_SESSION['user_id']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/* ===================== Guards ===================== */

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'                  => (int) $_SESSION['user_id'],
        'first_name'          => $_SESSION['user_first_name'] ?? '',
        'last_name'           => $_SESSION['user_last_name'] ?? '',
        'email'               => $_SESSION['user_email'] ?? '',
        'role'                => $_SESSION['user_role'] ?? 'user',
        'verification_status' => $_SESSION['verification_status'] ?? 'not_started',
    ];
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Where a logged-in user's home page is, based on role. */
function home_path_for(array $user): string
{
    return in_array($user['role'], ['admin', 'manager'], true) ? '/admin/index.php' : '/dashboard.php';
}

function require_login(): array
{
    if (!is_logged_in()) {
        flash_set('error', 'Please log in to continue.');
        redirect('/auth/login.php');
    }
    return current_user();
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('You do not have permission to view this page.');
    }
    return $user;
}
