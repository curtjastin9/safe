<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

// Only super-admins may access this page — managers cannot add new admins.
$adminUser = require_role('admin');
$conn = db();

$errors   = [];
$pwErrors = [];

/* =========================================================
   POST actions
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    /* ---- Add new admin / manager ---- */
    if ($action === 'add_admin') {
        $firstName = clean_input($_POST['first_name'] ?? '');
        $lastName  = clean_input($_POST['last_name']  ?? '');
        $email     = strtolower(clean_input($_POST['email'] ?? ''));
        $role      = in_array($_POST['role'] ?? '', ['admin', 'manager'], true) ? $_POST['role'] : 'manager';
        $password  = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (!is_valid_name($firstName)) $errors[] = 'Please enter a valid first name.';
        if (!is_valid_name($lastName))  $errors[] = 'Please enter a valid last name.';
        if (!is_valid_email($email))    $errors[] = 'Please enter a valid email address.';
        if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';
        $errors = array_merge($errors, password_policy_errors($password));

        if (!$errors) {
            // Check email uniqueness
            $chk = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chk->bind_param('s', $email);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $errors[] = 'An account with that email already exists.';
            }
            $chk->close();
        }

        if (!$errors) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $uuid = uuid_v4();
            $stmt = $conn->prepare(
                "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, status, verification_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', 'approved')"
            );
            $stmt->bind_param('ssssss', $uuid, $firstName, $lastName, $email, $hash, $role);
            try {
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                audit_log($adminUser['id'], 'admin.admin_account.created', 'user', $newId, 'role=' . $role);
                flash_set('success', ucfirst($role) . ' account created for ' . $firstName . ' ' . $lastName . '.');
                redirect('/admin/manage-admins.php');
            } catch (mysqli_sql_exception $e) {
                $stmt->close();
                $errors[] = 'Could not create account: ' . $e->getMessage();
            }
        }
    }

    /* ---- Change another admin's password ---- */
    elseif ($action === 'change_password') {
        $targetId       = (int) ($_POST['target_id'] ?? 0);
        $newPassword    = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($newPassword !== $confirmPassword) {
            $pwErrors[$targetId][] = 'Passwords do not match.';
        }
        $pwErrors[$targetId] = array_merge($pwErrors[$targetId] ?? [], password_policy_errors($newPassword));

        if (empty($pwErrors[$targetId])) {
            // Verify the target is an admin/manager (can't use this to reset regular users)
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('admin','manager') LIMIT 1");
            $chk->bind_param('i', $targetId);
            $chk->execute();
            $targetRow = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$targetRow) {
                flash_set('error', 'Admin account not found.');
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->bind_param('si', $hash, $targetId);
                $stmt->execute();
                $stmt->close();

                // Revoke all active sessions for the target so they must log in again
                try {
                    $rev = $conn->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
                    $rev->bind_param('i', $targetId);
                    $rev->execute();
                    $rev->close();
                } catch (Throwable $e) { /* user_sessions may not exist */ }

                audit_log($adminUser['id'], 'admin.admin_account.password_changed', 'user', $targetId);
                flash_set('success', 'Password updated. That admin will need to log in again.');
                redirect('/admin/manage-admins.php');
            }
        }
    }

    /* ---- Change role ---- */
    elseif ($action === 'change_role') {
        $targetId = (int) ($_POST['target_id'] ?? 0);
        $newRole  = in_array($_POST['new_role'] ?? '', ['admin', 'manager'], true) ? $_POST['new_role'] : null;

        if (!$newRole) {
            flash_set('error', 'Invalid role selected.');
        } elseif ($targetId === $adminUser['id']) {
            flash_set('error', 'You cannot change your own role.');
        } else {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ? AND role IN ('admin','manager')");
            $stmt->bind_param('si', $newRole, $targetId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminUser['id'], 'admin.admin_account.role_changed', 'user', $targetId, 'new_role=' . $newRole);
            flash_set('success', 'Role updated to ' . ucfirst($newRole) . '.');
            redirect('/admin/manage-admins.php');
        }
    }

    /* ---- Deactivate / Reactivate ---- */
    elseif ($action === 'deactivate_admin' || $action === 'reactivate_admin') {
        $targetId  = (int) ($_POST['target_id'] ?? 0);
        $newStatus = $action === 'deactivate_admin' ? 'suspended' : 'active';

        if ($targetId === $adminUser['id']) {
            flash_set('error', 'You cannot deactivate your own account.');
        } else {
            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND role IN ('admin','manager')");
            $stmt->bind_param('si', $newStatus, $targetId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminUser['id'], 'admin.admin_account.' . $action, 'user', $targetId);
            flash_set('success', 'Account ' . ($action === 'deactivate_admin' ? 'deactivated' : 'reactivated') . '.');
            redirect('/admin/manage-admins.php');
        }
    }

    /* ---- Delete admin account ---- */
    elseif ($action === 'delete_admin') {
        $targetId = (int) ($_POST['target_id'] ?? 0);

        if ($targetId === $adminUser['id']) {
            flash_set('error', 'You cannot delete your own account.');
        } else {
            // Verify target is admin/manager
            $chk = $conn->prepare("SELECT id, email, first_name, last_name FROM users WHERE id = ? AND role IN ('admin','manager') LIMIT 1");
            $chk->bind_param('i', $targetId);
            $chk->execute();
            $target = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$target) {
                flash_set('error', 'Admin account not found.');
            } else {
                try {
                    // Detach audit log references first
                    try {
                        $anon = $conn->prepare('UPDATE audit_logs SET actor_id = NULL WHERE actor_id = ?');
                        $anon->bind_param('i', $targetId);
                        $anon->execute();
                        $anon->close();
                    } catch (Throwable $e) { /* NOT NULL column — log but continue */ }

                    foreach (['user_sessions', 'password_resets', 'notifications', 'login_attempts'] as $table) {
                        try {
                            $del = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                            $del->bind_param('i', $targetId);
                            $del->execute();
                            $del->close();
                        } catch (Throwable $e) { /* table may not exist */ }
                    }

                    $del = $conn->prepare('DELETE FROM users WHERE id = ?');
                    $del->bind_param('i', $targetId);
                    $del->execute();
                    $del->close();

                    audit_log(
                        $adminUser['id'],
                        'admin.admin_account.deleted',
                        'user',
                        $targetId,
                        'email=' . $target['email']
                    );
                    flash_set('success', 'Admin account for ' . $target['first_name'] . ' ' . $target['last_name'] . ' has been deleted.');
                    redirect('/admin/manage-admins.php');
                } catch (Throwable $e) {
                    error_log('[SafeTrade] delete_admin failed: ' . $e->getMessage());
                    flash_set('error', 'Could not delete account: ' . $e->getMessage());
                }
            }
        }
    }
}

