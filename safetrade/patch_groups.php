<?php
/**
 * patch_groups.php — one-shot patcher. Open it once in the browser:
 *   http://localhost/safetrade/patch_groups.php
 * It backs up dashboard.php to dashboard.php.bak, applies every edit for the
 * new group page / activity log / kick / invite links, then tells you the result.
 * DELETE THIS FILE afterwards.
 */
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/dashboard.php';
if (!is_file($file)) { exit("dashboard.php not found next to this script.\n"); }
foreach (['includes/group_logs.php', 'includes/group_view.php'] as $need) {
    if (!is_file(__DIR__ . '/' . $need)) { exit("Missing $need — copy it into includes/ first.\n"); }
}

$src = file_get_contents($file);
if (strpos($src, 'group_logs.php') !== false) { exit("Already patched — nothing to do.\n"); }

$log = [];
$ok  = true;

/** Inserts $new before (or after) the single occurrence of $anchor. */
function patch(string &$src, string $anchor, string $new, string $label, bool $before = true, array &$log = [], bool &$ok = true): void
{
    $n = substr_count($src, $anchor);
    if ($n !== 1) { $log[] = "FAILED  $label (anchor found $n times)"; $ok = false; return; }
    $src = str_replace($anchor, $before ? $new . $anchor : $anchor . $new, $src);
    $log[] = "ok      $label";
}

/* 1. load helper */
patch($src, "require_once __DIR__ . '/includes/image_helper.php';",
"\nrequire_once __DIR__ . '/includes/group_logs.php';", '1 require helper', false, $log, $ok);

/* 2. new group view */
$n = substr_count($src, "<?php elseif (\$view === 'group'):");
if ($n !== 1) { $log[] = "FAILED  2 group view (anchor found $n times)"; $ok = false; }
else {
    $src = str_replace("<?php elseif (\$view === 'group'):",
        "<?php elseif (\$view === 'group'): include __DIR__ . '/includes/group_view.php'; ?>\n    <?php elseif (\$view === 'group_old_unused'):", $src);
    $log[] = 'ok      2 group view include';
}

/* 3. log creation */
patch($src, "audit_log(\$userId, 'user.group.created', 'group', \$groupId);",
"group_log(\$conn, (int) \$groupId, \$userId, 'created');\n            ", '3 log created', true, $log, $ok);

/* 4. log join */
patch($src, "flash_set('success', 'You have joined the group!');",
"group_log(\$conn, \$groupId, \$userId, 'joined');\n                ", '4 log joined', true, $log, $ok);

/* 5. log join via code (+ invited by) */
patch($src, "audit_log(\$userId, 'user.group.joined_via_code', 'group', \$gid);",
<<<'PHPCODE'
$invitedBy = (int) ($_POST['invited_by'] ?? 0);
            if ($invitedBy === $userId || !user_is_group_member($conn, $gid, $invitedBy)) $invitedBy = null;
            group_log($conn, $gid, $userId, 'joined', $invitedBy);
            
PHPCODE
, '5 log joined via code', true, $log, $ok);

/* 6. log leave */
patch($src, "audit_log(\$userId, 'user.group.left', 'group', \$groupId, count(\$postIds) . ' post(s) removed');",
"group_log(\$conn, \$groupId, \$userId, 'left');\n                    ", '6 log left', true, $log, $ok);

/* 7. kick action */
patch($src, "case 'leave_group': {",
<<<'PHPCODE'
case 'kick_member': {
            $groupId  = (int) ($_POST['group_id'] ?? 0);
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $own = $conn->prepare('SELECT name FROM groups WHERE id=? AND owner_id=? LIMIT 1');
            $own->bind_param('ii', $groupId, $userId);
            $own->execute();
            $grp = $own->get_result()->fetch_assoc();
            $own->close();

            if ($grp && $targetId > 0 && $targetId !== $userId && user_is_group_member($conn, $groupId, $targetId)) {
                try {
                    $removed = group_remove_member($conn, $groupId, $targetId);
                    group_log($conn, $groupId, $targetId, 'kicked', $userId);
                    audit_log($userId, 'user.group.kicked', 'group', $groupId, 'user=' . $targetId . ', ' . $removed . ' post(s) removed');
                    inbox_push($targetId, 'account', 'You were removed from a group',
                        'You were removed from “' . $grp['name'] . '” by the group owner.', null, $groupId, 'group_kicked');
                    flash_set('success', 'Member removed from the group.');
                } catch (Throwable $e) {
                    error_log('[SafeTrade] kick_member failed: ' . $e->getMessage());
                    flash_set('error', 'Could not remove that member — please try again.');
                }
            } else {
                flash_set('error', 'Only the group creator can remove members.');
            }
            redirect('/dashboard.php?view=group&id=' . $groupId);
        }

        
PHPCODE
, '7 kick action', true, $log, $ok);

/* 8. clean logs on group delete */
patch($src, "audit_log(\$userId, 'user.group.deleted', 'group', \$groupId);",
"try { \$conn->query('DELETE FROM group_logs WHERE group_id=' . (int) \$groupId); } catch (Throwable \$e) {}\n                    ", '8 delete logs with group', true, $log, $ok);

/* 9. invite-link auto-open script before the final </body> */
$pos = strrpos($src, '</body>');
if ($pos === false) { $log[] = 'FAILED  9 </body> not found'; $ok = false; }
else {
    $script = <<<'HTMLCODE'
<script>
(function () {
  var p = new URLSearchParams(location.search), code = (p.get('code') || '').toUpperCase();
  var modal = document.getElementById('join-code-modal');
  if (!code || !modal || typeof previewGroup !== 'function') return;
  var inv = parseInt(p.get('inv') || '0', 10);
  if (inv > 0) {
    var h = document.createElement('input');
    h.type = 'hidden'; h.name = 'invited_by'; h.value = inv;
    document.getElementById('code-join-form').appendChild(h);
  }
  modal.style.display = 'flex';
  document.getElementById('code-input').value = code;
  previewGroup(code);
})();
</script>

HTMLCODE;
    $src = substr($src, 0, $pos) . $script . substr($src, $pos);
    $log[] = 'ok      9 invite-link script';
}

echo implode("\n", $log) . "\n\n";

if (!$ok) {
    echo "Nothing was written — dashboard.php is unchanged. Send me the FAILED lines.\n";
    exit;
}

copy($file, $file . '.bak');
file_put_contents($file, $src);
echo "Done. Backup saved as dashboard.php.bak.\nNow delete patch_groups.php.\n";
