<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$total = (int) $conn->query('SELECT COUNT(*) c FROM audit_logs')->fetch_assoc()['c'];
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $conn->prepare(
    'SELECT a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at, u.first_name, u.last_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
     ORDER BY a.created_at DESC LIMIT ? OFFSET ?'
);
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

$pageTitle = 'Audit Log · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('audit', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Audit Log</h1><p class="sub">Immutable trail of security-relevant events (<?= number_format($total) ?> entries).</p></div></div>

    <div class="panel">
      <table class="data-table">
        <thead><tr><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        <?php if ($logs->num_rows === 0): ?>
          <tr><td colspan="6"><div class="empty-state">No log entries yet.</div></td></tr>
        <?php else: while ($l = $logs->fetch_assoc()): ?>
          <tr>
            <td><?= $l['first_name'] ? e($l['first_name'].' '.$l['last_name']) : 'System' ?></td>
            <td><?= e($l['action']) ?></td>
            <td><?= e(trim(($l['entity_type'] ?? '').' #'.($l['entity_id'] ?? ''), ' #')) ?></td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($l['details'] ?? '') ?></td>
            <td><?= e($l['ip_address'] ?? '—') ?></td>
            <td><?= e(date('M j, Y g:i A', strtotime($l['created_at']))) ?></td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
      <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
