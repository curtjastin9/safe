<?php
declare(strict_types=1);

/** Escape for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Trim + strip null bytes from raw input. */
function clean_input(?string $value): string
{
    $value = $value ?? '';
    $value = str_replace("\0", '', $value);
    return trim($value);
}

/* ---------------------------- CSRF ---------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        die('Your session expired or the request was invalid. Please go back and try again.');
    }
}

/* ---------------------------- Flash messages ---------------------------- */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get_all(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ---------------------------- Misc ---------------------------- */

/**
 * Redirect to an app-relative path (e.g. '/admin/index.php').
 * Automatically prefixed with BASE_URL so this works whether the app
 * lives at the web server's document root or in a subfolder
 * (e.g. http://192.168.1.8/safetrade). Configure BASE_URL in config/config.php.
 */
function redirect(string $path): never
{
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    header('Location: ' . $base . $path);
    exit;
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Write an immutable audit-trail row. Never throws — logging must not break the request. */
function audit_log(?int $actorId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ip = client_ip();
        $stmt->bind_param('ississ', $actorId, $action, $entityType, $entityId, $details, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] audit_log failed: ' . $e->getMessage());
    }
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/**
 * Builds a fully-qualified URL (scheme + host + BASE_URL + path).
 * redirect()/most of the app use root-relative paths like '/dashboard.php',
 * which only work inside a browser that's already on this site. Links
 * inside an EMAIL need the whole thing, since the email is opened with
 * no browsing context at all.
 *
 * Prefers an explicit SAFETRADE_PUBLIC_URL env var (set this in
 * production — e.g. https://safetrade.example.com — since background/
 * queued jobs have no $_SERVER request to fall back on). During normal
 * requests on XAMPP, falls back to the current request's scheme+host,
 * which is fine for this app's "send the email synchronously, during
 * the request that triggered it" pattern.
 */
function app_url(string $path): string
{
    $envBase = getenv('SAFETRADE_PUBLIC_URL');
    if ($envBase) {
        return rtrim($envBase, '/') . '/' . ltrim($path, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}
