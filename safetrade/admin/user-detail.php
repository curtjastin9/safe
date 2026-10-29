<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

$userId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action   = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['user_id'] ?? 0);

    if ($targetId === $adminUser['id'] && in_array($action, ['suspend', 'ban'], true)) {
        flash_set('error', "You can't suspend or ban your own account.");
    } elseif ($adminUser['role'] !== 'admin' && $action === 'set_role') {
        flash_set('error', 'Only admins can change user roles.');
    } else {
        $newStatus = match ($action) {
            'suspend'    => 'suspended',
            'ban'        => 'banned',
            'reactivate' => 'active',
            default      => null,
        };

        if ($newStatus) {
            $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $newStatus, $targetId);
            $stmt->execute();
            $stmt->close();

            try {
                $stmt = $conn->prepare('INSERT INTO moderation_actions (admin_id, target_type, target_id, action) VALUES (?, \'user\', ?, ?)');
                $stmt->bind_param('iis', $adminUser['id'], $targetId, $action);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) { /* table may not exist */ }

            audit_log($adminUser['id'], 'admin.user.' . $action, 'user', $targetId);
            flash_set('success', 'User status updated.');

        } elseif ($action === 'set_role' && $adminUser['role'] === 'admin') {
            $newRole = in_array($_POST['role'] ?? '', ['user', 'admin', 'manager'], true) ? $_POST['role'] : 'user';
            $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->bind_param('si', $newRole, $targetId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminUser['id'], 'admin.user.role_changed', 'user', $targetId, 'new_role=' . $newRole);
            flash_set('success', 'Role updated.');
        }
    }
    redirect('/admin/user-detail.php?id=' . $targetId);
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    die('User not found.');
}

/* counts — products table used throughout the rest of the app */
try {
    $listingsCount = (int) $conn->query('SELECT COUNT(*) c FROM products WHERE seller_id=' . $userId)->fetch_assoc()['c'];
} catch (Throwable $e) {
    $listingsCount = 0;
}
$reportsAgainst = (int) $conn->query('SELECT COUNT(*) c FROM reports WHERE reported_user_id=' . $userId)->fetch_assoc()['c'];