/* =========================================================
   Load all admin/manager accounts
   ========================================================= */
$admins = $conn->query(
    "SELECT id, first_name, last_name, email, role, status, created_at
     FROM users WHERE role IN ('admin','manager')
     ORDER BY role ASC, created_at ASC"
);

$pageTitle = 'Manage Admins · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<style>
/* ---- Manage Admins page extras ---- */
.ma-card{
  background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);
  padding:24px 26px;margin-bottom:16px;
  transition:box-shadow .18s var(--ease);
}
.ma-card:hover{box-shadow:var(--shadow-sm);}
.ma-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.ma-identity{display:flex;align-items:center;gap:14px;}
.ma-avatar{
  width:46px;height:46px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(160deg,var(--brand),var(--brand-dark));
  color:var(--cream);display:flex;align-items:center;justify-content:center;
  font-family:'Fraunces',serif;font-weight:600;font-size:17px;box-shadow:var(--shadow-xs);
}
.ma-avatar.manager-av{background:linear-gradient(160deg,#C97B52,#8A4A28);}
.ma-name{font-family:'Fraunces',serif;font-size:16.5px;font-weight:600;color:var(--ink);}
.ma-email{font-size:12.5px;color:var(--ink-soft);margin-top:3px;}
.ma-meta{font-size:11.5px;color:var(--ink-faint);margin-top:2px;}
.ma-badges{display:flex;align-items:center;gap:7px;margin-top:8px;flex-wrap:wrap;}
.ma-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;padding-top:4px;}

/* Expandable password / role change section */
.ma-expand-panel{
  display:none;margin-top:20px;padding-top:18px;
  border-top:1px solid var(--line);
  animation:maExpand .18s ease both;
}
.ma-expand-panel.open{display:block;}
@keyframes maExpand{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:none;}}
.ma-section-label{font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:12px;font-weight:700;}
.ma-two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
@media(max-width:700px){.ma-two-col{grid-template-columns:1fr;}}

