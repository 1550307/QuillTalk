<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

header('Content-Type: application/json; charset=utf-8');

function respond_chat_nickname(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_chat_nickname(['success' => false, 'error' => 'Invalid request method'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond_chat_nickname(['success' => false, 'error' => 'Invalid request body'], 400);
}

$token = trim((string)($input['token'] ?? ''));
$chatTarget = qt_parse_chat_target($input['chat_key'] ?? '');
$nickname = qt_normalize_chat_user_nickname((string)($input['nickname'] ?? ''));

if ($token === '' || $chatTarget['type'] === 'unknown' || $chatTarget['id'] <= 0) {
    respond_chat_nickname(['success' => false, 'error' => 'Missing required fields'], 400);
}

$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$userId = (int)($session['user_id'] ?? 0);

if ($userId <= 0) {
    respond_chat_nickname(['success' => false, 'error' => 'Invalid session'], 401);
}

if ($chatTarget['type'] === 'group') {
    if (!qt_user_can_access_group($pdo, $userId, (int)$chatTarget['id'])) {
        respond_chat_nickname(['success' => false, 'error' => 'Group chat not found'], 403);
    }
} else {
    $friendStmt = $pdo->prepare("
        SELECT 1
        FROM friends
        WHERE (user_id = ? AND friend_id = ?)
           OR (user_id = ? AND friend_id = ?)
        LIMIT 1
    ");
    $friendStmt->execute([$userId, $chatTarget['id'], $chatTarget['id'], $userId]);
    if (!$friendStmt->fetchColumn()) {
        respond_chat_nickname(['success' => false, 'error' => 'Chat not found'], 403);
    }
}

$existingStmt = $pdo->prepare("
    SELECT nickname
    FROM chat_user_nicknames
    WHERE user_id = ?
      AND chat_type = ?
      AND chat_id = ?
    LIMIT 1
");
$existingStmt->execute([$userId, (string)$chatTarget['type'], (int)$chatTarget['id']]);
$previousNickname = trim((string)($existingStmt->fetchColumn() ?: ''));

$storedNickname = qt_set_chat_user_nickname(
    $pdo,
    $userId,
    (string)$chatTarget['type'],
    (int)$chatTarget['id'],
    $nickname
);

if ($storedNickname !== '' && $storedNickname !== $previousNickname) {
    try {
        qt_log_history_event($pdo, [
            'actor_user_id' => $userId,
            'chat_type' => (string)$chatTarget['type'],
            'chat_id' => (int)$chatTarget['id'],
            'event_type' => 'chat_nickname_set',
            'event_value' => $storedNickname,
        ]);
    } catch (Throwable $e) {
        error_log('[set_chat_nickname history] ' . $e->getMessage());
    }
} elseif ($storedNickname === '' && $previousNickname !== '') {
    try {
        qt_log_history_event($pdo, [
            'actor_user_id' => $userId,
            'chat_type' => (string)$chatTarget['type'],
            'chat_id' => (int)$chatTarget['id'],
            'event_type' => 'chat_nickname_removed',
            'event_value' => $previousNickname,
        ]);
    } catch (Throwable $e) {
        error_log('[set_chat_nickname history] ' . $e->getMessage());
    }
}

respond_chat_nickname([
    'success' => true,
    'chat_key' => (string)$chatTarget['key'],
    'chat_type' => (string)$chatTarget['type'],
    'chat_id' => (int)$chatTarget['id'],
    'user_id' => $userId,
    'nickname' => $storedNickname,
]);
