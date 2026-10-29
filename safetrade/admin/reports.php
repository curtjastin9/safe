<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $newStatus = match ($action) {
        'resolve'  => 'resolved',
        'dismiss'  => 'dismissed',
        'reviewing' => 'reviewing',
        default    => null,
    };
    if ($newStatus && $reportId) {
        $stmt = $conn->prepare('UPDATE reports SET status = ?, resolved_at = IF(? IN (\'resolved\',\'dismissed\'), NOW(), resolved_at), resolved_by = ? WHERE id = ?');
        $stmt->bind_param('ssii', $newStatus, $newStatus, $adminUser['id'], $reportId);
        $stmt->execute();
        $stmt->close();
        audit_log($adminUser['id'], 'admin.report.' . $action, 'report', $reportId);
        flash_set('success', 'Report updated.');
    }
    redirect('/admin/reports.php');
}

$statusFilter = clean_input($_GET['status'] ?? 'open');
$where = '1=1';
$params = [];
$types = '';
if (in_array($statusFilter, ['open', 'reviewing', 'resolved', 'dismissed'], true)) {
    $where = 'r.status = ?';
    $params[] = $statusFilter;
    $types = 's';
}

$sql = "SELECT r.id, r.reason, r.details, r.status, r.created_at,
               ru.first_name AS rby_fn, ru.last_name AS rby_ln,
               tu.id AS target_id, tu.first_name AS t_fn, tu.last_name AS t_ln,
               l.id AS listing_id, l.title AS listing_title
        FROM reports r
        JOIN users ru ON ru.id = r.reported_by
        LEFT JOIN users tu ON tu.id = r.reported_user_id
        LEFT JOIN listings l ON l.id = r.listing_id
        WHERE $where ORDER BY r.created_at DESC LIMIT 100";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$reports = $stmt->get_result();
$stmt->close();

$pageTitle = 'Reports · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('reports', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Reports</h1><p class="sub">User reports awaiting moderator review.</p></div></div>
    <?php render_flashes(); ?>

    <form class="search-row" method="get">
      <select name="status" onchange="this.form.submit()">
        <?php foreach (['open','reviewing','resolved','dismissed'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="panel">
      <table class="data-table">
        <thead><tr><th>Reported by</th><th>Against</th><th>Reason</th><th>Status</th><th>Filed</th><th></th></tr></thead>
        <tbody>
        <?php if ($reports->num_rows === 0): ?>
          <tr><td colspan="6"><div class="empty-state">No reports in this view.</div></td></tr>
        <?php else: while ($r = $reports->fetch_assoc()): ?>
          <tr>
            <td><?= e($r['rby_fn'].' '.$r['rby_ln']) ?></td>
            <td><?php if ($r['target_id']): ?>
                  <a href="user-detail.php?id=<?= (int)$r['target_id'] ?>" style="color:var(--brand);font-weight:600;text-decoration:none;"><?= e($r['t_fn'].' '.$r['t_ln']) ?></a>
                <?php elseif ($r['listing_id']): ?>
                  Listing: <?= e($r['listing_title']) ?>
                <?php else: ?>—<?php endif; ?></td>
            <td><?= e($r['reason']) ?></td>
            <td><span class="pill <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e(time_ago($r['created_at'])) ?></td>
            <td>
              <form method="post" style="display:flex;gap:6px;">
                <?= csrf_field() ?>
                <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
                <?php if ($r['status'] === 'open'): ?>
                  <button class="btn-sm" name="action" value="reviewing">Start review</button>
                <?php endif; ?>
                <?php if ($r['status'] !== 'resolved'): ?>
                  <button class="btn-sm" name="action" value="resolve">Resolve</button>
                <?php endif; ?>
                <?php if ($r['status'] !== 'dismissed'): ?>
                  <button class="btn-sm danger" name="action" value="dismiss">Dismiss</button>
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
