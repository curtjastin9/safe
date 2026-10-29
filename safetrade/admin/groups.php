<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $groupId = (int) ($_POST['group_id'] ?? 0);
    if ($groupId) {
        $stmt = $conn->prepare('DELETE FROM trade_groups WHERE id = ?');
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $stmt->close();
        audit_log($adminUser['id'], 'admin.group.deleted', 'group', $groupId);
        flash_set('success', 'Group deleted.');
    }
    redirect('/admin/groups.php');
}

$groups = $conn->query(
    "SELECT g.id, g.name, g.join_policy, g.created_at,
            (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status='approved') AS member_count,
            (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status='pending') AS pending_count
     FROM trade_groups g ORDER BY g.created_at DESC LIMIT 100"
);

$pageTitle = 'Groups · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('groups', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Groups</h1><p class="sub">Community groups across SafeTrade.</p></div></div>
    <?php render_flashes(); ?>

    <div class="panel">
      <table class="data-table">
        <thead><tr><th>Group</th><th>Join policy</th><th>Members</th><th>Pending requests</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php if ($groups->num_rows === 0): ?>
          <tr><td colspan="6"><div class="empty-state">No groups yet.</div></td></tr>
        <?php else: while ($g = $groups->fetch_assoc()): ?>
          <tr>
            <td><?= e($g['name']) ?></td>
            <td><?= e(ucwords(str_replace('_',' ',$g['join_policy']))) ?></td>
            <td><?= (int) $g['member_count'] ?></td>
            <td><?= (int) $g['pending_count'] ?></td>
            <td><?= e(time_ago($g['created_at'])) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Delete this group permanently?');">
                <?= csrf_field() ?>
                <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
                <button class="btn-sm danger" type="submit">Delete</button>
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
