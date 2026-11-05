<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/../includes/suggestions.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();
sg_ensure($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete_suggestion') {
            $sid = (int) ($_POST['suggestion_id'] ?? 0);
            $st = $conn->prepare('SELECT user_id, title FROM suggestions WHERE id = ? LIMIT 1');
            $st->bind_param('i', $sid);
            $st->execute();
            $sg = $st->get_result()->fetch_assoc();
            $st->close();

            if ($sg) {
                $d = $conn->prepare('DELETE FROM suggestion_comments WHERE suggestion_id = ?');
                $d->bind_param('i', $sid); $d->execute(); $d->close();
                $d = $conn->prepare('DELETE FROM suggestion_likes WHERE suggestion_id = ?');
                $d->bind_param('i', $sid); $d->execute(); $d->close();
                $d = $conn->prepare('DELETE FROM suggestions WHERE id = ?');
                $d->bind_param('i', $sid); $d->execute(); $d->close();
                audit_log($adminUser['id'], 'admin.suggestion.deleted', 'suggestion', $sid, 'title=' . mb_substr($sg['title'], 0, 80));
                inbox_push((int) $sg['user_id'], 'account', 'Your suggestion was removed',
                    '“' . mb_substr($sg['title'], 0, 80) . '” was removed by a moderator.', null, $sid, 'suggestion_removed');
                flash_set('success', 'Suggestion deleted.');
            }
        } elseif ($action === 'delete_comment') {
            $cid = (int) ($_POST['comment_id'] ?? 0);
            $d = $conn->prepare('DELETE FROM suggestion_comments WHERE id = ?');
            $d->bind_param('i', $cid); $d->execute(); $d->close();
            audit_log($adminUser['id'], 'admin.suggestion.comment_deleted', 'suggestion_comment', $cid);
            flash_set('success', 'Comment deleted.');
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] admin suggestions action failed: ' . $e->getMessage());
        flash_set('error', 'Something went wrong: ' . $e->getMessage());
    }
    redirect('/admin/suggestions.php' . (!empty($_GET['cat']) ? '?cat=' . urlencode((string) $_GET['cat']) : ''));
}

$filter = in_array($_GET['cat'] ?? '', SG_CATEGORIES, true) ? $_GET['cat'] : '';

$sql = 'SELECT s.*, u.first_name, u.last_name, u.email,
               (SELECT COUNT(*) FROM suggestion_likes l WHERE l.suggestion_id = s.id) AS like_count
        FROM suggestions s LEFT JOIN users u ON u.id = s.user_id'
     . ($filter !== '' ? ' WHERE s.category = ?' : '')
     . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 100';
$st = $conn->prepare($sql);
if ($filter !== '') $st->bind_param('s', $filter);
$st->execute();
$items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$comments = [];
if ($items) {
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $items));
    $res = $conn->query(
        "SELECT c.*, u.first_name, u.last_name FROM suggestion_comments c
         LEFT JOIN users u ON u.id = c.user_id WHERE c.suggestion_id IN ($ids) ORDER BY c.id ASC"
    );
    while ($r = $res->fetch_assoc()) $comments[(int) $r['suggestion_id']][] = $r;
}

$pageTitle = 'Suggestions · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<style>
.sgA-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;margin-bottom:14px;}
.sgA-top{display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;}
.sgA-top .who{flex:1;min-width:0;font-size:13px;}
.sgA-top .who span{display:block;font-size:11.5px;color:var(--ink-soft);}
.sgA-tag{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:3px 10px;border-radius:999px;background:var(--brand-light);color:var(--brand-dark);}
.sgA-tag.bug{background:var(--danger-bg);color:#8C331F;}
.sgA-tag.imp{background:var(--gold-light);color:#7A5308;}
.sgA-tag.oth{background:var(--line);color:var(--ink-soft);}
.sgA-title{font-family:'Fraunces',serif;font-size:17px;font-weight:600;}
.sgA-body{font-size:14px;line-height:1.65;color:var(--ink-soft);margin-top:6px;white-space:pre-line;word-break:break-word;}
.sgA-comments{margin-top:12px;padding-top:10px;border-top:1px solid var(--line);}
.sgA-comments summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--brand);}
.sgA-comment{display:flex;gap:8px;align-items:flex-start;background:var(--cream);border-radius:11px;padding:9px 12px;margin-top:8px;font-size:13px;line-height:1.5;}
.sgA-comment .txt{flex:1;min-width:0;word-break:break-word;white-space:pre-line;}
.sgA-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
</style>
<div class="admin-app">
  <?php render_admin_sidebar('suggestions', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Suggestions &amp; Feedback</h1><p class="sub">Ideas and bug reports posted by members (<?= count($items) ?> shown).</p></div></div>
    <?php render_flashes(); ?>

    <div class="sgA-chips">
      <a class="btn-sm" href="suggestions.php"<?= $filter === '' ? ' style="background:var(--brand);color:#fff;"' : '' ?>>All</a>
      <?php foreach (SG_CATEGORIES as $cat): ?>
        <a class="btn-sm" href="suggestions.php?cat=<?= urlencode($cat) ?>"<?= $filter === $cat ? ' style="background:var(--brand);color:#fff;"' : '' ?>><?= e($cat) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
      <div class="empty-state" style="background:var(--paper);border-radius:var(--radius);padding:40px;">No suggestions yet.</div>
    <?php endif; ?>

    <?php foreach ($items as $s):
      $sid = (int) $s['id'];
      $cm  = $comments[$sid] ?? [];
      $tagClass = $s['category'] === 'Bug report' ? 'bug' : ($s['category'] === 'Improvement' ? 'imp' : ($s['category'] === 'Other' ? 'oth' : '')); ?>
      <div class="sgA-card">
        <div class="sgA-top">
          <div class="who">
            <strong><?= e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: 'Deleted user') ?></strong>
            <span><?= e($s['email'] ?? '') ?> · <?= e(date('M j, Y g:i A', strtotime((string) $s['created_at']))) ?> · ❤️ <?= (int) $s['like_count'] ?></span>
          </div>
          <span class="sgA-tag <?= $tagClass ?>"><?= e($s['category']) ?></span>
          <form method="post" onsubmit="return confirm('Delete this suggestion and all its comments?');" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_suggestion">
            <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="btn-sm danger" type="submit">Delete</button>
          </form>
        </div>
        <div class="sgA-title"><?= e($s['title']) ?></div>
        <div class="sgA-body"><?= e($s['body']) ?></div>

        <details class="sgA-comments"<?= $cm ? ' open' : '' ?>>
          <summary>💬 <?= count($cm) ?> comment<?= count($cm) === 1 ? '' : 's' ?></summary>
          <?php foreach ($cm as $m): ?>
            <div class="sgA-comment">
              <div class="txt"><strong><?= e(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: 'Deleted user') ?></strong>
                <span style="font-size:11px;color:var(--ink-faint);margin-left:6px;"><?= e(time_ago((string) $m['created_at'])) ?></span><br><?= e($m['body']) ?></div>
              <form method="post" onsubmit="return confirm('Delete this comment?');" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_comment">
                <input type="hidden" name="comment_id" value="<?= (int) $m['id'] ?>">
                <button class="btn-sm danger" type="submit">✕</button>
              </form>
            </div>
          <?php endforeach; ?>
        </details>
      </div>
    <?php endforeach; ?>
  </main>
</div>
</body>
</html>
