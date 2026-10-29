<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $listingId = (int) ($_POST['listing_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $newStatus = match ($action) {
        'remove'  => 'removed',
        'restore' => 'active',
        default   => null,
    };
    if ($newStatus && $listingId) {
        $stmt = $conn->prepare('UPDATE listings SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $newStatus, $listingId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO moderation_actions (admin_id, target_type, target_id, action) VALUES (?, 'listing', ?, ?)");
        $stmt->bind_param('iis', $adminUser['id'], $listingId, $action);
        $stmt->execute();
        $stmt->close();

        audit_log($adminUser['id'], 'admin.listing.' . $action, 'listing', $listingId);
        flash_set('success', 'Listing updated.');
    }
    redirect('/admin/listings.php');
}

$statusFilter = clean_input($_GET['status'] ?? '');
$where = '1=1';
$params = [];
$types = '';
if (in_array($statusFilter, ['active', 'pending_review', 'sold', 'removed'], true)) {
    $where = 'l.status = ?';
    $params[] = $statusFilter;
    $types = 's';
}

$sql = "SELECT l.id, l.title, l.price, l.status, l.created_at, u.first_name, u.last_name, u.id AS user_id
        FROM listings l JOIN users u ON u.id = l.user_id
        WHERE $where ORDER BY l.created_at DESC LIMIT 100";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$listings = $stmt->get_result();
$stmt->close();

$pageTitle = 'Listings · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('listings', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Listings</h1><p class="sub">Moderate marketplace listings.</p></div></div>
    <?php render_flashes(); ?>

    <form class="search-row" method="get">
      <select name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['active','pending_review','sold','removed'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="panel">
      <table class="data-table">
        <thead><tr><th>Listing</th><th>Seller</th><th>Price</th><th>Status</th><th>Posted</th><th></th></tr></thead>
        <tbody>
        <?php if ($listings->num_rows === 0): ?>
          <tr><td colspan="6"><div class="empty-state">No listings yet.</div></td></tr>
        <?php else: while ($l = $listings->fetch_assoc()): ?>
          <tr>
            <td><?= e($l['title']) ?></td>
            <td><a href="user-detail.php?id=<?= (int) $l['user_id'] ?>" style="color:var(--brand);font-weight:600;text-decoration:none;"><?= e($l['first_name'].' '.$l['last_name']) ?></a></td>
            <td><?= $l['price'] !== null ? '₱' . number_format((float) $l['price'], 2) : '—' ?></td>
            <td><span class="pill <?= e($l['status']) ?>"><?= e(ucwords(str_replace('_',' ',$l['status']))) ?></span></td>
            <td><?= e(time_ago($l['created_at'])) ?></td>
            <td>
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
                <?php if ($l['status'] !== 'removed'): ?>
                  <button class="btn-sm danger" name="action" value="remove" onclick="return confirm('Remove this listing?');">Remove</button>
                <?php else: ?>
                  <button class="btn-sm" name="action" value="restore">Restore</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>
</body>
</html>
