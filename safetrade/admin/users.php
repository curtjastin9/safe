<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

$search = clean_input($_GET['q'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ["role = 'user'"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if (in_array($statusFilter, ['active', 'pending', 'suspended', 'banned'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) c FROM users WHERE $whereSql");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

$listSql = "SELECT id, first_name, last_name, email, status, verification_status, created_at, last_login_at
            FROM users WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($listSql);
$allTypes = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$users = $stmt->get_result();
$stmt->close();

$totalPages = max(1, (int) ceil($total / $perPage));

$pageTitle = 'Users · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('users', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar">
      <div><h1>Users</h1><p class="sub"><?= number_format($total) ?> registered members</p></div>
    </div>
    <?php render_flashes(); ?>

    <form class="search-row" method="get">
      <input type="text" name="q" placeholder="Search name or email…" value="<?= e($search) ?>">
      <select name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['active','pending','suspended','banned'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-sm">Search</button>
    </form>

    <div class="panel">
      <table class="data-table">
        <thead><tr><th>User</th><th>Status</th><th>Verification</th><th>Joined</th><th>Last login</th><th></th></tr></thead>
        <tbody>
        <?php if ($users->num_rows === 0): ?>
          <tr><td colspan="6"><div class="empty-state">No users match your search.</div></td></tr>
        <?php else: while ($u = $users->fetch_assoc()): ?>
          <tr>
            <td><div class="table-user">
              <div class="avatar-sm"><?= e(strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1))) ?></div>
              <div><div class="name"><?= e($u['first_name'].' '.$u['last_name']) ?></div><div class="sub"><?= e($u['email']) ?></div></div>
            </div></td>
            <td><span class="pill <?= e($u['status']) ?>"><?= e(ucfirst($u['status'])) ?></span></td>
            <td><span class="pill <?= e($u['verification_status']) ?>"><?= e(ucwords(str_replace('_',' ',$u['verification_status']))) ?></span></td>
            <td><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
            <td><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : '—' ?></td>
            <td><a class="btn-sm" href="user-detail.php?id=<?= (int) $u['id'] ?>">Manage</a></td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?q=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
