<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/poll_auth.php';
require __DIR__ . '/includes/history_events.php';

header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method'], 405);
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$input = qt_poll_json_input() ?? [];
$pollId = (int)($input['poll_id'] ?? 0);

if (!$pollId) {
    respond(['success' => false, 'error' => 'Missing poll_id'], 400);
}

try {
    $pollStmt = $pdo->prepare("SELECT group_id, recipient_id, title FROM polls WHERE id = ? LIMIT 1");
    $pollStmt->execute([$pollId]);
    $poll = $pollStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?");
    $stmt->execute([$pollId, $userId]);

    $historyChatType = (int)($poll['group_id'] ?? 0) > 0 ? 'group' : 'direct';
    $historyChatId = $historyChatType === 'group'
        ? (int)($poll['group_id'] ?? 0)
        : (int)($poll['recipient_id'] ?? 0);
    $pollTitle = trim((string)($poll['title'] ?? 'Poll'));

    qt_log_history_event($pdo, [
        'actor_user_id' => $userId,
        'chat_type' => $historyChatType,
        'chat_id' => $historyChatId > 0 ? $historyChatId : null,
        'event_type' => 'poll_vote_cleared',
        'event_value' => $pollTitle !== '' ? $pollTitle : 'Poll',
    ]);

    respond(['success' => true]);

} catch (Exception $e) {
    error_log('Clear poll vote error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to clear vote'], 500);
}
