<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * update-location.php
 *
 * Called by the geolocation script at the bottom of dashboard.php once
 * the browser grants permission. Saves the coordinates onto the user's
 * own account (users.latitude/longitude) — this is what the Haversine
 * distance query in dashboard.php ($view === 'home' / 'market') reads
 * to sort "near you" and to compute each listing's seller's distance
 * from you, and it's what makes the km badge on the buyer's OWN account
 * available for OTHER buyers to sort by when browsing this seller's
 * listings.
 *
 * Without this endpoint, the browser's geolocation prompt fires and
 * succeeds, but the coordinates go nowhere — the "Turn on location to
 * see items sorted by distance" banner never clears.
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

$lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);

if ($lat === false || $lng === false || $lat === null || $lng === null
    || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_coordinates']);
    exit;
}

$user = current_user();
$conn = db();

try {
    // location_source requires migration_add_location_source.sql — fall
    // back to the plain 2-column update if that hasn't been run yet.
    $stmt = $conn->prepare("UPDATE users SET latitude = ?, longitude = ?, location_updated_at = NOW(), location_source = 'gps' WHERE id = ?");
    $stmt->bind_param('ddi', $lat, $lng, $user['id']);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $stmt = $conn->prepare('UPDATE users SET latitude = ?, longitude = ?, location_updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ddi', $lat, $lng, $user['id']);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['ok' => true]);
