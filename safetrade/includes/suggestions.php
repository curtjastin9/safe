<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * Suggestions & Feedback — members post ideas / bug reports, everyone
 * else can comment and like. Lives at dashboard.php?view=suggestions.
 * Admins moderate from admin/suggestions.php.
 *
 * Tables are created automatically (CREATE TABLE IF NOT EXISTS) and use
 * NO foreign keys, so deleting a user can never be blocked by these rows.
 * ---------------------------------------------------------------------
 */

const SG_CATEGORIES = ['Feature idea', 'Improvement', 'Bug report', 'Other'];

function sg_ensure(mysqli $c): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c->query("CREATE TABLE IF NOT EXISTS suggestions (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'Other',
            title VARCHAR(150) NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $c->query("CREATE TABLE IF NOT EXISTS suggestion_comments (
            id INT NOT NULL AUTO_INCREMENT,
            suggestion_id INT NOT NULL,
            user_id INT NOT NULL,
            body VARCHAR(600) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_suggestion (suggestion_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $c->query("CREATE TABLE IF NOT EXISTS suggestion_likes (
            id INT NOT NULL AUTO_INCREMENT,
            suggestion_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_sg_like (suggestion_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[SafeTrade] sg_ensure failed: ' . $e->getMessage());
    }
}

/** Handles every sg_* POST action. Returns normally for any other action. */
function suggestions_handle_post(mysqli $c, int $uid, string $action): void
{
    if (strpos($action, 'sg_') !== 0) return;
    sg_ensure($c);
    $back = '/dashboard.php?view=suggestions';

    try {
        switch ($action) {
            case 'sg_add': {
                $cat   = in_array($_POST['category'] ?? '', SG_CATEGORIES, true) ? $_POST['category'] : 'Other';
                $title = mb_substr(trim(clean_input($_POST['title'] ?? '')), 0, 150);
                $body  = mb_substr(trim(clean_input($_POST['body'] ?? '')), 0, 2000);
                if ($title === '' || $body === '') {
                    flash_set('error', 'Please add a title and some details.');
                    break;
                }
                if (chat_find_bad_word($title . ' ' . $body) !== null) {
                    flash_set('error', chat_bad_word_message());
                    break;
                }
                $st = $c->prepare('INSERT INTO suggestions (user_id, category, title, body) VALUES (?,?,?,?)');
                $st->bind_param('isss', $uid, $cat, $title, $body);
                $st->execute();
                $newId = (int) $c->insert_id;
                $st->close();
                audit_log($uid, 'user.suggestion.posted', 'suggestion', $newId);
                if (function_exists('inbox_push_staff')) {
                    inbox_push_staff('account', 'New suggestion: ' . mb_substr($title, 0, 80),
                        'A member posted a new ' . strtolower($cat) . '.', '/admin/suggestions.php', $newId, 'staff_suggestion', [$uid]);
                }
                flash_set('success', 'Thanks! Your suggestion is posted.');
                break;
            }

            case 'sg_like': {
                $sid = (int) ($_POST['suggestion_id'] ?? 0);
                if ($sid <= 0) break;
                $st = $c->prepare('SELECT id FROM suggestions WHERE id = ? LIMIT 1');
                $st->bind_param('i', $sid);
                $st->execute();
                $exists = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$exists) break;

                $st = $c->prepare('SELECT id FROM suggestion_likes WHERE suggestion_id = ? AND user_id = ? LIMIT 1');
                $st->bind_param('ii', $sid, $uid);
                $st->execute();
                $liked = $st->get_result()->fetch_assoc();
                $st->close();

                if ($liked) {
                    $d = $c->prepare('DELETE FROM suggestion_likes WHERE id = ?');
                    $lid = (int) $liked['id'];
                    $d->bind_param('i', $lid); $d->execute(); $d->close();
                } else {
                    $i = $c->prepare('INSERT IGNORE INTO suggestion_likes (suggestion_id, user_id) VALUES (?,?)');
                    $i->bind_param('ii', $sid, $uid); $i->execute(); $i->close();
                }
                $back .= '#sg-' . $sid;
                break;
            }

            case 'sg_comment': {
                $sid  = (int) ($_POST['suggestion_id'] ?? 0);
                $body = mb_substr(trim(clean_input($_POST['body'] ?? '')), 0, 600);
                if ($sid <= 0 || $body === '') break;
                if (chat_find_bad_word($body) !== null) {
                    flash_set('error', chat_bad_word_message());
                    break;
                }
                $st = $c->prepare('SELECT user_id, title FROM suggestions WHERE id = ? LIMIT 1');
                $st->bind_param('i', $sid);
                $st->execute();
                $sg = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$sg) { flash_set('error', 'That suggestion no longer exists.'); break; }

                $st = $c->prepare('INSERT INTO suggestion_comments (suggestion_id, user_id, body) VALUES (?,?,?)');
                $st->bind_param('iis', $sid, $uid, $body);
                $st->execute();
                $st->close();

                if ((int) $sg['user_id'] !== $uid && function_exists('inbox_push')) {
                    inbox_push((int) $sg['user_id'], 'account', 'New comment on your suggestion',
                        '“' . mb_substr($sg['title'], 0, 80) . '” got a new comment.',
                        '/dashboard.php?view=suggestions', $sid, 'suggestion_comment');
                }
                $back .= '#sg-' . $sid;
                break;
            }

            case 'sg_delete': {
                $sid = (int) ($_POST['suggestion_id'] ?? 0);
                $st = $c->prepare('SELECT id FROM suggestions WHERE id = ? AND user_id = ? LIMIT 1');
                $st->bind_param('ii', $sid, $uid);
                $st->execute();
                $own = $st->get_result()->fetch_assoc();
                $st->close();
                if ($own) {
                    $d = $c->prepare('DELETE FROM suggestion_comments WHERE suggestion_id = ?');
                    $d->bind_param('i', $sid); $d->execute(); $d->close();
                    $d = $c->prepare('DELETE FROM suggestion_likes WHERE suggestion_id = ?');
                    $d->bind_param('i', $sid); $d->execute(); $d->close();
                    $d = $c->prepare('DELETE FROM suggestions WHERE id = ? AND user_id = ?');
                    $d->bind_param('ii', $sid, $uid); $d->execute(); $d->close();
                    flash_set('success', 'Suggestion deleted.');
                }
                break;
            }

            case 'sg_delete_comment': {
                $cid = (int) ($_POST['comment_id'] ?? 0);
                $sid = (int) ($_POST['suggestion_id'] ?? 0);
                $d = $c->prepare('DELETE FROM suggestion_comments WHERE id = ? AND user_id = ?');
                $d->bind_param('ii', $cid, $uid);
                $d->execute();
                $d->close();
                $back .= '#sg-' . $sid;
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] suggestions action failed: ' . $e->getMessage());
        flash_set('error', 'Something went wrong — please try again.');
    }
    redirect($back);
}

function suggestions_render(mysqli $c, int $uid): void
{
    sg_ensure($c);
    $filter = in_array($_GET['cat'] ?? '', SG_CATEGORIES, true) ? $_GET['cat'] : '';

    $sql = 'SELECT s.*, u.first_name, u.last_name,
                   (SELECT COUNT(*) FROM suggestion_likes l WHERE l.suggestion_id = s.id) AS like_count,
                   (SELECT COUNT(*) FROM suggestion_likes l WHERE l.suggestion_id = s.id AND l.user_id = ?) AS liked_by_me
            FROM suggestions s LEFT JOIN users u ON u.id = s.user_id'
         . ($filter !== '' ? ' WHERE s.category = ?' : '')
         . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 50';
    $st = $c->prepare($sql);
    if ($filter !== '') $st->bind_param('is', $uid, $filter);
    else                $st->bind_param('i', $uid);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $comments = [];
    if ($items) {
        $ids = implode(',', array_map(fn($r) => (int) $r['id'], $items));
        $res = $c->query(
            "SELECT c.*, u.first_name, u.last_name FROM suggestion_comments c
             LEFT JOIN users u ON u.id = c.user_id WHERE c.suggestion_id IN ($ids) ORDER BY c.id ASC"
        );
        while ($r = $res->fetch_assoc()) $comments[(int) $r['suggestion_id']][] = $r;
    }
    ?>
<style>
.sg-wrap{max-width:760px;}
.sg-composer{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);padding:20px 22px;margin-bottom:22px;}
.sg-composer h3{font-size:16px;margin-bottom:4px;}
.sg-composer .hint{font-size:12.5px;color:var(--ink-soft);margin-bottom:6px;}
.sg-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;margin-bottom:14px;scroll-margin-top:20px;}
.sg-top{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sg-top .who{flex:1;min-width:0;font-size:13px;}
.sg-top .who strong{display:block;}
.sg-top .who span{font-size:11.5px;color:var(--ink-soft);}
.sg-tag{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:3px 10px;border-radius:999px;background:var(--brand-light);color:var(--brand-dark);}
.sg-tag.t-Bug{background:var(--danger-bg);color:#8C331F;}
.sg-tag.t-Improvement{background:var(--gold-light);color:#7A5308;}
.sg-tag.t-Other{background:var(--line);color:var(--ink-soft);}
.sg-title{font-family:'Fraunces',serif;font-size:17px;font-weight:600;}
.sg-body{font-size:14px;line-height:1.65;color:var(--ink-soft);margin-top:6px;white-space:pre-line;word-break:break-word;}
.sg-actions{display:flex;gap:8px;margin-top:12px;}
.sg-like{border:1.5px solid var(--line-strong);background:var(--cream);color:var(--ink);border-radius:999px;padding:6px 14px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s ease;}
.sg-like:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.sg-like.on{border-color:var(--brand);background:var(--brand-light);color:var(--brand-dark);}
.sg-comments{margin-top:14px;padding-top:12px;border-top:1px solid var(--line);}
.sg-comments summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--brand);list-style:none;}
.sg-comments summary::-webkit-details-marker{display:none;}
.sg-comment{display:flex;gap:8px;align-items:flex-start;background:var(--cream);border-radius:11px;padding:9px 12px;margin-top:8px;font-size:13px;line-height:1.5;}
.sg-comment .txt{flex:1;min-width:0;word-break:break-word;white-space:pre-line;}
.sg-comment .tm{font-size:11px;color:var(--ink-faint);margin-left:6px;}
.sg-x{background:none;border:none;cursor:pointer;color:var(--ink-faint);font-size:12px;padding:2px 4px;}
.sg-x:hover{color:var(--danger);}
.sg-cform{display:flex;gap:8px;margin-top:10px;}
.sg-cform input[type=text]{flex:1;border:1.5px solid var(--line-strong);border-radius:999px;padding:9px 14px;background:var(--cream);font-family:inherit;font-size:13px;}
.sg-cform input[type=text]:focus{outline:none;border-color:var(--brand);}
</style>

<div class="sg-wrap">
  <div class="row-head"><h2>Suggestions &amp; Feedback</h2></div>
  <p style="color:var(--ink-soft);font-size:13px;margin:-6px 0 18px;">Got an idea or found a problem? Share it here — other members can like and comment, and our team reads everything.</p>

  <form method="post" class="sg-composer"><?= csrf_field() ?>
    <input type="hidden" name="action" value="sg_add">
    <h3>Share a suggestion</h3>
    <div class="hint">Keep it respectful and specific — what would make SafeTrade better?</div>
    <div class="field-row">
      <div class="field" style="flex:0 0 190px;"><label>Type</label>
        <select name="category"><?php foreach (SG_CATEGORIES as $cat): ?><option><?= e($cat) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Title</label><input type="text" name="title" maxlength="150" required placeholder="e.g. Let sellers mark items as reserved"></div>
    </div>
    <div class="field"><label>Details</label><textarea name="body" rows="4" maxlength="2000" required placeholder="Tell us more…"></textarea></div>
    <button class="btn" style="margin-top:14px;" type="submit">Post suggestion</button>
  </form>

  <div class="chip-row">
    <a class="chip <?= $filter === '' ? 'active' : '' ?>" href="?view=suggestions">All</a>
    <?php foreach (SG_CATEGORIES as $cat): ?>
      <a class="chip <?= $filter === $cat ? 'active' : '' ?>" href="?view=suggestions&cat=<?= urlencode($cat) ?>"><?= e($cat) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
    <p style="color:var(--ink-soft);">No suggestions yet — be the first to share one.</p>
  <?php endif; ?>

  <?php foreach ($items as $s):
    $sid   = (int) $s['id'];
    $cm    = $comments[$sid] ?? [];
    $fn    = (string) ($s['first_name'] ?? '');
    $ln    = (string) ($s['last_name'] ?? '');
    $name  = trim($fn . ' ' . $ln) ?: 'Former member';
    $liked = (int) $s['liked_by_me'] > 0;
    $likes = (int) $s['like_count'];
    $tagClass = 't-' . strtok($s['category'], ' '); ?>
    <div class="sg-card" id="sg-<?= $sid ?>">
      <div class="sg-top">
        <span class="p-avatar hp-trigger" data-uid="<?= (int) $s['user_id'] ?>"><?= e(strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1)) ?: '?') ?></span>
        <div class="who"><strong class="hp-trigger" data-uid="<?= (int) $s['user_id'] ?>"><?= e($name) ?></strong><span><?= e(time_ago($s['created_at'])) ?></span></div>
        <span class="sg-tag <?= e($tagClass) ?>"><?= e($s['category']) ?></span>
        <?php if ((int) $s['user_id'] === $uid): ?>
          <form method="post" onsubmit="return confirm('Delete this suggestion and its comments?');" style="margin:0;"><?= csrf_field() ?>
            <input type="hidden" name="action" value="sg_delete"><input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="sg-x" type="submit" title="Delete">🗑</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="sg-title"><?= e($s['title']) ?></div>
      <div class="sg-body"><?= e($s['body']) ?></div>

      <div class="sg-actions">
        <form method="post" style="margin:0;"><?= csrf_field() ?>
          <input type="hidden" name="action" value="sg_like">
          <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
          <button class="sg-like<?= $liked ? ' on' : '' ?>" type="submit"><?= $liked ? '❤️' : '🤍' ?> Like<?= $likes > 0 ? ' (' . $likes . ')' : '' ?></button>
        </form>
      </div>

      <details class="sg-comments"<?= $cm ? ' open' : '' ?>>
        <summary>💬 <?= count($cm) ?> comment<?= count($cm) === 1 ? '' : 's' ?></summary>
        <?php foreach ($cm as $m):
          $mName = trim(((string) ($m['first_name'] ?? '')) . ' ' . ((string) ($m['last_name'] ?? ''))) ?: 'Former member'; ?>
          <div class="sg-comment">
            <div class="txt"><strong><?= e($mName) ?></strong><span class="tm"><?= e(time_ago($m['created_at'])) ?></span><br><?= e($m['body']) ?></div>
            <?php if ((int) $m['user_id'] === $uid): ?>
              <form method="post" style="margin:0;"><?= csrf_field() ?>
                <input type="hidden" name="action" value="sg_delete_comment">
                <input type="hidden" name="comment_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
                <button class="sg-x" type="submit" title="Delete comment">✕</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <form method="post" class="sg-cform"><?= csrf_field() ?>
          <input type="hidden" name="action" value="sg_comment">
          <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
          <input type="text" name="body" maxlength="600" placeholder="Write a comment…" required autocomplete="off">
          <button class="btn sm" type="submit">Send</button>
        </form>
      </details>
    </div>
  <?php endforeach; ?>
</div>
    <?php
}
