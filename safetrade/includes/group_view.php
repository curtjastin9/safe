<?php
/**
 * Group page (?view=group&id=N), included from dashboard.php.
 * Runs in dashboard.php's scope: $g-related data comes from $pageData,
 * plus $conn, $userId, e(), csrf_field(), time_ago(), product_thumbs().
 */
$g = $pageData['group'] ?? null;
?>
<a class="crumb" href="?view=groups">← Browse groups</a>
<?php if (!$g): ?><p>Group not found.</p><?php else:
    $gid        = (int) $g['id'];
    $m          = $pageData['membership'] ?? null;
    $isMember   = $m && $m['status'] === 'approved';
    $isOwner    = !empty($pageData['isOwner']);
    $groupCode  = $g['join_code'] ?? null;
    $members    = [];
    $logs       = [];

    if ($isMember) {
        // Safety net: backfill a code for older groups that never got one.
        if (!$groupCode) {
            for ($i = 0; $i < 5 && !$groupCode; $i++) {
                $tryCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
                try {
                    $upd = $conn->prepare('UPDATE `groups` SET join_code=? WHERE id=? AND (join_code IS NULL OR join_code="")');
                    $upd->bind_param('si', $tryCode, $gid);
                    $upd->execute();
                    $upd->close();
                    $chk = $conn->prepare('SELECT join_code FROM `groups` WHERE id=? LIMIT 1');
                    $chk->bind_param('i', $gid);
                    $chk->execute();
                    $groupCode = $chk->get_result()->fetch_assoc()['join_code'] ?? null;
                    $chk->close();
                } catch (Throwable $e) {
                    error_log('[SafeTrade] join_code backfill: ' . $e->getMessage());
                    break;
                }
            }
        }

        $ms = $conn->prepare(
            "SELECT m.user_id, m.role, u.first_name, u.last_name
             FROM group_members m JOIN users u ON u.id = m.user_id
             WHERE m.group_id = ? AND m.status = 'approved'
             ORDER BY (m.role = 'owner') DESC, m.joined_at ASC LIMIT 100"
        );
        $ms->bind_param('i', $gid);
        $ms->execute();
        $members = $ms->get_result()->fetch_all(MYSQLI_ASSOC);
        $ms->close();

        $logs = group_logs_fetch($conn, $gid, 30);
    }
?>
<style>
.group-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:22px;align-items:start;}
.group-layout .detail-card{max-width:none;}
.group-side{display:flex;flex-direction:column;gap:16px;position:sticky;top:20px;}
.gs-card{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:16px 18px;}
.gs-title{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:11px;}
.gs-code{font-family:'IBM Plex Mono',monospace;font-size:20px;font-weight:700;letter-spacing:.16em;color:var(--brand-dark);background:var(--brand-light);padding:10px 14px;border-radius:10px;border:1.5px dashed var(--brand);user-select:all;cursor:pointer;text-align:center;}
.gs-btn-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.gs-member{display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid var(--line);font-size:13px;}
.gs-member:last-child{border-bottom:none;}
.gs-member .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;}
.gs-scroll{max-height:240px;overflow-y:auto;}
.gs-log{display:flex;gap:9px;padding:8px 0;border-bottom:1px solid var(--line);font-size:12.5px;line-height:1.45;}
.gs-log:last-child{border-bottom:none;}
.gs-log .ic{flex-shrink:0;width:24px;text-align:center;}
.gs-log .tm{display:block;font-size:11px;color:var(--ink-faint);margin-top:1px;}
.gs-empty{font-size:12.5px;color:var(--ink-soft);}
@media(max-width:1100px){.group-layout{grid-template-columns:1fr;}.group-side{position:static;}}
@keyframes joinModalIn{from{opacity:0;transform:scale(.94) translateY(8px);}to{opacity:1;transform:none;}}
</style>

