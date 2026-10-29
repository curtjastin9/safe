<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$adminUser = require_role('admin', 'manager');
$adminId   = $adminUser['id'];
$conn      = db();

/* =========================================================
   POST actions (PRG pattern)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $back   = $_POST['back'] ?? 'overview';

    switch ($action) {

        // FIX: 'verify_user' and 'reject_verification' used to be one
        // combined case that only ever ran an UPDATE (approve -> active/
        // approved, reject -> pending_manual_review/rejected). That left
        // rejected accounts — plus their uploaded ID photos and any face
        // embedding — sitting in the database forever, permanently
        // blocking that email address from registering again. Rejecting
        // now deletes the account and its submitted documents entirely
        // (see the 'reject_verification' case below), so the two actions
        // need separate handling.
        case 'verify_user': {
            $targetId = (int) ($_POST['user_id'] ?? 0);

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $verifiedUser = $uStmt->get_result()->fetch_assoc();
            $uStmt->close();

            $stmt = $conn->prepare("UPDATE users SET verification_status='approved', status='active' WHERE id=?");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.user.verify_user', 'user', $targetId);
            flash_set('success', 'Verification updated.');

            if ($verifiedUser) {
                send_branded_email(
                    $verifiedUser['email'],
                    $verifiedUser['first_name'],
                    "You're verified on SafeTrade!",
                    "You're verified!",
                    'Hi ' . e($verifiedUser['first_name']) . ',<br><br>'
                        . 'Good news — your ID and face verification were approved. Your account is now active '
                        . 'and you can start buying and selling on SafeTrade.',
                    app_url('auth/login.php'),
                    'Log in to SafeTrade'
                );
            }
            break;
        }

        case 'reject_verification': {
            $targetId = (int) ($_POST['user_id'] ?? 0);

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $rejectedUser  = $uStmt->get_result()->fetch_assoc();
            $rejectedEmail = $rejectedUser['email'] ?? '';
            $uStmt->close();

            // Reject = throw out the whole registration attempt: delete
            // any uploaded ID photos, face embedding, the verification
            // record, and the account row itself, in a transaction. The
            // email becomes free again so the person can sign up fresh.
            $conn->begin_transaction();
            try {
                // FIX: audit_logs.actor_id is almost certainly the thing
                // that was blocking this delete — register_user() writes
                // an actor_id = $targetId row ('user.register') the
                // moment the account is created, and if that column has
                // a foreign key back to users.id, deleting the user row
                // fails with a constraint violation and the whole
                // transaction rolled back, which is exactly what produced
                // the generic "may have other activity blocking deletion"
                // message. audit_logs is meant to stay immutable, so we
                // detach the reference (NULL it out) rather than delete
                // those rows — the log entries themselves are preserved.
                try {
                    $anon = $conn->prepare('UPDATE audit_logs SET actor_id = NULL WHERE actor_id = ?');
                    $anon->bind_param('i', $targetId);
                    $anon->execute();
                    $anon->close();
                } catch (Throwable $e) {
                    // actor_id may be NOT NULL in some schema versions —
                    // if this was in fact the blocker, the outer catch
                    // below will now report the real reason instead of a
                    // generic one.
                }

                // Other tables a still-pending (never-approved) account
                // could plausibly have rows in, each with its own FK back
                // to users.id. Attempted independently so a missing
                // table/column in this install doesn't abort the cleanup.
                foreach (['user_sessions', 'password_resets', 'notifications'] as $table) {
                    try {
                        $del = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                        $del->bind_param('i', $targetId);
                        $del->execute();
                        $del->close();
                    } catch (Throwable $e) {
                        // table doesn't exist / no such column in this
                        // schema — nothing to clean up here, move on.
                    }
                }

                // FIX: same issue as dashboard.php — this loop ran
                // unguarded while the loop above it was already wrapped
                // in try/catch. identity_verifications doesn't exist in
                // this schema, so prepare() threw, the whole transaction
                // rolled back, and the account was never deleted.
                foreach (['government_ids', 'face_verifications', 'identity_verifications'] as $table) {
                    try {
                        $del = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                        $del->bind_param('i', $targetId);
                        $del->execute();
                        $del->close();
                    } catch (Throwable $e) {
                        // table doesn't exist in this schema — nothing to clean up here.
                    }
                }
                $del = $conn->prepare('DELETE FROM users WHERE id = ?');
                $del->bind_param('i', $targetId);
                $del->execute();
                $del->close();

                $conn->commit();
                audit_log(
                    $adminId,
                    'admin.user.reject_verification',
                    'user',
                    $targetId,
                    'deleted account, email=' . $rejectedEmail
                );
                flash_set('success', 'Registration rejected and removed — they can sign up again if they wish.');

                if ($rejectedEmail !== '') {
                    send_branded_email(
                        $rejectedEmail,
                        $rejectedUser['first_name'] ?? '',
                        'Your SafeTrade registration',
                        "We couldn't verify your registration",
                        'Hi ' . e($rejectedUser['first_name'] ?? '') . ',<br><br>'
                            . "Unfortunately we weren't able to approve your ID/face verification, so your "
                            . 'SafeTrade registration has been removed. You\'re welcome to sign up again with '
                            . 'a clearer photo of your government ID.',
                        app_url('auth/register.php'),
                        'Register again'
                    );
                }
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[SafeTrade] reject_verification delete failed: ' . $e->getMessage());
                // FIX: surface the actual DB error instead of a generic
                // message, so if something new is blocking deletion
                // (some other table with an FK we didn't anticipate)
                // it's diagnosable right from the admin UI, not just the
                // server's error log.
                flash_set('error', 'Could not remove this account: ' . $e->getMessage());
            }
            break;
        }

        case 'suspend_user':
        case 'ban_user':
        case 'reactivate_user': {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId === $adminId) {
                flash_set('error', "You can't act on your own account.");
                break;
            }

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $affectedUser = $uStmt->get_result()->fetch_assoc();
            $uStmt->close();

            $newStatus = match ($action) {
                'suspend_user'    => 'suspended',
                'ban_user'        => 'banned',
                'reactivate_user' => 'active',
            };
            if ($action === 'reactivate_user') {
                // Fully unblock: reactivating also clears any pending/
                // rejected verification hold so the account can log in.
                $stmt = $conn->prepare("UPDATE users SET status='active', verification_status='approved' WHERE id=?");
                $stmt->bind_param('i', $targetId);
            } else {
                $stmt = $conn->prepare('UPDATE users SET status=? WHERE id=?');
                $stmt->bind_param('si', $newStatus, $targetId);
            }
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.user.' . $action, 'user', $targetId);
            flash_set('success', 'User status updated — the account can now log in.');

            if ($affectedUser) {
                [$subject, $heading, $bodyHtml] = match ($action) {
                    'suspend_user' => [
                        'Your SafeTrade account has been suspended',
                        'Account suspended',
                        'Hi ' . e($affectedUser['first_name']) . ',<br><br>'
                            . 'Your SafeTrade account has been temporarily suspended by a moderator. '
                            . 'If you think this is a mistake, please contact support.',
                    ],
                    'ban_user' => [
                        'Your SafeTrade account has been banned',
                        'Account banned',
                        'Hi ' . e($affectedUser['first_name']) . ',<br><br>'
                            . 'Your SafeTrade account has been banned for violating our community guidelines. '
                            . 'This action is final unless you successfully appeal with support.',
                    ],
                    'reactivate_user' => [
                        'Your SafeTrade account is active again',
                        'Welcome back!',
                        'Hi ' . e($affectedUser['first_name']) . ',<br><br>'
                            . 'Your SafeTrade account has been reactivated and you can log in again right away.',
                    ],
                };
                send_branded_email(
                    $affectedUser['email'],
                    $affectedUser['first_name'],
                    $subject,
                    $heading,
                    $bodyHtml,
                    $action === 'reactivate_user' ? app_url('auth/login.php') : null,
                    $action === 'reactivate_user' ? 'Log in to SafeTrade' : null
                );
            }
            break;
        }

        case 'delete_product':
        case 'restore_product': {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $isDeleted = $action === 'delete_product' ? 1 : 0;
            $isActive  = $action === 'delete_product' ? 0 : 1;
            $stmt = $conn->prepare('UPDATE products SET is_deleted=?, is_active=? WHERE id=?');
            $stmt->bind_param('iii', $isDeleted, $isActive, $productId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.product.' . $action, 'product', $productId);
            flash_set('success', 'Listing updated.');
            break;
        }

        case 'report_action': {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $sub = $_POST['sub_action'] ?? '';
            $newStatus = match ($sub) {
                'reviewing' => 'reviewing',
                'resolve'   => 'resolved',
                'dismiss'   => 'dismissed',
                default     => null,
            };
            if ($newStatus) {
                $stmt = $conn->prepare(
                    "UPDATE reports SET status=?, resolved_at=IF(? IN ('resolved','dismissed'), NOW(), resolved_at), handled_by_admin_id=? WHERE id=?"
                );
                $stmt->bind_param('ssii', $newStatus, $newStatus, $adminId, $reportId);
                $stmt->execute();
                $stmt->close();
                audit_log($adminId, 'admin.report.' . $sub, 'report', $reportId);
                flash_set('success', 'Report updated.');
            }
            break;
        }

        case 'notify_reported': {
            $userIdTarget = (int) ($_POST['reported_user_id'] ?? 0);
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $title = 'You were reported';
            $body  = 'A report was filed against your account. A moderator is reviewing it. Please review SafeTrade\'s community guidelines.';
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'report', ?, ?)");
            $stmt->bind_param('iss', $userIdTarget, $title, $body);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.report.notified_user', 'user', $userIdTarget, 'report#' . $reportId);
            flash_set('success', 'User notified.');
            break;
        }

        case 'delete_group': {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $stmt = $conn->prepare('DELETE FROM groups WHERE id=?');
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.group.deleted', 'group', $groupId);
            flash_set('success', 'Group deleted.');
            $back = 'groups';
            break;
        }
    }
    // NOTE: this file is admin/dashboard.php, not admin-dashboard.php —
    // redirecting to the wrong filename here was causing a 404 after
    // every admin action (delete product, ban user, etc.) even though
    // the database update itself had already gone through.
    redirect('/admin/dashboard.php?tab=' . $back);
}

/* =========================================================
   Tab data
   ========================================================= */
