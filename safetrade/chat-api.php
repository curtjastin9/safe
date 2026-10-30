<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * ---------------------------------------------------------------------
 * Lightweight JSON endpoint backing the live chat UI in dashboard.php.
 *
 *   GET  chat-api.php?t=<transaction_id>&after=<last_message_id>
 *        -> { status: <tx status>, messages: [...] }   (poll for new msgs)
 *
 *   POST chat-api.php   (transaction_id, content, csrf_token)
 *        -> { status: <tx status>, message: {...} }    (send a message)
 *
 * Every request is re-scoped to (buyer_id = me OR seller_id = me), same
 * as fetch_transaction() in dashboard.php, so this can never be used to
 * read or post into someone else's conversation.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$user   = current_user();
$userId = $user['id'];
$conn   = db();

$txId = (int) ($_GET['t'] ?? ($_POST['transaction_id'] ?? 0));

$stmt = $conn->prepare('SELECT id, buyer_id, seller_id, status FROM transactions WHERE id = ? AND (buyer_id = ? OR seller_id = ?) LIMIT 1');
$stmt->bind_param('iii', $txId, $userId, $userId);
$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tx) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['error' => 'csrf']);
        exit;
    }

    $content = clean_input($_POST['content'] ?? '');
    if ($content === '') {
        http_response_code(422);
        echo json_encode(['error' => 'empty_message']);
        exit;
    }

    $badWord = chat_find_bad_word($content);
    if ($badWord !== null) {
        audit_log((int) $userId, 'user.chat.blocked_word', 'transaction', $txId, 'matched=' . $badWord);
        http_response_code(422);
        echo json_encode(['error' => 'inappropriate_language', 'message' => chat_bad_word_message()]);
        exit;
    }

    $stmt = $conn->prepare('INSERT INTO messages (transaction_id, sender_id, content) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $txId, $userId, $content);
    $stmt->execute();
    $newId = $conn->insert_id;
    $stmt->close();
    inbox_trade_event((int) $txId, 'message', ['sender' => (int) $userId]);

    echo json_encode([
        'status'  => $tx['status'],
        'message' => [
            'id'         => (int) $newId,
            'mine'       => true,
            'content'    => $content,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

/* ---- GET: poll for messages newer than ?after= ---- */
$afterId = (int) ($_GET['after'] ?? 0);

$stmt = $conn->prepare('SELECT id, sender_id, content, created_at FROM messages WHERE transaction_id = ? AND id > ? ORDER BY id ASC');
$stmt->bind_param('ii', $txId, $afterId);
$stmt->execute();
$res = $stmt->get_result();

$messages = [];
while ($row = $res->fetch_assoc()) {
    $messages[] = [
        'id'         => (int) $row['id'],
        'mine'       => (int) $row['sender_id'] === $userId,
        'content'    => $row['content'],
        'created_at' => $row['created_at'],
    ];
}
$stmt->close();

echo json_encode([
    'status'   => $tx['status'],
    'messages' => $messages,
]);
