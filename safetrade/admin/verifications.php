<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

/* =========================================================
   POST: approve / reject / under_review
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action         = $_POST['action'] ?? '';
    $verificationId = (int) ($_POST['verification_id'] ?? 0);

    $stmt = $conn->prepare('SELECT user_id FROM identity_verifications WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $verificationId);
    $stmt->execute();
    $verifyRecord = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$verifyRecord) {
        flash_set('error', 'Verification not found.');
        redirect('/admin/verifications.php');
    }

    $userId = $verifyRecord['user_id'];

    if ($action === 'approve') {
        $stmt = $conn->prepare(
            'UPDATE identity_verifications
             SET status = "approved", reviewed_by_admin_id = ?, reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ii', $adminUser['id'], $verificationId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE users SET verification_status = "approved", status = "active" WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        audit_log($adminUser['id'], 'admin.verification.approve', 'user', $userId);
        flash_set('success', 'Verification approved. User is now verified.');

    } elseif ($action === 'reject') {
        $reason = clean_input($_POST['reason'] ?? '');
        $stmt = $conn->prepare(
            'UPDATE identity_verifications
             SET status = "rejected", rejection_reason = ?, reviewed_by_admin_id = ?, reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('sii', $reason, $adminUser['id'], $verificationId);
        $stmt->execute();
        $stmt->close();

        // Also block login the same way admin-dashboard.php's
        // reject_verification action does — otherwise the user is left
        // in "pending_verification" and sees the wrong message on
        // login instead of "your verification was not approved."
        $stmt = $conn->prepare('UPDATE users SET verification_status = "rejected", status = "pending_manual_review" WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        audit_log($adminUser['id'], 'admin.verification.reject', 'user', $userId, 'reason=' . substr($reason, 0, 100));
        flash_set('success', 'Verification rejected.');

    } elseif ($action === 'under_review') {
        $stmt = $conn->prepare(
            'UPDATE identity_verifications
             SET status = "under_review", reviewed_by_admin_id = ?, reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ii', $adminUser['id'], $verificationId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE users SET status = "pending_manual_review" WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        audit_log($adminUser['id'], 'admin.verification.under_review', 'user', $userId);
        flash_set('success', 'Marked for manual review.');
    }

    // NOTE: the old version of this redirect used
    //   redirect('/admin/verifications.php' . ($statusFilter = clean_input($_GET['status'] ?? '')) ? '?status=' . urlencode($statusFilter) : '');
    // — operator precedence made the ternary test the ASSIGNMENT
    // expression's truthiness (always true once PHP evaluates the
    // assignment), not the filter value, and string-concatenated the
    // filter onto the base path even when it was empty. Fixed below.
    $statusFilter = clean_input($_GET['status'] ?? '');
    redirect('/admin/verifications.php' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''));
}

/* =========================================================
   Query with filter + pagination
   ========================================================= */
