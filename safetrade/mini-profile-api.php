<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * mini-profile-api.php?id=<user_id>
 *
 * Tiny JSON endpoint that backs the "hover card" preview shown when you
 * point at a seller/buyer's name anywhere in the app (product cards,
 * item page, chat header, buyer lists). Returns just enough to judge
 * trustworthiness at a glance — the same numbers that live on the full
 * profile page, not separate data.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$conn = db();
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$stmt = $conn->prepare('SELECT id, first_name, last_name, created_at, verification_status FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

/* ---- Rating summary (same source of truth as the full profile page) ---- */
$r = $conn->prepare('SELECT COUNT(*) c, AVG(rating) avg_rating FROM feedback WHERE to_user_id = ?');
$r->bind_param('i', $id);
$r->execute();
$rr = $r->get_result()->fetch_assoc();
$r->close();
$ratingCount = (int) ($rr['c'] ?? 0);
$avgRating   = $ratingCount > 0 ? round((float) $rr['avg_rating'], 2) : 0.0;

/* ---- Just the identity-relevant counts, not the full analytics set ---- */
$aq = $conn->prepare('SELECT COUNT(*) c FROM products WHERE seller_id=? AND is_deleted=0');
$aq->bind_param('i', $id); $aq->execute();
$totalListings = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
$aq->close();

$aq = $conn->prepare('SELECT COUNT(*) c FROM products WHERE seller_id=? AND is_active=1 AND is_deleted=0');
$aq->bind_param('i', $id); $aq->execute();
$activeListings = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
$aq->close();

$aq = $conn->prepare('SELECT COUNT(*) c FROM products WHERE seller_id=? AND is_active=0 AND is_deleted=0');
$aq->bind_param('i', $id); $aq->execute();
$soldListings = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
$aq->close();

$initials = strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1));

echo json_encode([
    'id'              => (int) $u['id'],
    'name'            => trim($u['first_name'] . ' ' . $u['last_name']),
    'initials'        => $initials,
    'verified'        => $u['verification_status'] === 'approved',
    'member_since'    => date('M Y', strtotime($u['created_at'])),
    'avg_rating'      => $avgRating,
    'rating_count'    => $ratingCount,
    'total_listings'  => $totalListings,
    'active_listings' => $activeListings,
    'sold_listings'   => $soldListings,
]);