function count_q(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    return (int) ($res->fetch_assoc()['c'] ?? 0);
}

$tab = $_GET['tab'] ?? 'overview';

$totalUsers    = count_q($conn, "SELECT COUNT(*) c FROM users WHERE role='user'");
$activeUsers   = count_q($conn, "SELECT COUNT(*) c FROM users WHERE role='user' AND status='active'");
$pendingVerifs = count_q($conn, "SELECT COUNT(*) c FROM users WHERE verification_status='pending'");
$openReports   = count_q($conn, "SELECT COUNT(*) c FROM reports WHERE status='open'");
$activeItems   = count_q($conn, "SELECT COUNT(*) c FROM products WHERE is_active=1 AND is_deleted=0");
$totalGroups   = count_q($conn, "SELECT COUNT(*) c FROM groups");
$completedTx   = count_q($conn, "SELECT COUNT(*) c FROM transactions WHERE status='completed'");

$data = [];

if ($tab === 'overview') {
    $data['recentUsers'] = $conn->query("SELECT id, first_name, last_name, email, status, created_at FROM users WHERE role='user' ORDER BY created_at DESC LIMIT 6");
    $data['recentAudit'] = $conn->query(
        "SELECT a.action, a.target_type, a.target_id, a.created_at, u.first_name, u.last_name
         FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.created_at DESC LIMIT 10"
    );
}