<div class="group-layout">

  <!-- ===================== LEFT: group + feed ===================== -->
  <div>
    <div class="detail-card">
      <?php if (!empty($g['photo_mime'])): ?>
        <div style="border-radius:var(--radius);overflow:hidden;max-width:420px;margin-bottom:6px;">
          <img src="image.php?type=group&id=<?= $gid ?>" alt="" style="width:100%;height:200px;object-fit:cover;display:block;">
        </div>
      <?php else: ?>
        <div class="detail-hero"><?= e($g['icon']) ?></div>
      <?php endif; ?>
      <h2><?= e($g['name']) ?></h2>
      <?php if (!empty($g['category'])): ?><div class="sub" style="color:var(--ink-soft);margin-top:6px;"><?= e($g['category']) ?></div><?php endif; ?>
      <p style="margin-top:14px;color:var(--ink-soft);line-height:1.6;"><?= nl2br(e($g['description'])) ?></p>

      <?php if (!$m): ?>
        <button type="button" class="btn" style="margin-top:16px;" onclick="openJoinModal(<?= $gid ?>, '<?= e(addslashes($g['name'])) ?>')">Join Group</button>
      <?php elseif ($isMember): ?>
        <div class="status-banner">✅ You're a member of this group.</div>
      <?php elseif ($m['status'] === 'rejected'): ?>
        <div class="status-banner" style="background:var(--danger-bg);color:var(--danger);">Your previous request was declined. You may request to join again.</div>
        <button type="button" class="btn" style="margin-top:12px;" onclick="openJoinModal(<?= $gid ?>, '<?= e(addslashes($g['name'])) ?>')">Join Group</button>
      <?php endif; ?>

      <?php if (!$isMember): ?>
        <form method="post" id="join-form-<?= $gid ?>" style="display:none;"><?= csrf_field() ?>
          <input type="hidden" name="action" value="join_group">
          <input type="hidden" name="group_id" value="<?= $gid ?>">
        </form>
        <div id="join-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;">
          <div style="background:var(--cream);border-radius:22px;padding:34px 30px;max-width:400px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);animation:joinModalIn .22s cubic-bezier(.22,.75,.28,1) both;">
            <div style="width:52px;height:52px;border-radius:50%;background:var(--brand-light);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">👥</div>
            <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 8px;">Join this group?</h3>
            <p id="join-modal-name" style="text-align:center;font-weight:700;font-size:15px;color:var(--brand);margin:0 0 16px;"></p>
            <p style="text-align:center;font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 24px;">You will immediately become a member and can post, sell items, and chat in this group.</p>
            <div style="display:flex;gap:10px;">
              <button type="button" onclick="closeJoinModal()" style="flex:1;padding:13px;border-radius:999px;font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);background:transparent;color:var(--ink-soft);cursor:pointer;">Cancel</button>
              <button type="button" onclick="confirmJoin()" style="flex:1;padding:13px;border-radius:999px;font-weight:700;font-size:14px;border:none;background:var(--brand);color:var(--cream);cursor:pointer;">Yes, join now</button>
            </div>
          </div>
        </div>
        <script>
        var _joinGroupId = null;
        function openJoinModal(id, name) {
          _joinGroupId = id;
          document.getElementById('join-modal-name').textContent = '"' + name + '"';
          document.getElementById('join-modal').style.display = 'flex';
        }
        function closeJoinModal() { document.getElementById('join-modal').style.display = 'none'; _joinGroupId = null; }
        function confirmJoin() { if (_joinGroupId) document.getElementById('join-form-' + _joinGroupId).submit(); }
        document.getElementById('join-modal').addEventListener('click', function (e) { if (e.target === this) closeJoinModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeJoinModal(); });
        </script>
      <?php endif; ?>
    </div>

    <?php if ($isMember): ?>
      <div class="group-feed-composer">
        <form method="post"><?= csrf_field() ?>
          <input type="hidden" name="action" value="create_group_post">
          <input type="hidden" name="group_id" value="<?= $gid ?>">
          <textarea name="content" rows="2" placeholder="Share something with the group…" required></textarea>
          <div class="group-feed-actions">
            <button class="btn sm" type="submit">Post</button>
            <a class="btn sm ghost" href="?view=sell&type=item&group_id=<?= $gid ?>">📦 Sell an item here</a>
            <a class="btn sm ghost" href="?view=sell&type=vehicle&group_id=<?= $gid ?>">🚗 Sell a vehicle here</a>
          </div>
        </form>
      </div>

      <?php if (!$pageData['feedAvailable']): ?>
        <div class="alert error">The group feed isn't available yet — ask an admin to run <code>migration_add_group_feed.sql</code>.</div>
      <?php elseif (empty($pageData['posts'])): ?>
        <p style="color:var(--ink-soft);">No posts yet — be the first to share something with the group.</p>
      <?php else: ?>
        <div class="group-feed">
          <?php foreach ($pageData['posts'] as $post):
            $postThumbs = product_thumbs($post['images_concat'] ?? null); ?>
            <div class="group-post" id="post-<?= (int)$post['id'] ?>">
              <div class="group-post-header">
                <span class="p-avatar hp-trigger" data-uid="<?= (int)$post['user_id'] ?>"><?= e(strtoupper(substr($post['first_name'], 0, 1) . substr($post['last_name'], 0, 1))) ?></span>
                <div>
                  <span class="hp-trigger" data-uid="<?= (int)$post['user_id'] ?>" style="font-weight:700;"><?= e($post['first_name'] . ' ' . $post['last_name']) ?></span>
                  <div class="sub" style="font-size:11.5px;color:var(--ink-soft);"><?= e(time_ago($post['created_at'])) ?></div>
                </div>
              </div>

              <?php if (!empty($post['content'])): ?>
                <p class="group-post-content"><?= nl2br(e($post['content'])) ?></p>
              <?php endif; ?>

              <?php if (!empty($post['product_id'])): ?>
                <a class="group-post-product" href="?view=item&id=<?= (int)$post['product_id'] ?>">
                  <?php if ($postThumbs): ?>
                    <img src="<?= e($postThumbs[0]) ?>" alt="">
                  <?php else: ?>
                    <div class="group-post-product-fallback"><?= e($post['product_icon'] ?? '📦') ?></div>
                  <?php endif; ?>
                  <div>
                    <div class="price">₱<?= number_format((float)($post['product_price'] ?? 0), 2) ?></div>
                    <div style="font-size:13px;font-weight:600;"><?= e($post['product_title'] ?? '') ?></div>
                    <?php if (empty($post['product_active'])): ?><span class="pill" style="background:var(--danger-bg);color:var(--danger);">Sold / unavailable</span><?php endif; ?>
                  </div>
                </a>
                <?php if ((int)$post['user_id'] !== $userId && !empty($post['product_active'])): ?>
                  <form method="post" style="margin-bottom:10px;"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="express_interest">
                    <input type="hidden" name="product_id" value="<?= (int)$post['product_id'] ?>">
                    <button class="chat-pill" type="submit">💬 Chat with seller — Buying this</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <div class="group-post-actions">
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_group_post_like">
                  <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                  <input type="hidden" name="group_id" value="<?= $gid ?>">
                  <button class="btn sm ghost" type="submit"><?= !empty($post['liked_by_me']) ? '❤️' : '🤍' ?> Like<?= (int)$post['like_count'] > 0 ? ' (' . (int)$post['like_count'] . ')' : '' ?></button>
                </form>
              </div>

              <div class="group-post-comments">
                <?php foreach ($post['comments'] as $c): ?>
                  <div class="group-post-comment"><strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?>:</strong> <?= nl2br(e($c['content'])) ?></div>
                <?php endforeach; ?>
                <form method="post" class="group-post-comment-form"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="comment_group_post">
                  <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                  <input type="hidden" name="group_id" value="<?= $gid ?>">
                  <input type="text" name="content" placeholder="Write a comment… (this messages the poster)" required>
                  <button class="btn sm" type="submit">Send</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ===================== RIGHT: invite, members, activity, actions ===================== -->
  <?php if ($isMember): ?>
  <aside class="group-side">

    <div class="gs-card">
      <div class="gs-title">🔑 Invite people to this group</div>
      <?php if ($groupCode): ?>
        <div class="gs-code" id="gcode-display" title="Click to copy the code" onclick="copyGroupText('<?= e($groupCode) ?>', 'Code copied!')"><?= e($groupCode) ?></div>
        <div class="gs-btn-row">
          <button type="button" class="btn sm ghost" onclick="copyGroupText('<?= e($groupCode) ?>', 'Code copied!')">📋 Copy code</button>
          <button type="button" class="btn sm ghost" onclick="copyGroupText(location.origin + location.pathname + '?view=groups&code=<?= e($groupCode) ?>&inv=<?= (int) $userId ?>', 'Invite link copied!')">🔗 Copy invite link</button>
          <form method="post" style="margin:0;"><?= csrf_field() ?>
            <input type="hidden" name="action" value="regenerate_code">
            <input type="hidden" name="group_id" value="<?= $gid ?>">
            <button class="btn sm ghost" type="submit" onclick="return confirm('Generate a new code? The old code will stop working.')">🔄 New code</button>
          </form>
        </div>
        <div id="gcode-copied" style="display:none;font-size:12px;color:var(--brand);margin-top:8px;font-weight:600;"></div>
        <div style="font-size:12px;color:var(--ink-soft);margin-top:10px;line-height:1.5;">
          Share the code, or your invite link — people who join through your link are logged as <strong>invited by you</strong>. No approval needed.
        </div>
      <?php else: ?>
        <div class="gs-empty">No invite code available yet.</div>
      <?php endif; ?>
    </div>

    <div class="gs-card">
      <div class="gs-title">👥 Members (<?= count($members) ?>)</div>
      <div class="gs-scroll">
        <?php foreach ($members as $mem):
          $mName = trim($mem['first_name'] . ' ' . $mem['last_name']);
          $canKick = $isOwner && (int) $mem['user_id'] !== $userId; ?>
          <div class="gs-member">
            <span class="p-avatar"><?= e(strtoupper(substr($mem['first_name'], 0, 1) . substr($mem['last_name'], 0, 1))) ?></span>
            <a class="nm hp-trigger" data-uid="<?= (int) $mem['user_id'] ?>" href="?view=profile&id=<?= (int) $mem['user_id'] ?>"><?= e($mName) ?></a>
            <?php if ($mem['role'] === 'owner'): ?><span class="pill ok">Owner</span><?php endif; ?>
            <?php if ($canKick): ?>
              <form method="post" style="margin:0;" data-msg="<?= e('Remove ' . $mName . ' from this group? Their posts in it will be removed too.') ?>" onsubmit="return confirm(this.dataset.msg);"><?= csrf_field() ?>
                <input type="hidden" name="action" value="kick_member">
                <input type="hidden" name="group_id" value="<?= $gid ?>">
                <input type="hidden" name="user_id" value="<?= (int) $mem['user_id'] ?>">
                <button type="submit" class="btn sm ghost" style="color:var(--danger);padding:4px 10px;">Kick</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="gs-card">
      <div class="gs-title">📜 Activity log</div>
      <?php if (!$logs): ?>
        <div class="gs-empty">No activity recorded yet. Joins, leaves and removals will show up here.</div>
      <?php else: ?>
        <div class="gs-scroll" style="max-height:320px;">
          <?php foreach ($logs as $l):
            $who = trim(($l['uf'] ?? '') . ' ' . ($l['ul'] ?? '')) ?: 'Former member';
            $by  = trim(($l['af'] ?? '') . ' ' . ($l['al'] ?? ''));
            switch ($l['action']) {
                case 'created': $ic = '👑'; $txt = '<strong>' . e($who) . '</strong> created the group'; break;
                case 'joined':  $ic = '➕'; $txt = '<strong>' . e($who) . '</strong> joined' . ($by !== '' ? ' · invited by <strong>' . e($by) . '</strong>' : ''); break;
                case 'left':    $ic = '🚪'; $txt = '<strong>' . e($who) . '</strong> left the group'; break;
                case 'kicked':  $ic = '⛔'; $txt = '<strong>' . e($who) . '</strong> was kicked' . ($by !== '' ? ' by <strong>' . e($by) . '</strong>' : ''); break;
                default:        $ic = '•';  $txt = '<strong>' . e($who) . '</strong> · ' . e($l['action']);
            } ?>
            <div class="gs-log">
              <span class="ic"><?= $ic ?></span>
              <div><?= $txt ?><span class="tm"><?= e(time_ago($l['created_at'])) ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="gs-card">
      <?php if ($isOwner): ?>
        <button type="button" class="btn sm ghost" style="color:var(--danger);border-color:var(--clay);" onclick="document.getElementById('delete-group-modal').style.display='flex';">🗑 Delete this group</button>
      <?php else: ?>
        <button type="button" class="btn sm ghost" style="color:var(--danger);border-color:var(--clay);" onclick="document.getElementById('leave-group-modal').style.display='flex';">🚪 Leave this group</button>
      <?php endif; ?>
    </div>
  </aside>

  <?php if ($isOwner): ?>
  <div id="delete-group-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--cream);border-radius:22px;padding:34px 30px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);">
      <div style="width:52px;height:52px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">🗑</div>
      <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 8px;">Delete "<?= e($g['name']) ?>"?</h3>
      <p style="text-align:center;font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 22px;">
        This permanently deletes the group, its feed posts, comments, likes and member list. Listings posted into it stay live, just unlinked. The invite code <strong><?= e((string) $groupCode) ?></strong> is freed up. This can't be undone.
      </p>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="document.getElementById('delete-group-modal').style.display='none';" style="flex:1;padding:13px;border-radius:999px;font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);background:transparent;color:var(--ink-soft);cursor:pointer;">Cancel</button>
        <form method="post" style="flex:1;margin:0;"><?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_group_by_owner">
          <input type="hidden" name="group_id" value="<?= $gid ?>">
          <button type="submit" style="width:100%;padding:13px;border-radius:999px;font-weight:700;font-size:14px;border:none;background:var(--danger);color:#fff;cursor:pointer;">Yes, delete it</button>
        </form>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div id="leave-group-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--cream);border-radius:22px;padding:34px 30px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);">
      <div style="width:52px;height:52px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">🚪</div>
      <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 8px;">Leave "<?= e($g['name']) ?>"?</h3>
      <p style="text-align:center;font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 22px;">
        Any posts you shared in its feed will be removed along with you. You can rejoin later with the group's code.
      </p>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="document.getElementById('leave-group-modal').style.display='none';" style="flex:1;padding:13px;border-radius:999px;font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);background:transparent;color:var(--ink-soft);cursor:pointer;">Cancel</button>
        <form method="post" style="flex:1;margin:0;"><?= csrf_field() ?>
          <input type="hidden" name="action" value="leave_group">
          <input type="hidden" name="group_id" value="<?= $gid ?>">
          <button type="submit" style="width:100%;padding:13px;border-radius:999px;font-weight:700;font-size:14px;border:none;background:var(--danger);color:#fff;cursor:pointer;">Yes, leave</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
  function copyGroupText(text, msg) {
    var done = function () {
      var el = document.getElementById('gcode-copied');
      if (el) { el.textContent = '✓ ' + msg; el.style.display = 'block'; setTimeout(function () { el.style.display = 'none'; }, 2500); }
    };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta);
      done();
    }
  }
  </script>
  <?php endif; ?>

</div>
<?php endif; ?>
