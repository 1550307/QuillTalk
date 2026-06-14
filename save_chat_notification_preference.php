<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

header('Content-Type: application/json');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['success' => false, 'error' => 'Invalid request body'], 400);
}

$token = trim((string)($input['token'] ?? ''));
$chatTarget = qt_parse_chat_target($input['chat_key'] ?? '');
$mode = qt_normalize_chat_notification_mode((string)($input['mode'] ?? ''));

if ($token === '' || $chatTarget['type'] === 'unknown' || $chatTarget['id'] <= 0) {
    respond(['success' => false, 'error' => 'Missing required fields'], 400);
}

$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$userId = (int)($session['user_id'] ?? 0);

if ($userId <= 0) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if ($chatTarget['type'] === 'group') {
    if (!qt_user_can_access_group($pdo, $userId, (int)$chatTarget['id'])) {
        respond(['success' => false, 'error' => 'Group chat not found'], 403);
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
        respond(['success' => false, 'error' => 'Chat not found'], 403);
    }
}

$storedMode = qt_set_chat_notification_mode(
    $pdo,
    $userId,
    (string)$chatTarget['type'],
    (int)$chatTarget['id'],
    $mode
);

respond([
    'success' => true,
    'chat_key' => (string)$chatTarget['key'],
    'mode' => $storedMode
]);