if ($tab === 'users') {
    $q = clean_input($_GET['q'] ?? '');
    $sql = "SELECT id, first_name, last_name, email, status, verification_status, trust_score, created_at FROM users WHERE role='user'";
    if ($q !== '') {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $sql .= " AND (first_name LIKE '$like' OR last_name LIKE '$like' OR email LIKE '$like')";
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 100';
    $data['users'] = $conn->query($sql);
    $data['q'] = $q;
}

if ($tab === 'verifications') {
    $data['pending'] = $conn->query("SELECT id, first_name, last_name, email, phone, status, created_at FROM users WHERE verification_status='pending' ORDER BY created_at ASC");
}

/* ---- AJAX: verification detail panel (called by the expand arrow) ----
   FIX: this used to require $tab === 'verifications', so the same expand
   panel couldn't be reused from the Users tab. It only ever needs
   $_GET['verif_detail'], so the tab restriction is dropped. ---- */
if (isset($_GET['verif_detail'])) {
    header('Content-Type: application/json');
    $uid = (int) $_GET['verif_detail'];
    $out = [];

    // Government ID
    try {
        $gs = $conn->prepare('SELECT id_type, front_path, back_path, created_at FROM government_ids WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
        $gs->bind_param('i', $uid); $gs->execute();
        $out['gov_id'] = $gs->get_result()->fetch_assoc();
        $gs->close();
    } catch (Throwable $e) { $out['gov_id'] = null; }

    // Face verification
    //
    // FIX: the browser-side face-api.js flow (face-verify-api.php ->
    // save_face_embedding()) only ever writes the `embedding` column —
    // it never captures or stores selfie_path/similarity_score/
    // match_distance, since matching happens entirely against a 128-float
    // descriptor, not a photo. The admin panel used to check only for
    // selfie_path, so a user who registered their face via the camera
    // (and can successfully log in with it) was shown "No face
    // verification submitted" here. has_embedding lets the panel tell
    // the two flows apart and report the embedding-only case correctly.
    try {
        $fs = $conn->prepare(
            'SELECT selfie_path, similarity_score, match_distance, created_at,
                    (embedding IS NOT NULL) AS has_embedding
             FROM face_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $fs->bind_param('i', $uid); $fs->execute();
        $out['face'] = $fs->get_result()->fetch_assoc();
        $fs->close();
    } catch (Throwable $e) { $out['face'] = null; }

    // Identity verification record
    try {
        $ivs = $conn->prepare('SELECT status, submitted_at, rejection_reason FROM identity_verifications WHERE user_id = ? LIMIT 1');
        $ivs->bind_param('i', $uid); $ivs->execute();
        $out['iv'] = $ivs->get_result()->fetch_assoc();
        $ivs->close();
    } catch (Throwable $e) { $out['iv'] = null; }

    echo json_encode($out);
    exit;
}

if ($tab === 'products') {
    $statusFilter = $_GET['status'] ?? 'active';
    $sql = "SELECT p.*, u.first_name, u.last_name FROM products p JOIN users u ON u.id=p.seller_id";
    $sql .= $statusFilter === 'deleted' ? ' WHERE p.is_deleted=1' : ' WHERE p.is_deleted=0';
    $sql .= ' ORDER BY p.created_at DESC LIMIT 100';
    $data['products'] = $conn->query($sql);
    $data['statusFilter'] = $statusFilter;
}

if ($tab === 'reports') {
    $statusFilter = in_array($_GET['status'] ?? '', ['open', 'reviewing', 'resolved', 'dismissed'], true) ? $_GET['status'] : 'open';
    $stmt = $conn->prepare(
        "SELECT r.*, ru.first_name rfn, ru.last_name rln, tu.id tuid, tu.first_name tfn, tu.last_name tln
         FROM reports r JOIN users ru ON ru.id=r.reporter_id LEFT JOIN users tu ON tu.id=r.reported_user_id
         WHERE r.status=? ORDER BY r.created_at DESC LIMIT 100"
    );
    $stmt->bind_param('s', $statusFilter);
    $stmt->execute();
    $data['reports'] = $stmt->get_result();
    $stmt->close();
    $data['statusFilter'] = $statusFilter;
}

if ($tab === 'groups') {
    $data['groups'] = $conn->query(
        "SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.status='approved') member_count,
                u.first_name owner_fn, u.last_name owner_ln
         FROM groups g JOIN users u ON u.id=g.owner_id ORDER BY g.created_at DESC LIMIT 100"
    );
}

function tab_link(string $key, string $label, string $current, string $iconPath, int $badge = 0): string
{
    $active = $key === $current ? ' active' : '';
    $b = $badge > 0 ? ' <span class="count">' . $badge . '</span>' : '';
    return '<a class="admin-link' . $active . '" href="?tab=' . $key . '">'
         . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $iconPath . '</svg>'
         . '<span>' . e($label) . '</span>' . $b . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin · SafeTrade</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/app.css">
<style>
/* ---- Match user dashboard sidebar exactly ---- */
.admin-app{display:grid;grid-template-columns:224px 1fr;min-height:100vh;}
.admin-side{
  background:linear-gradient(190deg,#155943 0%,#092C21 100%);
  color:#FBF7EF;padding:24px 14px;position:sticky;top:0;height:100vh;
  display:flex;flex-direction:column;box-shadow:2px 0 24px rgba(9,44,33,.14);z-index:5;
  transition:width .22s cubic-bezier(.22,.75,.28,1);overflow:hidden;
}
/* Logo row: burger + SafeTrade wordmark */
.ad-logo-row{display:flex;align-items:center;gap:11px;padding:8px 10px 24px;flex-shrink:0;}
.ad-burger{
  background:rgba(251,247,239,.10);border:none;color:#FBF7EF;
  width:32px;height:32px;border-radius:10px;display:flex;align-items:center;
  justify-content:center;cursor:pointer;flex-shrink:0;padding:0;
  transition:background .15s ease;
}
.ad-burger:hover{background:rgba(251,247,239,.20);}
.ad-burger svg{width:17px;height:17px;}
.ad-logo-text{
  display:flex;align-items:center;gap:9px;
  font-family:'Fraunces',serif;font-weight:700;font-size:18.5px;
  text-decoration:none;color:#FBF7EF;white-space:nowrap;
  letter-spacing:-.01em;
}
.ad-logo-text .seal{
  width:30px;height:30px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(160deg,rgba(251,247,239,.25),rgba(251,247,239,.10));
  border:1.5px solid rgba(251,247,239,.30);
  display:flex;align-items:center;justify-content:center;font-size:13px;
}
/* Nav links — identical to user dashboard .side-link */
.admin-nav{display:flex;flex-direction:column;gap:3px;flex:1;}
.admin-link{
  display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:13px;
  font-size:14px;font-weight:500;color:rgba(251,247,239,.70);
  text-decoration:none;position:relative;white-space:nowrap;
  transition:background .15s ease,color .15s ease,transform .15s ease;
}
.admin-link svg{width:18px;height:18px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
.admin-link:hover{background:rgba(251,247,239,.09);color:#FBF7EF;transform:translateX(1px);}
.admin-link.active{background:rgba(251,247,239,.16);color:#FBF7EF;font-weight:600;}
.admin-link.active::before{
  content:"";position:absolute;left:-14px;top:50%;transform:translateY(-50%);
  width:4px;height:20px;border-radius:0 4px 4px 0;background:#C6871F;
  box-shadow:0 0 10px rgba(198,135,31,.5);
}
.admin-link .count{
  margin-left:auto;background:#C6871F;color:#092C21;
  font-size:10.5px;font-weight:800;padding:2.5px 7.5px;
  border-radius:999px;flex-shrink:0;
}
.admin-divider{height:1px;background:rgba(251,247,239,.13);margin:14px 4px;}
.admin-foot{font-size:12px;color:rgba(251,247,239,.58);padding:8px;line-height:1.65;flex-shrink:0;}
.admin-foot a{color:#F5E6C2;font-weight:600;text-decoration:none;}
.admin-foot a:hover{color:#fff;}
.admin-main{padding:32px 36px 68px;overflow-y:auto;}

/* ---- Collapsed state: icons only ---- */
.admin-app{grid-template-columns:224px 1fr;}
.admin-app.sidebar-collapsed{grid-template-columns:72px 1fr;}
.admin-app.sidebar-collapsed .admin-side{width:72px;padding:24px 8px;}
.admin-app.sidebar-collapsed .ad-logo-row{justify-content:center;padding-left:0;padding-right:0;}
.admin-app.sidebar-collapsed .ad-logo-text{display:none;}
.admin-app.sidebar-collapsed .admin-link{justify-content:center;padding:12px;}
.admin-app.sidebar-collapsed .admin-link span:not(.count){display:none;}
.admin-app.sidebar-collapsed .admin-link.active::before{display:none;}
.admin-app.sidebar-collapsed .admin-link .count{
  position:absolute;top:5px;right:5px;margin-left:0;padding:2px 5px;font-size:9px;
}
.admin-app.sidebar-collapsed .admin-foot{display:none;}

@media(max-width:900px){
  .admin-app{grid-template-columns:72px 1fr;}
  .admin-side{padding:24px 8px;}
  .ad-logo-row{justify-content:center;padding-left:0;padding-right:0;}
  .ad-logo-text{display:none;}
  .admin-link{justify-content:center;padding:12px;}
  .admin-link span:not(.count){display:none;}
  .admin-link.active::before{display:none;}
  .admin-foot{display:none;}
}
</style>
</head>
<body>
<div class="admin-app" id="admin-app">
  <aside class="admin-side">

    <!-- Logo row: burger toggle + SafeTrade seal + wordmark -->
    <div class="ad-logo-row">
      <button type="button" class="ad-burger" id="ad-burger-btn" aria-label="Toggle sidebar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <line x1="4" y1="7"  x2="20" y2="7"/>
          <line x1="4" y1="12" x2="20" y2="12"/>
          <line x1="4" y1="17" x2="20" y2="17"/>
        </svg>
      </button>
      <a href="?tab=overview" class="ad-logo-text">
        <span class="seal">✓</span>
        SafeTrade
      </a>
    </div>

    <nav class="admin-nav">
      <?= tab_link('overview',       'Overview',       $tab, '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>') ?>
      <?= tab_link('users',          'Users',          $tab, '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.3 6.5-6.3s6.5 2.7 6.5 6.3"/><path d="M16 4.3a3.2 3.2 0 010 6.3"/><path d="M21.5 20c0-2.9-1.9-5.2-4.5-6"/>') ?>
      <?= tab_link('verifications',  'Verifications',  $tab, '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>', $pendingVerifs) ?>
      <?= tab_link('products',       'Products',       $tab, '<path d="M7 4h10l3 5-8 11L2 9z"/><path d="M2 9h18M9.5 4L7 9l5 11 5-11-2.5-5"/>') ?>
      <?= tab_link('reports',        'Reports',        $tab, '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L2.7 17a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>', $openReports) ?>
      <?= tab_link('groups',         'Groups',         $tab, '<circle cx="9" cy="8" r="3"/><path d="M2.5 19c0-3.3 2.9-5.8 6.5-5.8s6.5 2.5 6.5 5.8"/><path d="M16.5 6.5a3 3 0 010 5.8"/>') ?>
    </nav>

    <div class="admin-divider"></div>
    <div class="admin-foot">
      Signed in as<br>
      <strong style="color:#FBF7EF;"><?= e($adminUser['first_name'] . ' ' . $adminUser['last_name']) ?></strong><br>
      <a href="../auth/logout.php">Log out</a>
    </div>
  </aside>

  <script>
  (function(){
    var STORAGE_KEY = 'safetrade_admin_sidebar_collapsed';
    var app = document.getElementById('admin-app');
    var btn = document.getElementById('ad-burger-btn');
    function apply(c){ if(app) app.classList.toggle('sidebar-collapsed', c); }
    try{ apply(localStorage.getItem(STORAGE_KEY)==='1'); }catch(e){}
    if(btn) btn.addEventListener('click', function(){
      var c = !app.classList.contains('sidebar-collapsed');
      apply(c);
      try{ localStorage.setItem(STORAGE_KEY, c?'1':'0'); }catch(e){}
    });
  })();
  </script>

  <main class="admin-main">
    <?php foreach (flash_get_all() as $f): ?>
      <div class="alert <?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($tab === 'overview'): ?>
      <div class="admin-topbar"><div><h1>Overview</h1><p class="sub">What's happening across SafeTrade right now.</p></div></div>
      <div class="stat-cards">
        <div class="stat-card"><div class="label">Total users</div><div class="value"><?= number_format($totalUsers) ?></div></div>
        <div class="stat-card"><div class="label">Active users</div><div class="value"><?= number_format($activeUsers) ?></div></div>
        <div class="stat-card"><div class="label">Pending verifications</div><div class="value"><?= number_format($pendingVerifs) ?></div></div>
        <div class="stat-card"><div class="label">Open reports</div><div class="value"><?= number_format($openReports) ?></div></div>
        <div class="stat-card"><div class="label">Active listings</div><div class="value"><?= number_format($activeItems) ?></div></div>
        <div class="stat-card"><div class="label">Groups</div><div class="value"><?= number_format($totalGroups) ?></div></div>
        <div class="stat-card"><div class="label">Completed trades</div><div class="value"><?= number_format($completedTx) ?></div></div>
        <div class="stat-card"><div class="label">Environment</div><div class="value" style="font-size:18px;"><?= e(APP_ENV) ?></div></div>
      </div>

      <div class="panel"><h2>Recently registered users</h2>
        <table class="data-table">
          <thead><tr><th>User</th><th>Status</th><th>Joined</th></tr></thead>
          <tbody>
          <?php if ($data['recentUsers']->num_rows === 0): ?><tr><td colspan="3"><div class="empty-state">No users yet.</div></td></tr><?php endif; ?>
          <?php while ($u = $data['recentUsers']->fetch_assoc()): ?>
            <tr><td><?= e($u['first_name'] . ' ' . $u['last_name']) ?><br><span style="color:var(--ink-soft);font-size:11.5px;"><?= e($u['email']) ?></span></td>
              <td><span class="pill <?= e($u['status']) ?>"><?= e(ucfirst($u['status'])) ?></span></td>
              <td><?= e(time_ago($u['created_at'])) ?></td></tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="panel"><h2>Recent activity (audit log)</h2>
        <table class="data-table">
          <thead><tr><th>Actor</th><th>Action</th><th>Target</th><th>When</th></tr></thead>
          <tbody>
          <?php if ($data['recentAudit']->num_rows === 0): ?><tr><td colspan="4"><div class="empty-state">No activity yet.</div></td></tr><?php endif; ?>
          <?php while ($a = $data['recentAudit']->fetch_assoc()): ?>
            <tr><td><?= $a['first_name'] ? e($a['first_name'] . ' ' . $a['last_name']) : 'System' ?></td>
              <td><?= e($a['action']) ?></td>
              <td><?= e(trim(($a['target_type'] ?? '') . ' #' . ($a['target_id'] ?? ''), ' #')) ?></td>
              <td><?= e(time_ago($a['created_at'])) ?></td></tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'users'): ?>
      <div class="admin-topbar"><div><h1>Users</h1><p class="sub">Manage member accounts.</p></div></div>
      <form class="search-row" method="get">
        <input type="hidden" name="tab" value="users">
        <input type="text" name="q" placeholder="Search name or email…" value="<?= e($data['q']) ?>">
        <button type="submit" class="btn-sm">Search</button>
      </form>
      <style>
        .verif-expand-btn{background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px;color:var(--ink-soft);font-size:16px;line-height:1;transition:transform .2s ease,color .15s ease;}
        .verif-expand-btn:hover{color:var(--brand);}
        .verif-expand-btn.open{transform:rotate(180deg);color:var(--brand);}
        .verif-detail-row td{padding:0!important;border-bottom:2px solid var(--brand-light);}
        .verif-detail-panel{padding:20px 22px;background:var(--brand-light);border-radius:0 0 12px 12px;display:none;}
        .verif-detail-panel.open{display:block;}
        .verif-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:14px;}
        .verif-doc-box{background:var(--paper);border-radius:12px;padding:14px;}
        .verif-doc-box h4{margin:0 0 10px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-doc-img{width:100%;height:170px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid var(--line);cursor:pointer;display:block;}
        .verif-score-row{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line);}
        .verif-score-row:last-child{border-bottom:none;}
        .verif-info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
        .verif-info-chip{background:var(--paper);border-radius:10px;padding:10px 13px;}
        .verif-info-chip .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-info-chip .val{font-size:13.5px;font-weight:600;margin-top:3px;}
        .verif-loading{padding:20px;text-align:center;color:var(--ink-soft);font-size:13px;}
        /* Image lightbox */
        #verif-lightbox-users{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;justify-content:center;align-items:center;}
        #verif-lightbox-users.on{display:flex;}
        #verif-lightbox-users img{max-width:90vw;max-height:88vh;border-radius:10px;object-fit:contain;}
        #verif-lightbox-users-close{position:absolute;top:16px;right:20px;font-size:30px;color:#fff;background:none;border:none;cursor:pointer;line-height:1;}
        @media(max-width:700px){.verif-detail-grid,.verif-info-grid{grid-template-columns:1fr;}}
      </style>

      <div class="panel">
        <table class="data-table">
          <thead><tr><th></th><th>User</th><th>Status</th><th>Verification</th><th>Trust</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['users']->num_rows === 0): ?><tr><td colspan="6"><div class="empty-state">No users match.</div></td></tr><?php endif; ?>
          <?php while ($u = $data['users']->fetch_assoc()): ?>
            <tr class="verif-main-row" data-uid="<?= (int)$u['id'] ?>">
              <td style="width:36px;padding-right:0;">
                <button class="verif-expand-btn" title="Show verification details" data-uid="<?= (int)$u['id'] ?>">▼</button>
              </td>
              <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?><br><span style="color:var(--ink-soft);font-size:11.5px;"><?= e($u['email']) ?></span></td>
              <td><span class="pill <?= e($u['status']) ?>"><?= e(ucwords(str_replace('_',' ',$u['status']))) ?></span></td>
              <td><span class="pill <?= e($u['verification_status']) ?>"><?= e(ucfirst($u['verification_status'])) ?></span></td>
              <td><?= e((string)$u['trust_score']) ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;"><?= csrf_field() ?>
                  <input type="hidden" name="back" value="users">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <?php if ($u['status'] !== 'active'): ?><button class="btn-sm" name="action" value="reactivate_user">Reactivate</button><?php endif; ?>
                  <?php if ($u['status'] !== 'suspended'): ?><button class="btn-sm danger" name="action" value="suspend_user" onclick="return confirm('Suspend this account?');">Suspend</button><?php endif; ?>
                  <?php if ($u['status'] !== 'banned'): ?><button class="btn-sm danger" name="action" value="ban_user" onclick="return confirm('Ban this account?');">Ban</button><?php endif; ?>
                </form>
              </td>
            </tr>
            <tr class="verif-detail-row" id="verif-detail-users-<?= (int)$u['id'] ?>">
              <td colspan="6">
                <div class="verif-detail-panel" id="verif-panel-users-<?= (int)$u['id'] ?>">
                  <div class="verif-loading">Loading…</div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <!-- Lightbox (Users tab copy — separate ids so it doesn't clash with the Verifications tab's lightbox) -->
      <div id="verif-lightbox-users">
        <button id="verif-lightbox-users-close" onclick="document.getElementById('verif-lightbox-users').classList.remove('on')">✕</button>
        <img id="verif-lightbox-users-img" src="" alt="">
      </div>

      <script>
      (function(){
        var loaded = {};
        var base = '<?= e(BASE_URL) ?>';

        function openLightbox(src){ document.getElementById('verif-lightbox-users-img').src = src; document.getElementById('verif-lightbox-users').classList.add('on'); }
        document.getElementById('verif-lightbox-users').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('on'); });

        function score_color(s){ return s==null?'#999':(s>=80?'#22c55e':(s>=60?'#eab308':'#ef4444')); }
        function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

        function renderPanel(uid, data){
          var panel = document.getElementById('verif-panel-users-'+uid);

          var html = '';

          html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">Registration Details</div>';
          html += '<div class="verif-info-grid">';
          html += '<div class="verif-info-chip"><div class="lbl">Submitted</div><div class="val">'+(data.iv&&data.iv.submitted_at ? new Date(data.iv.submitted_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—')+'</div></div>';
          html += '<div class="verif-info-chip"><div class="lbl">IV Status</div><div class="val">'+(data.iv ? esc(data.iv.status.replace(/_/g,' ')) : '—')+'</div></div>';
          html += '<div class="verif-info-chip"><div class="lbl">ID Type</div><div class="val">'+(data.gov_id ? esc(data.gov_id.id_type) : 'Not uploaded')+'</div></div>';
          html += '</div>';

          html += '<div class="verif-detail-grid">';

          // Government ID column
          html += '<div class="verif-doc-box">';
          html += '<h4>Government ID</h4>';
          if(data.gov_id && data.gov_id.front_path){
            var frontSrc = base+'/uploads/verification_docs/government_ids/'+data.gov_id.front_path;
            html += '<div style="margin-bottom:8px;font-size:11.5px;color:var(--ink-soft);">Front</div>';
            html += '<img src="'+esc(frontSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(frontSrc)+'\');" alt="ID Front">';
            if(data.gov_id.back_path){
              var backSrc = base+'/uploads/verification_docs/government_ids/'+data.gov_id.back_path;
              html += '<div style="margin:10px 0 6px;font-size:11.5px;color:var(--ink-soft);">Back</div>';
              html += '<img src="'+esc(backSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(backSrc)+'\');" alt="ID Back">';
            }
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No ID document uploaded yet.</div>';
          }
          html += '</div>';

          // Face verification column
          html += '<div class="verif-doc-box">';
          html += '<h4>Face Verification</h4>';
          if(data.face && data.face.selfie_path){
            // Legacy/selfie-based flow — photo + similarity scores.
            var selfieSrc = base+'/uploads/verification_docs/face_verifications/'+data.face.selfie_path;
            html += '<img src="'+esc(selfieSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(selfieSrc)+'\');" alt="Selfie" style="margin-bottom:12px;">';
            var score = data.face.similarity_score;
            var sc = score_color(score);
            html += '<div style="background:#f5f5f5;border-radius:8px;padding:10px 12px;">';
            html += '<div class="verif-score-row"><span style="color:var(--ink-soft);">Similarity Score</span><strong style="color:'+sc+';">'+(score!=null?Math.round(score)+'%':'—')+'</strong></div>';
            html += '<div class="verif-score-row"><span style="color:var(--ink-soft);">Match Distance</span><strong>'+(data.face.match_distance!=null?parseFloat(data.face.match_distance).toFixed(4):'—')+'</strong></div>';
            if(score!=null){
              var label = score>=80?'✅ Strong match':(score>=60?'⚠️ Review carefully':'❌ Poor match');
              html += '<div style="font-size:11px;color:var(--ink-soft);margin-top:6px;">'+label+'</div>';
            }
            html += '</div>';
          } else if (data.face && (data.face.has_embedding == 1 || data.face.has_embedding === true)) {
            // face-api.js flow — no photo is ever stored, only a 128-float
            // descriptor computed on-device in the browser. A row existing
            // here means the user's face IS registered and face-login will
            // work for them, even though there's no selfie to display.
            var regDate = data.face.created_at
              ? new Date(data.face.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
              : 'an unknown date';
            html += '<div style="padding:16px;background:var(--brand-light);border-radius:8px;color:var(--brand);font-size:13px;line-height:1.5;">'
                  + '✅ <strong>Face verified</strong> — registered ' + esc(regDate) + '.<br>'
                  + 'This app stores a mathematical face descriptor for on-device matching, not a photo, so there\'s no selfie image to display here.'
                  + '</div>';
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No face verification submitted (optional step).</div>';
          }
          html += '</div>';

          html += '</div>'; // .verif-detail-grid

          panel.innerHTML = html;
        }

        document.querySelectorAll('.verif-expand-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var uid = btn.getAttribute('data-uid');
            var panel = document.getElementById('verif-panel-users-'+uid);
            var isOpen = panel.classList.contains('open');

            document.querySelectorAll('.verif-detail-panel.open').forEach(function(p){ p.classList.remove('open'); });
            document.querySelectorAll('.verif-expand-btn.open').forEach(function(b){ b.classList.remove('open'); });

            if(isOpen) return;

            panel.classList.add('open');
            btn.classList.add('open');

            if(loaded[uid]) return;
            loaded[uid] = true;

            fetch('?tab=users&verif_detail='+encodeURIComponent(uid), {credentials:'same-origin'})
              .then(function(r){ return r.json(); })
              .then(function(data){ renderPanel(uid, data); })
              .catch(function(){ document.getElementById('verif-panel-users-'+uid).innerHTML='<div class="verif-loading">Failed to load details.</div>'; });
          });
        });
      })();
      </script>

    <?php elseif ($tab === 'verifications'): ?>
      <div class="admin-topbar"><div><h1>Identity Verifications</h1><p class="sub">Accounts waiting on manual face/ID review.</p></div></div>

      <style>
        .verif-expand-btn{background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px;color:var(--ink-soft);font-size:16px;line-height:1;transition:transform .2s ease,color .15s ease;}
        .verif-expand-btn:hover{color:var(--brand);}
        .verif-expand-btn.open{transform:rotate(180deg);color:var(--brand);}
        .verif-detail-row td{padding:0!important;border-bottom:2px solid var(--brand-light);}
        .verif-detail-panel{padding:20px 22px;background:var(--brand-light);border-radius:0 0 12px 12px;display:none;}
        .verif-detail-panel.open{display:block;}
        .verif-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:14px;}
        .verif-doc-box{background:var(--paper);border-radius:12px;padding:14px;}
        .verif-doc-box h4{margin:0 0 10px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-doc-img{width:100%;height:170px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid var(--line);cursor:pointer;display:block;}
        .verif-score-row{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line);}
        .verif-score-row:last-child{border-bottom:none;}
        .verif-info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
        .verif-info-chip{background:var(--paper);border-radius:10px;padding:10px 13px;}
        .verif-info-chip .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-info-chip .val{font-size:13.5px;font-weight:600;margin-top:3px;}
        .verif-loading{padding:20px;text-align:center;color:var(--ink-soft);font-size:13px;}
        /* Image lightbox */
        #verif-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;justify-content:center;align-items:center;}
        #verif-lightbox.on{display:flex;}
        #verif-lightbox img{max-width:90vw;max-height:88vh;border-radius:10px;object-fit:contain;}
        #verif-lightbox-close{position:absolute;top:16px;right:20px;font-size:30px;color:#fff;background:none;border:none;cursor:pointer;line-height:1;}
        @media(max-width:700px){.verif-detail-grid,.verif-info-grid{grid-template-columns:1fr;}}
      </style>

      <div class="panel">
        <table class="data-table" id="verif-table">
          <thead><tr><th></th><th>User</th><th>Submitted</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if ($data['pending']->num_rows === 0): ?>
            <tr><td colspan="4"><div class="empty-state">Nothing pending review.</div></td></tr>
          <?php endif; ?>
          <?php while ($v = $data['pending']->fetch_assoc()): ?>
            <tr class="verif-main-row" data-uid="<?= (int)$v['id'] ?>">
              <td style="width:36px;padding-right:0;">
                <button class="verif-expand-btn" title="Show verification details" data-uid="<?= (int)$v['id'] ?>">▼</button>
              </td>
              <td>
                <?= e($v['first_name'] . ' ' . $v['last_name']) ?><br>
                <span style="color:var(--ink-soft);font-size:11.5px;"><?= e($v['email']) ?></span>
                <?php if (!empty($v['phone'])): ?><br><span style="color:var(--ink-soft);font-size:11.5px;"><?= e($v['phone']) ?></span><?php endif; ?>
              </td>
              <td><?= e(time_ago($v['created_at'])) ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;"><?= csrf_field() ?>
                  <input type="hidden" name="back" value="verifications">
                  <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                  <button class="btn-sm" name="action" value="verify_user">Approve</button>
                  <button class="btn-sm danger" name="action" value="reject_verification" onclick="return confirm('Reject this registration? This permanently deletes the account and any uploaded ID/face data.');">Reject &amp; delete</button>
                </form>
              </td>
            </tr>
            <tr class="verif-detail-row" id="verif-detail-<?= (int)$v['id'] ?>">
              <td colspan="4">
                <div class="verif-detail-panel" id="verif-panel-<?= (int)$v['id'] ?>">
                  <div class="verif-loading">Loading…</div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <!-- Lightbox -->
      <div id="verif-lightbox">
        <button id="verif-lightbox-close" onclick="document.getElementById('verif-lightbox').classList.remove('on')">✕</button>
        <img id="verif-lightbox-img" src="" alt="">
      </div>

      <script>
      (function(){
        var loaded = {};
        var base = '<?= e(BASE_URL) ?>';

        function openLightbox(src){ document.getElementById('verif-lightbox-img').src = src; document.getElementById('verif-lightbox').classList.add('on'); }
        document.getElementById('verif-lightbox').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('on'); });

        function score_color(s){ return s==null?'#999':(s>=80?'#22c55e':(s>=60?'#eab308':'#ef4444')); }
        function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

        function renderPanel(uid, data){
          var panel = document.getElementById('verif-panel-'+uid);

          var html = '';

          // Personal info chips
          html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">Registration Details</div>';
          html += '<div class="verif-info-grid">';
          html += '<div class="verif-info-chip"><div class="lbl">Submitted</div><div class="val">'+(data.iv&&data.iv.submitted_at ? new Date(data.iv.submitted_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—')+'</div></div>';
          html += '<div class="verif-info-chip"><div class="lbl">IV Status</div><div class="val">'+(data.iv ? esc(data.iv.status.replace(/_/g,' ')) : '—')+'</div></div>';
          html += '<div class="verif-info-chip"><div class="lbl">ID Type</div><div class="val">'+(data.gov_id ? esc(data.gov_id.id_type) : 'Not uploaded')+'</div></div>';
          html += '</div>';

          html += '<div class="verif-detail-grid">';

          // Government ID column
          html += '<div class="verif-doc-box">';
          html += '<h4>Government ID</h4>';
          if(data.gov_id && data.gov_id.front_path){
            var frontSrc = base+'/uploads/verification_docs/government_ids/'+data.gov_id.front_path;
            html += '<div style="margin-bottom:8px;font-size:11.5px;color:var(--ink-soft);">Front</div>';
            html += '<img src="'+esc(frontSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(frontSrc)+'\');" alt="ID Front">';
            if(data.gov_id.back_path){
              var backSrc = base+'/uploads/verification_docs/government_ids/'+data.gov_id.back_path;
              html += '<div style="margin:10px 0 6px;font-size:11.5px;color:var(--ink-soft);">Back</div>';
              html += '<img src="'+esc(backSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(backSrc)+'\');" alt="ID Back">';
            }
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No ID document uploaded yet.</div>';
          }
          html += '</div>';

          // Face verification column
          html += '<div class="verif-doc-box">';
          html += '<h4>Face Verification</h4>';
          if(data.face && data.face.selfie_path){
            // Legacy/selfie-based flow — photo + similarity scores.
            var selfieSrc = base+'/uploads/verification_docs/face_verifications/'+data.face.selfie_path;
            html += '<img src="'+esc(selfieSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(selfieSrc)+'\');" alt="Selfie" style="margin-bottom:12px;">';
            var score = data.face.similarity_score;
            var sc = score_color(score);
            html += '<div style="background:#f5f5f5;border-radius:8px;padding:10px 12px;">';
            html += '<div class="verif-score-row"><span style="color:var(--ink-soft);">Similarity Score</span><strong style="color:'+sc+';">'+(score!=null?Math.round(score)+'%':'—')+'</strong></div>';
            html += '<div class="verif-score-row"><span style="color:var(--ink-soft);">Match Distance</span><strong>'+(data.face.match_distance!=null?parseFloat(data.face.match_distance).toFixed(4):'—')+'</strong></div>';
            if(score!=null){
              var label = score>=80?'✅ Strong match':(score>=60?'⚠️ Review carefully':'❌ Poor match');
              html += '<div style="font-size:11px;color:var(--ink-soft);margin-top:6px;">'+label+'</div>';
            }
            html += '</div>';
          } else if (data.face && (data.face.has_embedding == 1 || data.face.has_embedding === true)) {
            // face-api.js flow — no photo is ever stored, only a 128-float
            // descriptor computed on-device in the browser. A row existing
            // here means the user's face IS registered and face-login will
            // work for them, even though there's no selfie to display.
            var regDate = data.face.created_at
              ? new Date(data.face.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
              : 'an unknown date';
            html += '<div style="padding:16px;background:var(--brand-light);border-radius:8px;color:var(--brand);font-size:13px;line-height:1.5;">'
                  + '✅ <strong>Face verified</strong> — registered ' + esc(regDate) + '.<br>'
                  + 'This app stores a mathematical face descriptor for on-device matching, not a photo, so there\'s no selfie image to display here.'
                  + '</div>';
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No face verification submitted (optional step).</div>';
          }
          html += '</div>';

          html += '</div>'; // .verif-detail-grid

          panel.innerHTML = html;
        }

        document.querySelectorAll('.verif-expand-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var uid = btn.getAttribute('data-uid');
            var panel = document.getElementById('verif-panel-'+uid);
            var isOpen = panel.classList.contains('open');

            // close all others
            document.querySelectorAll('.verif-detail-panel.open').forEach(function(p){ p.classList.remove('open'); });
            document.querySelectorAll('.verif-expand-btn.open').forEach(function(b){ b.classList.remove('open'); });

            if(isOpen) return; // toggle off

            panel.classList.add('open');
            btn.classList.add('open');

            if(loaded[uid]) return; // already fetched
            loaded[uid] = true;

            fetch('?tab=verifications&verif_detail='+encodeURIComponent(uid), {credentials:'same-origin'})
              .then(function(r){ return r.json(); })
              .then(function(data){ renderPanel(uid, data); })
              .catch(function(){ document.getElementById('verif-panel-'+uid).innerHTML='<div class="verif-loading">Failed to load details.</div>'; });
          });
        });
      })();
      </script>

    <?php elseif ($tab === 'products'): ?>
      <div class="admin-topbar"><div><h1>Products</h1><p class="sub">Moderate marketplace listings.</p></div></div>
      <form class="search-row" method="get">
        <input type="hidden" name="tab" value="products">
        <select name="status" onchange="this.form.submit()">
          <option value="active" <?= $data['statusFilter']==='active'?'selected':'' ?>>Active</option>
          <option value="deleted" <?= $data['statusFilter']==='deleted'?'selected':'' ?>>Removed</option>
        </select>
      </form>
      <div class="panel">
        <table class="data-table">
          <thead><tr><th>Item</th><th>Seller</th><th>Price</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['products']->num_rows === 0): ?><tr><td colspan="4"><div class="empty-state">No listings.</div></td></tr><?php endif; ?>
          <?php while ($p = $data['products']->fetch_assoc()): ?>
            <tr>
              <td><?= e($p['icon']) ?> <?= e($p['title']) ?></td>
              <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
              <td>₱<?= number_format((float)$p['price'], 2) ?></td>
              <td>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="back" value="products">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <?php if (!$p['is_deleted']): ?>
                    <button class="btn-sm danger" name="action" value="delete_product" onclick="return confirm('Remove this listing?');">Remove</button>
                  <?php else: ?>
                    <button class="btn-sm" name="action" value="restore_product">Restore</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'reports'): ?>
      <div class="admin-topbar"><div><h1>Reports</h1><p class="sub">User reports awaiting moderator review.</p></div></div>
      <form class="search-row" method="get">
        <input type="hidden" name="tab" value="reports">
        <select name="status" onchange="this.form.submit()">
          <?php foreach (['open','reviewing','resolved','dismissed'] as $s): ?>
            <option value="<?= $s ?>" <?= $data['statusFilter']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="panel">
        <table class="data-table">
          <thead><tr><th>Reported by</th><th>Against</th><th>Type</th><th>Details</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['reports']->num_rows === 0): ?><tr><td colspan="5"><div class="empty-state">No reports in this view.</div></td></tr><?php endif; ?>
          <?php while ($r = $data['reports']->fetch_assoc()): ?>
            <tr>
              <td><?= e($r['rfn'] . ' ' . $r['rln']) ?></td>
              <td><?= $r['tuid'] ? e($r['tfn'] . ' ' . $r['tln']) : '—' ?></td>
              <td><span class="pill <?= e($r['status']) ?>"><?= e($r['type']) ?></span></td>
              <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($r['description']) ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;"><?= csrf_field() ?>
                  <input type="hidden" name="back" value="reports">
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <?php if ($r['status']==='open'): ?><button class="btn-sm" name="sub_action" value="reviewing" formaction="?tab=reports" onclick="this.form.action.value='report_action'">Start review</button><?php endif; ?>
                  <input type="hidden" name="action" value="report_action">
                  <?php if ($r['status']!=='resolved'): ?><button class="btn-sm" name="sub_action" value="resolve">Resolve</button><?php endif; ?>
                  <?php if ($r['status']!=='dismissed'): ?><button class="btn-sm danger" name="sub_action" value="dismiss">Dismiss</button><?php endif; ?>
                </form>
                <?php if ($r['tuid']): ?>
                <form method="post" style="margin-top:6px;"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="notify_reported">
                  <input type="hidden" name="back" value="reports">
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="reported_user_id" value="<?= (int)$r['tuid'] ?>">
                  <button class="btn-sm">Notify user</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'groups'): ?>
      <div class="admin-topbar"><div><h1>Groups</h1><p class="sub">Community groups across SafeTrade.</p></div></div>
      <div class="panel">
        <table class="data-table">
          <thead><tr><th>Group</th><th>Owner</th><th>Members</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['groups']->num_rows === 0): ?><tr><td colspan="5"><div class="empty-state">No groups yet.</div></td></tr><?php endif; ?>
          <?php while ($g = $data['groups']->fetch_assoc()): ?>
            <tr>
              <td><?= e($g['icon']) ?> <?= e($g['name']) ?></td>
              <td><?= e($g['owner_fn'] . ' ' . $g['owner_ln']) ?></td>
              <td><?= (int)$g['member_count'] ?></td>
              <td><?= e(time_ago($g['created_at'])) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Delete this group permanently?');"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_group">
                  <input type="hidden" name="back" value="groups">
                  <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                  <button class="btn-sm danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
