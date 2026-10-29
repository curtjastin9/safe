<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * ip-location.php
 *
 * Fallback for when the browser's precise GPS geolocation isn't
 * available, times out, or the user declines the permission prompt —
 * the same idea as Facebook's "approximate location" fallback. Looks
 * up the requester's public IP against a free IP-geolocation service
 * and stores CITY-LEVEL (not exact) coordinates on the account, tagged
 * with location_source = 'ip' so the UI can be upfront that it's an
 * approximation rather than a precise pin.
 *
 * NOTE: this only resolves anything useful when the server can see a
 * real public IP. On a local XAMPP install behind a home router,
 * REMOTE_ADDR is normally a private LAN address (192.168.x.x / 10.x.x.x)
 * that no IP-geolocation service can resolve — that's expected in local
 * development. This endpoint reports that back explicitly rather than
 * guessing or defaulting to some arbitrary location.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

/**
 * Best-effort real client IP. Prefers a proxy header (if this app sits
 * behind a load balancer / reverse proxy / Cloudflare) but only trusts
 * values that are actually valid, non-private, non-reserved addresses —
 * falls through to REMOTE_ADDR otherwise.
 */
function client_public_ip(): ?string
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Header may be a comma-separated chain; left-most is the original client.
        $candidates[] = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_CLIENT_IP']);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim($_SERVER['REMOTE_ADDR']);
    }

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    return null; // every candidate was private/loopback/reserved
}

$ip = client_public_ip();

if ($ip === null) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'private_ip',
        'message' => "Your connection is on a private/local network address, so IP-based location can't be resolved here. This works once the app is on a real public server.",
    ]);
    exit;
}

// Free tier, no API key required. Swap for a paid provider if you need
// higher volume or tighter SLAs in production.
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$raw = @file_get_contents('https://ipapi.co/' . rawurlencode($ip) . '/json/', false, $ctx);

if ($raw === false) {
    echo json_encode(['ok' => false, 'error' => 'lookup_failed']);
    exit;
}

$data = json_decode($raw, true);

if (!is_array($data) || isset($data['error']) || !isset($data['latitude'], $data['longitude'])) {
    echo json_encode(['ok' => false, 'error' => 'lookup_failed']);
    exit;
}

$lat  = (float) $data['latitude'];
$lng  = (float) $data['longitude'];
$city = trim(($data['city'] ?? '') . (!empty($data['region']) ? ', ' . $data['region'] : ''));

$user = current_user();
$conn = db();
try {
    // location_source requires migration_add_location_source.sql — fall
    // back to the plain 2-column update if that hasn't been run yet.
    $stmt = $conn->prepare("UPDATE users SET latitude = ?, longitude = ?, location_updated_at = NOW(), location_source = 'ip' WHERE id = ?");
    $stmt->bind_param('ddi', $lat, $lng, $user['id']);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $stmt = $conn->prepare('UPDATE users SET latitude = ?, longitude = ?, location_updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ddi', $lat, $lng, $user['id']);
    $stmt->execute();
    $stmt->close();
}

echo json_encode([
    'ok'     => true,
    'lat'    => $lat,
    'lng'    => $lng,
    'city'   => $city !== '' ? $city : null,
    'approx' => true,
]);
