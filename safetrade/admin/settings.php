<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->bind_param('i', $adminUser['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if ($newPassword !== $newPasswordConfirm) {
        $errors[] = 'New passwords do not match.';
    }
    $errors = array_merge($errors, password_policy_errors($newPassword));

    if (!$errors) {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $adminUser['id']);
        $stmt->execute();
        $stmt->close();
        audit_log($adminUser['id'], 'admin.password_changed', 'user', $adminUser['id']);
        flash_set('success', 'Password updated.');
        redirect('/admin/settings.php');
    }
}

$pageTitle = 'Settings · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('settings', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Settings</h1><p class="sub">Manage your admin account.</p></div></div>
    <?php render_flashes(); ?>

    <div class="panel" style="max-width:480px;">
      <h2>Change password</h2>
      <?php if ($errors): ?>
        <div class="alert error">Please fix the following:
          <ul class="err-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Current password</label><input type="password" name="current_password" required></div>
        <div class="field"><label>New password</label><input type="password" name="new_password" minlength="10" required></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="new_password_confirm" minlength="10" required></div>
        <button type="submit" class="btn-primary" style="width:auto;">Update password</button>
      </form>
    </div>

    <div class="panel" style="max-width:480px;">
      <h2>Account</h2>
      <p style="font-size:13.5px;color:var(--ink-soft);">Signed in as <strong><?= e($adminUser['email']) ?></strong> (<?= e(ucfirst($adminUser['role'])) ?>)</p>
    </div>
  </main>
</div>
</body>
</html>