/* "You" badge */
.you-badge{font-size:10.5px;font-weight:700;background:var(--gold-light);color:var(--gold-dark);padding:3px 9px;border-radius:999px;letter-spacing:.02em;}

/* Add admin form card */
.add-card{background:var(--paper);border:2px dashed var(--line-strong);border-radius:var(--radius-lg);padding:28px 26px;margin-bottom:32px;transition:border-color .15s ease;}
.add-card:focus-within{border-color:var(--brand);}
.add-card h2{font-size:17px;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.add-card h2 span{font-size:22px;}

/* Modal */
.del-modal-backdrop{display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;}
.del-modal-backdrop.show{display:flex;}
.del-modal{background:var(--cream);border-radius:22px;padding:34px 30px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);}
.del-modal h3{font-family:'Fraunces',serif;font-size:19px;margin:0 0 8px;}
.del-modal p{font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 22px;}
.del-modal-actions{display:flex;gap:10px;}
.del-modal-cancel{flex:1;padding:13px;border-radius:999px;font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);background:transparent;color:var(--ink-soft);cursor:pointer;}
.del-modal-confirm{flex:1;padding:13px;border-radius:999px;font-weight:700;font-size:14px;border:none;background:var(--danger);color:#fff;cursor:pointer;}
</style>

<div class="admin-app">
  <?php render_admin_sidebar('manage-admins', $adminUser); ?>
  <main class="admin-main">

    <div class="admin-topbar">
      <div>
        <h1>Manage Admins</h1>
        <p class="sub">Add new admins or managers, change passwords, and control account access. Only super-admins can view this page.</p>
      </div>
    </div>

    <?php foreach (flash_get_all() as $f): ?>
      <div class="alert <?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <!-- ===================== ADD NEW ADMIN ===================== -->
    <div class="add-card">
      <h2><span>🛡️</span> Add New Admin or Manager</h2>
      <?php if ($errors): ?>
        <div class="alert error" style="margin-bottom:16px;">
          Please fix the following:
          <ul class="err-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_admin">
        <div class="field-row">
          <div class="field" style="margin-top:0;">
            <label>First name</label>
            <input type="text" name="first_name" value="<?= e($_POST['first_name'] ?? '') ?>" required placeholder="e.g. Maria">
          </div>
          <div class="field" style="margin-top:0;">
            <label>Last name</label>
            <input type="text" name="last_name" value="<?= e($_POST['last_name'] ?? '') ?>" required placeholder="e.g. Santos">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Email address</label>
            <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="admin@example.com">
          </div>
          <div class="field">
            <label>Role</label>
            <select name="role">
              <option value="manager" <?= ($_POST['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager — can moderate but can't add admins</option>
              <option value="admin"   <?= ($_POST['role'] ?? '') === 'admin'   ? 'selected' : '' ?>>Admin — full access including this page</option>
            </select>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Password</label>
            <input type="password" name="password" minlength="10" required autocomplete="new-password">
          </div>
          <div class="field">
            <label>Confirm password</label>
            <input type="password" name="password_confirm" minlength="10" required autocomplete="new-password">
          </div>
        </div>
        <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">Password must be at least 10 characters and contain at least one letter and one number.</p>
        <button type="submit" class="btn" style="margin-top:16px;display:inline-flex;align-items:center;gap:7px;padding:12px 22px;border-radius:999px;font-weight:600;font-size:14px;background:var(--brand);color:var(--cream);border:none;cursor:pointer;">
          + Create account
        </button>
      </form>
    </div>

    <!-- ===================== EXISTING ADMINS LIST ===================== -->
    <h2 style="font-size:17px;margin-bottom:18px;">Current Admins &amp; Managers</h2>

    <?php if ($admins->num_rows === 0): ?>
      <div class="empty-state" style="background:var(--paper);border-radius:var(--radius);padding:40px;">No admin accounts found.</div>
    <?php endif; ?>

    <?php while ($a = $admins->fetch_assoc()):
      $isMe    = (int) $a['id'] === (int) $adminUser['id'];
      $initials = strtoupper(substr($a['first_name'], 0, 1) . substr($a['last_name'], 0, 1));
      $panelId  = 'ma-panel-' . (int) $a['id'];
      $delId    = 'del-modal-' . (int) $a['id'];
      $hasPwErr = !empty($pwErrors[(int)$a['id']]);
    ?>
    <div class="ma-card" id="ma-card-<?= (int)$a['id'] ?>">
      <div class="ma-header">
        <div class="ma-identity">
          <div class="ma-avatar <?= $a['role'] === 'manager' ? 'manager-av' : '' ?>"><?= e($initials) ?></div>
          <div>
            <div class="ma-name"><?= e($a['first_name'] . ' ' . $a['last_name']) ?> <?= $isMe ? '<span class="you-badge">You</span>' : '' ?></div>
            <div class="ma-email"><?= e($a['email']) ?></div>
            <div class="ma-meta">Added <?= e(time_ago($a['created_at'])) ?></div>
            <div class="ma-badges">
              <span class="pill <?= $a['role'] === 'admin' ? 'admin' : 'manager' ?>"><?= e(ucfirst($a['role'])) ?></span>
              <span class="pill <?= $a['status'] === 'active' ? 'active' : 'suspended' ?>"><?= e(ucfirst($a['status'])) ?></span>
            </div>
          </div>
        </div>

        <div class="ma-actions">
          <button type="button" class="btn-sm" onclick="togglePanel('<?= $panelId ?>', this)">
            ✏️ Edit
          </button>
          <?php if (!$isMe): ?>
            <?php if ($a['status'] === 'active'): ?>
              <form method="post" style="display:inline;"><?= csrf_field() ?>
                <input type="hidden" name="action" value="deactivate_admin">
                <input type="hidden" name="target_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn-sm danger" onclick="return confirm('Deactivate this account? They will not be able to log in.');">Deactivate</button>
              </form>
            <?php else: ?>
              <form method="post" style="display:inline;"><?= csrf_field() ?>
                <input type="hidden" name="action" value="reactivate_admin">
                <input type="hidden" name="target_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn-sm">Reactivate</button>
              </form>
            <?php endif; ?>
            <button type="button" class="btn-sm danger" onclick="openDelModal('<?= $delId ?>')" style="color:#8C331F;border-color:#C97B52;">🗑 Delete</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Expandable edit panel -->
      <div class="ma-expand-panel <?= $hasPwErr ? 'open' : '' ?>" id="<?= $panelId ?>">
        <div class="ma-two-col">

          <!-- Password change -->
          <div>
            <div class="ma-section-label">🔑 Change Password</div>
            <?php if ($hasPwErr): ?>
              <div class="alert error" style="margin-bottom:12px;">
                <ul class="err-list"><?php foreach ($pwErrors[(int)$a['id']] as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
              </div>
            <?php endif; ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_password">
              <input type="hidden" name="target_id" value="<?= (int)$a['id'] ?>">
              <div class="field" style="margin-top:0;">
                <label>New password</label>
                <input type="password" name="new_password" minlength="10" required autocomplete="new-password" placeholder="Min. 10 characters">
              </div>
              <div class="field">
                <label>Confirm new password</label>
                <input type="password" name="confirm_password" minlength="10" required autocomplete="new-password" placeholder="Repeat password">
              </div>
              <button type="submit" class="btn-sm" style="margin-top:12px;background:var(--brand);color:var(--cream);border-color:var(--brand);">Save password</button>
              <?php if (!$isMe): ?>
                <p style="font-size:11.5px;color:var(--ink-soft);margin-top:8px;">Their active sessions will be invalidated — they'll need to log in again.</p>
              <?php endif; ?>
            </form>
          </div>

          <!-- Role change (can't change own role) -->
          <div>
            <div class="ma-section-label">🏷️ Role</div>
            <?php if ($isMe): ?>
              <p style="font-size:13px;color:var(--ink-soft);line-height:1.6;">You cannot change your own role. Ask another admin to do this.</p>
            <?php else: ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="target_id" value="<?= (int)$a['id'] ?>">
                <div class="field" style="margin-top:0;">
                  <label>Assign role</label>
                  <select name="new_role">
                    <option value="manager" <?= $a['role'] === 'manager' ? 'selected' : '' ?>>Manager — moderate only</option>
                    <option value="admin"   <?= $a['role'] === 'admin'   ? 'selected' : '' ?>>Admin — full access</option>
                  </select>
                </div>
                <button type="submit" class="btn-sm" style="margin-top:12px;">Update role</button>
              </form>
              <p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px;line-height:1.55;">
                <strong>Admin</strong> — can do everything including this Manage Admins page.<br>
                <strong>Manager</strong> — can moderate users, listings, and reports but cannot add or manage admin accounts.
              </p>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>

    <!-- Delete confirmation modal -->
    <?php if (!$isMe): ?>
    <div class="del-modal-backdrop" id="<?= $delId ?>">
      <div class="del-modal">
        <div style="width:52px;height:52px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">🗑</div>
        <h3 style="text-align:center;">Delete <?= e($a['first_name']) ?>'s account?</h3>
        <p style="text-align:center;">This permanently removes the admin account for <strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong> (<?= e($a['email']) ?>). This cannot be undone.</p>
        <div class="del-modal-actions">
          <button type="button" class="del-modal-cancel" onclick="closeDelModal('<?= $delId ?>')">Cancel</button>
          <form method="post" style="flex:1;margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_admin">
            <input type="hidden" name="target_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="del-modal-confirm" style="width:100%;">Yes, delete</button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php endwhile; ?>

  </main>
</div>

<script>
function togglePanel(id, btn) {
  var panel = document.getElementById(id);
  var isOpen = panel.classList.contains('open');
  // Close all other panels first
  document.querySelectorAll('.ma-expand-panel.open').forEach(function (p) {
    if (p.id !== id) {
      p.classList.remove('open');
      // reset the corresponding button text
      var card = p.closest('.ma-card');
      if (card) {
        var editBtn = card.querySelector('button[onclick*="togglePanel"]');
        if (editBtn) editBtn.textContent = '✏️ Edit';
      }
    }
  });
  panel.classList.toggle('open', !isOpen);
  btn.textContent = !isOpen ? '✕ Close' : '✏️ Edit';
}

function openDelModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.add('show');
}
function closeDelModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('show');
}
// Close modals on backdrop click or Escape
document.querySelectorAll('.del-modal-backdrop').forEach(function (backdrop) {
  backdrop.addEventListener('click', function (e) {
    if (e.target === backdrop) backdrop.classList.remove('show');
  });
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.del-modal-backdrop.show').forEach(function (b) { b.classList.remove('show'); });
  }
});

// Auto-open the panel that has password errors (so the user sees the error inline)
document.querySelectorAll('.ma-expand-panel.open').forEach(function (panel) {
  var card = panel.closest('.ma-card');
  if (card) {
    var editBtn = card.querySelector('button[onclick*="togglePanel"]');
    if (editBtn) editBtn.textContent = '✕ Close';
  }
});
</script>
</body>
</html>
