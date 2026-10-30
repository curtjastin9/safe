<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * inbox-api.php — JSON endpoint behind the Inbox page and sidebar badge.
 *
 *   GET  ?action=list&filter=all|unread|trade|announcement|update|maintenance|account&page=1
 *   GET  ?action=count
 *   GET  ?action=wait&latest=<id>&unread=<n>   (long poll, up to ~25s)
 *   POST action=read|unread|delete (id) · read_all · clear_read  (+ csrf_token)
 *
 * Every query is scoped to the logged-in user's own user_id, so nobody
 * can ever read or change someone else's inbox.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$user = current_user();
$uid  = (int) $user['id'];
inbox_ensure();
$conn = db();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $isPost ? (string) ($_POST['action'] ?? '') : (string) ($_GET['action'] ?? 'list');

if ($isPost && !verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

function inbox_api_reply(int $uid, array $extra = []): never
{
    $s = inbox_summary($uid);
    echo json_encode(array_merge(['ok' => true, 'unread' => $s['unread'], 'latest_id' => $s['latest_id'], 'cats' => $s['cats']], $extra));
    exit;
}

try {
    if (!$isPost) {
        if ($action === 'count') {
            inbox_api_reply($uid);
        }

        if ($action === 'wait') {
            // Long poll: hold the request until something changes (or ~25s pass).
            // Release the session lock first, otherwise every other page
            // request from this user would queue behind this one.
            session_write_close();
            set_time_limit(40);
            $sinceLatest = (int) ($_GET['latest'] ?? -1);
            $sinceUnread = (int) ($_GET['unread'] ?? -1);
            $deadline    = time() + 25;
            do {
                $s = inbox_summary($uid);
                if ($s['latest_id'] !== $sinceLatest || $s['unread'] !== $sinceUnread) {
                    break;
                }
                sleep(2);
            } while (time() < $deadline && !connection_aborted());
            inbox_api_reply($uid);
        }

        $filter  = (string) ($_GET['filter'] ?? 'all');
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $limit   = 25;
        $offset  = ($page - 1) * $limit;
        $cats    = ['trade', 'announcement', 'update', 'maintenance', 'account'];

        $where  = 'user_id = ?';
        $types  = 'i';
        $params = [$uid];
        if ($filter === 'unread') {
            $where .= ' AND is_read = 0';
        } elseif (in_array($filter, $cats, true)) {
            $where .= ' AND category = ?';
            $types .= 's';
            $params[] = $filter;
        }

        $fetch = $limit + 1;
        $stmt = $conn->prepare(
            "SELECT id, category, type, title, body, link, ref_id, is_read, is_pinned, created_at,
                    TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s
             FROM inbox_messages WHERE $where
             ORDER BY is_pinned DESC, created_at DESC, id DESC LIMIT ? OFFSET ?"
        );
        $types .= 'ii';
        $params[] = $fetch;
        $params[] = $offset;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $base  = rtrim(BASE_URL, '/');
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $link = (!empty($r['link']) && $r['link'][0] === '/') ? $base . $r['link'] : null;
            $items[] = [
                'id'        => (int) $r['id'],
                'category'  => $r['category'],
                'type'      => $r['type'],
                'title'     => $r['title'],
                'body'      => $r['body'] ?? '',
                'link'      => $link,
                'ref_id'    => $r['ref_id'] !== null ? (int) $r['ref_id'] : null,
                'is_read'   => (int) $r['is_read'],
                'is_pinned' => (int) $r['is_pinned'],
                'created_at'=> $r['created_at'],
                'age_s'     => max(0, (int) $r['age_s']),
            ];
        }
        $stmt->close();

        $hasMore = count($items) > $limit;
        if ($hasMore) array_pop($items);

        inbox_api_reply($uid, ['items' => $items, 'has_more' => $hasMore]);
    }

    $id = (int) ($_POST['id'] ?? 0);

    switch ($action) {
        case 'read':
        case 'unread':
            $v = $action === 'read' ? 1 : 0;
            $stmt = $conn->prepare('UPDATE inbox_messages SET is_read = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('iii', $v, $id, $uid);
            $stmt->execute();
            $stmt->close();
            break;

        case 'read_all':
            $stmt = $conn->prepare('UPDATE inbox_messages SET is_read = 1 WHERE user_id = ? AND is_read = 0');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
            break;

        case 'delete':
            $stmt = $conn->prepare('DELETE FROM inbox_messages WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $uid);
            $stmt->execute();
            $stmt->close();
            break;

        case 'clear_read':
            $stmt = $conn->prepare('DELETE FROM inbox_messages WHERE user_id = ? AND is_read = 1 AND is_pinned = 0');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown_action']);
            exit;
    }

    inbox_api_reply($uid);
} catch (Throwable $e) {
    error_log('[SafeTrade] inbox-api failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