$stmt = $conn->prepare('SELECT action, created_at, ip_address, entity_type, entity_id FROM audit_logs WHERE actor_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->bind_param('i', $userId);
$stmt->execute();
$activity = $stmt->get_result();
$stmt->close();

$pageTitle = e($user['first_name'] . ' ' . $user['last_name']) . ' · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('users', $adminUser); ?>
  <main class="admin-main">

    <div class="admin-topbar">
      <div>
        <h1><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h1>
        <p class="sub"><?= e($user['email']) ?> · joined <?= e(date('M j, Y', strtotime($user['created_at']))) ?></p>
      </div>
      <div>
        <span class="pill <?= e($user['status']) ?>"><?= e(ucfirst($user['status'])) ?></span>
        <span class="pill <?= e($user['role']) ?>"><?= e(ucfirst($user['role'])) ?></span>
      </div>
    </div>

    <?php render_flashes(); ?>

    <!-- Stats -->
    <div class="stat-cards" style="grid-template-columns:repeat(3,1fr);">
      <div class="stat-card"><div class="label">Listings posted</div><div class="value"><?= $listingsCount ?></div></div>
      <div class="stat-card"><div class="label">Reports against user</div><div class="value"><?= $reportsAgainst ?></div></div>
      <div class="stat-card"><div class="label">Verification</div><div class="value" style="font-size:16px;"><?= e(ucwords(str_replace('_', ' ', $user['verification_status']))) ?></div></div>
    </div>

    <!-- Account actions -->
    <div class="panel">
      <h2>Account actions</h2>
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
        <?php if ($user['status'] !== 'active'): ?>
          <button class="btn-sm" name="action" value="reactivate">Reactivate account</button>
        <?php endif; ?>
        <?php if ($user['status'] !== 'suspended'): ?>
          <button class="btn-sm danger" name="action" value="suspend" onclick="return confirm('Suspend this account?');">Suspend</button>
        <?php endif; ?>
        <?php if ($user['status'] !== 'banned'): ?>
          <button class="btn-sm danger" name="action" value="ban" onclick="return confirm('Ban this account? This is a serious action.');">Ban</button>
        <?php endif; ?>
      </form>

      <?php if ($adminUser['role'] === 'admin'): ?>
      <form method="post" style="display:flex;gap:10px;align-items:center;margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="action" value="set_role">
        <label style="font-size:12.5px;color:var(--ink-soft);font-weight:600;">Role</label>
        <select name="role">
          <?php foreach (['user', 'manager', 'admin'] as $r): ?>
            <option value="<?= $r ?>" <?= $user['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn-sm" type="submit">Update role</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- =====================================================
         Government ID Section
         ===================================================== -->
    <div class="panel">
      <h2>Government ID Verification</h2>
      <?php
        $idStmt = $conn->prepare('SELECT * FROM government_ids WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
        $idStmt->bind_param('i', $userId);
        $idStmt->execute();
        $ids = $idStmt->get_result();
        $idStmt->close();
      ?>
      <?php if ($ids->num_rows === 0): ?>
        <p style="color:var(--ink-soft);font-size:13px;">No government ID submitted yet.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:16px;">
        <?php while ($id = $ids->fetch_assoc()): ?>
          <div style="border:1.5px solid var(--line);border-radius:12px;padding:16px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
              <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">ID Type</div>
                <div style="font-size:15px;font-weight:600;margin-top:3px;"><?= e($id['id_type']) ?></div>
              </div>
              <div style="font-size:11.5px;color:var(--ink-soft);">Submitted <?= e(time_ago($id['created_at'])) ?></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <?php if ($id['front_path']): ?>
              <div style="border-radius:8px;overflow:hidden;height:200px;border:1px solid var(--line);background:var(--cream);">
                <img src="<?= e('/safetrade/uploads/verification_docs/government_ids/' . $id['front_path']) ?>"
                     style="width:100%;height:100%;object-fit:contain;cursor:pointer;"
                     onclick="openImageModal('<?= e('/safetrade/uploads/verification_docs/government_ids/' . $id['front_path']) ?>','ID Front')"
                     alt="ID Front" title="Click to enlarge">
                <div style="font-size:10.5px;color:var(--ink-faint);text-align:center;padding:4px;">Front · click to enlarge</div>
              </div>
              <?php endif; ?>
              <?php if ($id['back_path']): ?>
              <div style="border-radius:8px;overflow:hidden;height:200px;border:1px solid var(--line);background:var(--cream);">
                <img src="<?= e('/safetrade/uploads/verification_docs/government_ids/' . $id['back_path']) ?>"
                     style="width:100%;height:100%;object-fit:contain;cursor:pointer;"
                     onclick="openImageModal('<?= e('/safetrade/uploads/verification_docs/government_ids/' . $id['back_path']) ?>','ID Back')"
                     alt="ID Back" title="Click to enlarge">
                <div style="font-size:10.5px;color:var(--ink-faint);text-align:center;padding:4px;">Back · click to enlarge</div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- =====================================================
         Face Verification Section
         ===================================================== -->
    <div class="panel">
      <h2>Face Verification</h2>
      <?php
        $faceStmt = $conn->prepare('SELECT * FROM face_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
        $faceStmt->bind_param('i', $userId);
        $faceStmt->execute();
        $faces = $faceStmt->get_result();
        $faceStmt->close();
      ?>
      <?php if ($faces->num_rows === 0): ?>
        <p style="color:var(--ink-soft);font-size:13px;">No selfie submitted yet.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:16px;">
        <?php while ($face = $faces->fetch_assoc()):
          $score = $face['similarity_score'] ?? null;
          $scoreColor = $score === null ? 'var(--ink-faint)' : ($score >= 80 ? 'var(--success)' : ($score >= 60 ? 'var(--gold)' : 'var(--danger)'));
        ?>
          <div style="border:1.5px solid var(--line);border-radius:12px;padding:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:14px;">
              <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Selfie</span>
              <span style="font-size:11.5px;color:var(--ink-soft);">Submitted <?= e(time_ago($face['created_at'])) ?></span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <?php if ($face['selfie_path']): ?>
              <div style="border-radius:8px;overflow:hidden;height:200px;border:1px solid var(--line);background:var(--cream);">
                <img src="<?= e('/safetrade/uploads/verification_docs/face_verifications/' . $face['selfie_path']) ?>"
                     style="width:100%;height:100%;object-fit:contain;cursor:pointer;"
                     onclick="openImageModal('<?= e('/safetrade/uploads/verification_docs/face_verifications/' . $face['selfie_path']) ?>','Selfie')"
                     alt="Selfie" title="Click to enlarge">
                <div style="font-size:10.5px;color:var(--ink-faint);text-align:center;padding:4px;">Selfie · click to enlarge</div>
              </div>
              <?php else: ?>
              <div style="border-radius:8px;height:200px;border:1px solid var(--line);background:var(--cream);display:flex;align-items:center;justify-content:center;color:var(--ink-soft);font-size:13px;">No selfie image</div>
              <?php endif; ?>
              <div style="background:var(--cream);border:1px solid var(--line);border-radius:8px;padding:14px;display:flex;flex-direction:column;gap:10px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Verification Scores</div>
                <div style="font-size:13px;display:flex;justify-content:space-between;align-items:center;">
                  <span style="color:var(--ink-soft);">Similarity Score</span>
                  <strong style="color:<?= $scoreColor ?>;"><?= $score !== null ? (int)$score . '%' : '—' ?></strong>
                </div>
                <div style="font-size:13px;display:flex;justify-content:space-between;align-items:center;">
                  <span style="color:var(--ink-soft);">Match Distance</span>
                  <strong><?= $face['match_distance'] !== null ? number_format((float)$face['match_distance'], 4) : '—' ?></strong>
                </div>
                <?php if ($score !== null): ?>
                <div style="height:6px;background:var(--line);border-radius:99px;overflow:hidden;margin-top:4px;">
                  <div style="height:100%;width:<?= min(100, (int)$score) ?>%;background:<?= $scoreColor ?>;border-radius:99px;"></div>
                </div>
                <div style="font-size:11px;color:var(--ink-soft);">
                  <?= $score >= 80 ? '✅ Strong match' : ($score >= 60 ? '⚠️ Acceptable — review carefully' : '❌ Poor match — likely reject') ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- =====================================================
         Overall Verification Status Section
         ===================================================== -->
    <div class="panel">
      <h2>Overall Verification Status</h2>
      <?php
        $vstmt = $conn->prepare('SELECT * FROM identity_verifications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1');
        $vstmt->bind_param('i', $userId);
        $vstmt->execute();
        $verification = $vstmt->get_result()->fetch_assoc();
        $vstmt->close();
      ?>
      <?php if (!$verification): ?>
        <p style="color:var(--ink-soft);font-size:13px;">No verification submission yet.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;max-width:520px;">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--cream);border-radius:10px;border:1px solid var(--line);">
            <span style="font-size:12px;font-weight:600;text-transform:uppercase;color:var(--ink-soft);">Status</span>
            <span class="pill <?= e($verification['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $verification['status']))) ?></span>
          </div>
          <?php if ($verification['submitted_at']): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--cream);border-radius:10px;border:1px solid var(--line);">
            <span style="font-size:12px;font-weight:600;text-transform:uppercase;color:var(--ink-soft);">Submitted</span>
            <span style="font-size:13px;"><?= e(date('M j, Y \a\t g:i A', strtotime($verification['submitted_at']))) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($verification['reviewed_at']): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--cream);border-radius:10px;border:1px solid var(--line);">
            <span style="font-size:12px;font-weight:600;text-transform:uppercase;color:var(--ink-soft);">Reviewed</span>
            <span style="font-size:13px;"><?= e(date('M j, Y \a\t g:i A', strtotime($verification['reviewed_at']))) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($verification['reviewed_by_admin_id'])): ?>
            <?php
              $revStmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
              $revStmt->bind_param('i', $verification['reviewed_by_admin_id']);
              $revStmt->execute();
              $reviewer = $revStmt->get_result()->fetch_assoc();
              $revStmt->close();
            ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--cream);border-radius:10px;border:1px solid var(--line);">
            <span style="font-size:12px;font-weight:600;text-transform:uppercase;color:var(--ink-soft);">Reviewed By</span>
            <span style="font-size:13px;"><?= $reviewer ? e($reviewer['first_name'] . ' ' . $reviewer['last_name']) : 'Unknown' ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($verification['rejection_reason'])): ?>
          <div style="padding:12px 14px;background:var(--danger-bg);border-left:4px solid var(--danger);border-radius:8px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--danger-dark);margin-bottom:5px;">Rejection Reason</div>
            <div style="font-size:13px;color:var(--ink);"><?= e($verification['rejection_reason']) ?></div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Quick action links to verifications queue -->
        <div style="margin-top:18px;">
          <a href="verifications.php" class="btn-sm">View in Verifications Queue →</a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent activity -->
    <div class="panel">
      <h2>Recent activity by this user</h2>
      <table class="data-table">
        <thead><tr><th>Action</th><th>Entity</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        <?php if ($activity->num_rows === 0): ?>
          <tr><td colspan="4"><div class="empty-state">No recorded activity.</div></td></tr>
        <?php else: while ($a = $activity->fetch_assoc()): ?>
          <tr>
            <td><?= e($a['action']) ?></td>
            <td><?= e(trim(($a['entity_type'] ?? '') . ' #' . ($a['entity_id'] ?? ''), ' #')) ?></td>
            <td><?= e($a['ip_address'] ?? '—') ?></td>
            <td><?= e(time_ago($a['created_at'])) ?></td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>

    <a href="users.php" class="btn-sm">← Back to users</a>

  </main>
</div>

<!-- Image lightbox (shared by all three sections) -->
<div id="imageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:9999;justify-content:center;align-items:center;">
  <div style="position:relative;max-width:92%;max-height:92%;background:white;border-radius:12px;padding:18px;">
    <button onclick="closeImageModal()" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:26px;cursor:pointer;z-index:10000;line-height:1;">✕</button>
    <img id="modalImage" src="" style="max-width:100%;max-height:82vh;object-fit:contain;display:block;">
    <div id="modalCaption" style="margin-top:10px;text-align:center;font-size:12.5px;color:var(--ink-soft);"></div>
  </div>
</div>
<script>
function openImageModal(src, caption) {
  document.getElementById('modalImage').src = src;
  document.getElementById('modalCaption').textContent = caption || '';
  document.getElementById('imageModal').style.display = 'flex';
}
function closeImageModal() {
  document.getElementById('imageModal').style.display = 'none';
}
document.getElementById('imageModal').addEventListener('click', function(e) {
  if (e.target === this) closeImageModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeImageModal();
});
</script>
</body>
</html>
