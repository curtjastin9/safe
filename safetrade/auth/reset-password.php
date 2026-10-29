<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$uid = (int)($_GET['uid'] ?? ($_POST['uid'] ?? 0));
$errors = [];
$validToken = false;

if ($token && $uid) {
    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT id FROM password_resets WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->bind_param('is', $uid, $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $validToken = (bool) $row;
    $resetId = $row['id'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    require_csrf();
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';
    $errors = array_merge($errors, password_policy_errors($password));

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $conn = db();
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $resetId);
            $stmt->execute();
            $stmt->close();

            // Best-effort only, same as auth.php treats this table
            // everywhere else: log out any other active sessions for
            // this account now that the password changed. If
            // user_sessions doesn't exist (or differs) on this install,
            // that's fine — it must NOT take down the actual password
            // change, which is the part that matters.
            try {
                $stmt = $conn->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) {
                error_log('[SafeTrade] user_sessions revoke skipped during password reset: ' . $e->getMessage());
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[SafeTrade] password reset UPDATE failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong: ' . $e->getMessage();
        }

        if (!$errors) {
            audit_log($uid, 'user.password_reset_completed', 'user', $uid);
            flash_set('success', 'Password updated. Please log in with your new password.');
            redirect('/auth/login.php');
        }
    }
}

$pageTitle = 'Reset password · SafeTrade';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="auth-shell">
  <div class="auth-card">
    <a href="../Index.html" class="auth-logo"><?= seal_svg() ?> SafeTrade</a>
    <h1>Choose a new password</h1>

    <?php if (!$validToken): ?>
      <div class="alert error">This reset session is invalid or has expired.</div>
      <div class="auth-foot"><a href="forgot-password.php">Request a new code</a></div>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert error">Please fix the following:
          <ul class="err-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="uid" value="<?= (int) $uid ?>">
        <div class="field">
          <label>New password</label>
          <div class="pw-field-wrap">
            <input type="password" name="password" id="pw-new" minlength="10" required autofocus>
            <button type="button" class="pw-toggle" data-target="pw-new" aria-label="Show password">👁</button>
          </div>
        </div>
        <div class="field">
          <label>Confirm new password</label>
          <div class="pw-field-wrap">
            <input type="password" name="password_confirm" id="pw-confirm" minlength="10" required>
            <button type="button" class="pw-toggle" data-target="pw-confirm" aria-label="Show password">👁</button>
          </div>
        </div>
        <button type="submit" class="btn-primary">Update password</button>
      </form>

      <style>
        .pw-field-wrap{position:relative;}
        .pw-field-wrap input{padding-right:44px;}
        .pw-toggle{
          position:absolute;top:50%;right:6px;transform:translateY(-50%);
          background:none;border:none;cursor:pointer;font-size:16px;line-height:1;
          padding:8px;border-radius:8px;opacity:.6;transition:opacity .15s ease,background .15s ease;
        }
        .pw-toggle:hover{opacity:1;background:rgba(21,89,67,.08);}
      </style>
      <script>
      document.querySelectorAll('.pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var input = document.getElementById(btn.getAttribute('data-target'));
          var showing = input.type === 'text';
          input.type = showing ? 'password' : 'text';
          btn.textContent = showing ? '👁' : '🙈';
          btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });
      });
      </script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
