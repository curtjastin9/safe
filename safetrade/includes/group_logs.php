<?php
declare(strict_types=1);

/**
 * Group activity log: created / joined (optionally invited by) / left / kicked (by).
 * Table is created automatically; no foreign keys, so deleting users or
 * groups can never be blocked by log rows.
 */

function group_logs_ensure(mysqli $c): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c->query("CREATE TABLE IF NOT EXISTS group_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            actor_id INT NULL,
            action VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_group (group_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[SafeTrade] group_logs_ensure failed: ' . $e->getMessage());
    }
}

/**
 * $action: created | joined | left | kicked
 * $actorId: who invited (joined) or who kicked (kicked); null otherwise.
 */
function group_log(mysqli $c, int $groupId, int $userId, string $action, ?int $actorId = null): void
{
    try {
        group_logs_ensure($c);
        $stmt = $c->prepare('INSERT INTO group_logs (group_id, user_id, actor_id, action) VALUES (?,?,?,?)');
        $stmt->bind_param('iiis', $groupId, $userId, $actorId, $action);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] group_log failed: ' . $e->getMessage());
    }
}

function group_logs_fetch(mysqli $c, int $groupId, int $limit = 30): array
{
    $rows = [];
    try {
        group_logs_ensure($c);
        $stmt = $c->prepare(
            'SELECT l.action, l.created_at, u.first_name AS uf, u.last_name AS ul, a.first_name AS af, a.last_name AS al
             FROM group_logs l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN users a ON a.id = l.actor_id
             WHERE l.group_id = ? ORDER BY l.id DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $groupId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] group_logs_fetch failed: ' . $e->getMessage());
    }
    return $rows;
}

/** Removes a member plus their own posts (and comments/likes on those posts). Returns posts removed. */
function group_remove_member(mysqli $c, int $groupId, int $userId): int
{
    $postIds = [];
    $p = $c->prepare('SELECT id FROM group_posts WHERE group_id=? AND user_id=?');
    $p->bind_param('ii', $groupId, $userId);
    $p->execute();
    $res = $p->get_result();
    while ($r = $res->fetch_assoc()) $postIds[] = (int) $r['id'];
    $p->close();

    foreach ($postIds as $pid) {
        $d1 = $c->prepare('DELETE FROM group_post_comments WHERE post_id=?');
        $d1->bind_param('i', $pid); $d1->execute(); $d1->close();
        $d2 = $c->prepare('DELETE FROM group_post_likes WHERE post_id=?');
        $d2->bind_param('i', $pid); $d2->execute(); $d2->close();
    }
    if ($postIds) {
        $d3 = $c->prepare('DELETE FROM group_posts WHERE group_id=? AND user_id=?');
        $d3->bind_param('ii', $groupId, $userId); $d3->execute(); $d3->close();
    }
    $d4 = $c->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=?');
    $d4->bind_param('ii', $groupId, $userId); $d4->execute(); $d4->close();

    return count($postIds);
}