$statusFilter = clean_input($_GET['status'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$whereConditions = [];
$params          = [];
$types           = '';

if (in_array($statusFilter, ['pending', 'under_review', 'approved', 'rejected'], true)) {
    $whereConditions[] = 'v.status = ?';
    $params[]          = $statusFilter;
    $types            .= 's';
}

$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$countStmt = $conn->prepare("SELECT COUNT(*) c FROM identity_verifications v $whereSql");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

$query = "
    SELECT
        v.id          AS verification_id,
        v.status,
        v.submitted_at,
        v.similarity_score,
        v.match_distance,
        v.rejection_reason,
        v.reviewed_by_admin_id,
        v.reviewed_at,
        g.id_type,
        g.front_path,
        g.back_path,
        f.selfie_path,
        f.similarity_score AS face_similarity,
        f.match_distance   AS face_match_distance,
        u.id               AS user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone_number,
        u.created_at       AS user_joined_at
    FROM identity_verifications v
    JOIN  users             u ON u.id       = v.user_id
    LEFT JOIN government_ids   g ON g.user_id  = v.user_id
    LEFT JOIN face_verifications f ON f.user_id = v.user_id
    $whereSql
    ORDER BY v.submitted_at DESC
    LIMIT ? OFFSET ?
";

$stmt      = $conn->prepare($query);
$allTypes  = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

$totalPages = max(1, (int) ceil($total / $perPage));

/* =========================================================
   Page
   ========================================================= */
$pageTitle = 'Verifications · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('verifications', $adminUser); ?>
  <main class="admin-main">

    <div class="admin-topbar">
      <div>
        <h1>Identity Verifications</h1>
        <p class="sub">Review queue for ID + face-match submissions (<?= number_format($total) ?> total).</p>
      </div>
    </div>

    <?php foreach (flash_get_all() as $f): ?>
      <div class="alert <?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <!-- Status filter -->
    <div class="search-row" style="margin-bottom:20px;">
      <select onchange="window.location.href='?status='+this.value">
        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
        <?php foreach (['pending', 'under_review', 'approved', 'rejected'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Verification cards -->
    <div class="panel" style="padding:0;background:transparent;box-shadow:none;">

      <?php if ($rows->num_rows === 0): ?>
        <div class="empty-state" style="background:var(--paper);border-radius:var(--radius);padding:40px;">No verification submissions found.</div>
      <?php else: while ($r = $rows->fetch_assoc()): ?>

        <div style="background:var(--paper);border-radius:var(--radius);padding:22px;margin-bottom:18px;">

          <!-- User header -->
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--line);">
            <div>
              <h3 style="margin:0;font-size:16px;"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></h3>
              <div style="font-size:12.5px;color:var(--ink-soft);margin-top:5px;line-height:1.6;">
                <?= e($r['email']) ?><br>
                Phone: <?= e($r['phone_number'] ?? '—') ?><br>
                Member since: <?= e(date('M j, Y', strtotime($r['user_joined_at']))) ?>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
              <span class="pill <?= e($r['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?></span>
              <?php if ($r['reviewed_at']): ?>
                <span style="font-size:11px;color:var(--ink-soft);">Reviewed <?= e(time_ago($r['reviewed_at'])) ?></span>
              <?php endif; ?>
              <a href="user-detail.php?id=<?= (int) $r['user_id'] ?>" class="btn-sm">View Profile</a>
            </div>
          </div>

          <!-- Documents grid -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:18px;">

            <!-- Government ID -->
            <div>
              <h4 style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Government ID</h4>
              <?php if ($r['front_path']): ?>
                <div style="background:var(--cream);border-radius:8px;padding:12px;margin-bottom:8px;">
                  <div style="font-size:12px;color:var(--ink-soft);margin-bottom:8px;">
                    Type: <strong><?= e($r['id_type'] ?? 'Unknown') ?></strong>
                  </div>
                  <div style="background:white;border-radius:6px;overflow:hidden;height:180px;margin-bottom:6px;">
                    <img src="<?= e('/safetrade/uploads/verification_docs/government_ids/' . $r['front_path']) ?>"
                         style="width:100%;height:100%;object-fit:contain;cursor:pointer;"
                         onclick="openImageModal('<?= e('/safetrade/uploads/verification_docs/government_ids/' . $r['front_path']) ?>','ID Front — <?= e($r['first_name'].' '.$r['last_name']) ?>')"
                         alt="ID Front">
                  </div>
                  <div style="font-size:11px;color:var(--ink-faint);">Front · click to enlarge</div>
                </div>
                <?php if ($r['back_path']): ?>
                  <div style="background:var(--cream);border-radius:8px;padding:12px;">
                    <div style="background:white;border-radius:6px;overflow:hidden;height:180px;margin-bottom:6px;">
                      <img src="<?= e('/safetrade/uploads/verification_docs/government_ids/' . $r['back_path']) ?>"
                           style="width:100%;height:100%;object-fit:contain;cursor:pointer;"
                           onclick="openImageModal('<?= e('/safetrade/uploads/verification_docs/government_ids/' . $r['back_path']) ?>','ID Back — <?= e($r['first_name'].' '.$r['last_name']) ?>')"
                           alt="ID Back">
                    </div>
                    <div style="font-size:11px;color:var(--ink-faint);">Back · click to enlarge</div>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div style="padding:20px;background:var(--cream);border-radius:8px;text-align:center;color:var(--ink-soft);font-size:13px;">No ID document uploaded</div>
              <?php endif; ?>
            </div>

            <!-- Face / Selfie -->
            <div>
              <h4 style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Face Verification</h4>
              <?php if ($r['selfie_path']): ?>
                <div style="background:var(--cream);border-radius:8px;padding:12px;">
                  <div style="background:white;border-radius:6px;overflow:hidden;height:180px;margin-bottom:6px;">
                    <img src="<?= e('/safetrade/uploads/verification_docs/face_verifications/' . $r['selfie_path']) ?>"
                         style="width:100%;height:100%;object-fit:contain;cursor:pointer;"
                         onclick="openImageModal('<?= e('/safetrade/uploads/verification_docs/face_verifications/' . $r['selfie_path']) ?>','Selfie — <?= e($r['first_name'].' '.$r['last_name']) ?>')"
                         alt="Selfie">
                  </div>
                  <div style="font-size:11px;color:var(--ink-faint);margin-bottom:10px;">Selfie · click to enlarge</div>
                  <div style="background:white;border-radius:6px;padding:10px;border:1px solid var(--line);font-size:12px;">
                    <?php
                      $score = $r['face_similarity'] ?? $r['similarity_score'] ?? null;
                      $scoreColor = $score === null ? 'var(--ink-faint)' : ($score >= 80 ? 'var(--success)' : ($score >= 60 ? 'var(--gold)' : 'var(--danger)'));
                    ?>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                      <span style="color:var(--ink-soft);">Similarity Score</span>
                      <strong style="color:<?= $scoreColor ?>;"><?= $score !== null ? (int)$score . '%' : '—' ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                      <span style="color:var(--ink-soft);">Match Distance</span>
                      <strong><?= $r['face_match_distance'] !== null ? number_format((float)$r['face_match_distance'], 4) : '—' ?></strong>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <div style="padding:20px;background:var(--cream);border-radius:8px;text-align:center;color:var(--ink-soft);font-size:13px;">No selfie uploaded — this user skipped face verification (optional).</div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($r['rejection_reason']): ?>
            <div style="background:var(--danger-bg);border-left:4px solid var(--danger);border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;">
              <strong style="color:var(--danger-dark);">Rejection reason:</strong> <?= e($r['rejection_reason']) ?>
            </div>
          <?php endif; ?>

          <!-- Actions -->
          <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--line);">
            <?php if ($r['status'] !== 'approved'): ?>
              <button class="btn-sm" style="background:var(--brand-light);color:var(--brand);border-color:var(--brand);"
                      onclick="approveVerification(<?= (int)$r['verification_id'] ?>)">✓ Approve</button>
            <?php endif; ?>
            <?php if ($r['status'] !== 'under_review'): ?>
              <button class="btn-sm" onclick="markUnderReview(<?= (int)$r['verification_id'] ?>)">🔍 Under Review</button>
            <?php endif; ?>
            <?php if ($r['status'] !== 'rejected'): ?>
              <button class="btn-sm danger" onclick="rejectVerification(<?= (int)$r['verification_id'] ?>)">✕ Reject</button>
            <?php endif; ?>
          </div>

        </div><!-- /card -->

      <?php endwhile; endif; ?>
    </div><!-- /panel -->

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?status=<?= urlencode($statusFilter) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<!-- Image lightbox -->
<div id="imageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:9999;justify-content:center;align-items:center;">
  <div style="position:relative;max-width:92%;max-height:92%;background:white;border-radius:12px;padding:18px;">
    <button onclick="closeImageModal()" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:26px;cursor:pointer;z-index:10000;line-height:1;">✕</button>
    <img id="modalImage" src="" style="max-width:100%;max-height:82vh;object-fit:contain;display:block;">
    <div id="modalCaption" style="margin-top:10px;text-align:center;font-size:12.5px;color:var(--ink-soft);"></div>
  </div>
</div>

<!-- Hidden action forms -->
<form id="approveForm" method="post" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="approve">
  <input type="hidden" name="verification_id" id="approveVerificationId">
</form>
<form id="rejectForm" method="post" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="reject">
  <input type="hidden" name="verification_id" id="rejectVerificationId">
  <input type="hidden" name="reason" id="rejectReason">
</form>
<form id="underReviewForm" method="post" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="under_review">
  <input type="hidden" name="verification_id" id="underReviewVerificationId">
</form>

<script>
function openImageModal(src, caption) {
  document.getElementById('modalImage').src = src;
  document.getElementById('modalCaption').textContent = caption;
  document.getElementById('imageModal').style.display = 'flex';
}
function closeImageModal() {
  document.getElementById('imageModal').style.display = 'none';
}
document.getElementById('imageModal').addEventListener('click', function(e) {
  if (e.target === this) closeImageModal();
});
function approveVerification(id) {
  if (confirm('Approve this verification? The user will be marked as verified.')) {
    document.getElementById('approveVerificationId').value = id;
    document.getElementById('approveForm').submit();
  }
}
function rejectVerification(id) {
  var reason = prompt('Reason for rejection (optional):', '');
  if (reason !== null) {
    document.getElementById('rejectVerificationId').value = id;
    document.getElementById('rejectReason').value = reason;
    document.getElementById('rejectForm').submit();
  }
}
function markUnderReview(id) {
  if (confirm('Flag this verification for closer manual review?')) {
    document.getElementById('underReviewVerificationId').value = id;
    document.getElementById('underReviewForm').submit();
  }
}
</script>
</body>
</html>
