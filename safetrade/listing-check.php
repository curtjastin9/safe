<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
$user = require_login();
$conn = db();
header('Content-Type: text/plain; charset=utf-8');
$me = (int) $user['id'];
echo "SafeTrade listing check v2 (delete this file when done)\n";
echo "Logged in as user id $me (" . ($user['first_name'] ?? '') . ")\n";
echo "Tip: add ?seller=6 to the address to also test My Listings as user 6\n\n";

echo "== table charset ==\n";
foreach ($conn->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('products','users','product_images')") as $r) echo $r['TABLE_NAME'] . ' ' . $r['TABLE_COLLATION'] . "\n";

echo "\n== users who own products ==\n";
foreach ($conn->query('SELECT p.seller_id, COUNT(*) n, u.id AS user_row, u.first_name, u.status FROM products p LEFT JOIN users u ON u.id = p.seller_id GROUP BY p.seller_id, u.id, u.first_name, u.status') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . ($r['user_row'] === null ? '   <-- NO MATCHING USER ROW (item is hidden by the JOIN)' : '') . "\n";
}

echo "\n== Browse Market query as user $me ==\n";
$st = $conn->prepare("SELECT p.id, p.title, p.seller_id FROM products p JOIN users u ON u.id=p.seller_id WHERE COALESCE(p.is_active,1)=1 AND COALESCE(p.is_deleted,0)=0 AND p.seller_id<>? ORDER BY p.created_at DESC LIMIT 60");
$st->bind_param('i', $me);
$st->execute();
$n = 0;
foreach ($st->get_result() as $r) { $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
echo "=> $n item(s) would be listed\n";

$seller = (int) ($_GET['seller'] ?? $me);
echo "\n== My Listings query as user $seller ==\n";
$st = $conn->prepare('SELECT id, title, is_active, is_deleted FROM products WHERE seller_id = ? ORDER BY created_at DESC');
$st->bind_param('i', $seller);
$st->execute();
$n = 0;
foreach ($st->get_result() as $r) { $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
echo "=> $n item(s)\n";

echo "\n== newest 5 products: can every field be displayed? ==\n";
foreach ($conn->query('SELECT * FROM products ORDER BY id DESC LIMIT 5') as $r) {
    $bad = [];
    foreach ($r as $k => $v) {
        if ($v !== null && !mb_check_encoding((string) $v, 'UTF-8')) $bad[] = $k;
        if ($k !== 'description' && $v !== null && (string) $v !== '' && htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') === '') $bad[] = $k;
    }
    echo '#' . $r['id'] . ' ' . $r['title'] . ' -> ' . ($bad ? 'UNREADABLE FIELDS: ' . implode(',', array_unique($bad)) : 'ok') . "\n";
}
