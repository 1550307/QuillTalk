<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? '';
$recipient_id = (int)($data['recipient_id'] ?? 0);
$type = $data['type'] ?? '';
$caller_id = (int)($data['caller_id'] ?? 0);

if ($token === '' || $recipient_id <= 0 || $caller_id <= 0 || !in_array($type, ['ended', 'missed'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$sender_id = (int)$session['user_id'];

if ($caller_id !== $sender_id && $caller_id !== $recipient_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid caller']);
    exit;
}

$check = $pdo->prepare("
    SELECT 1 FROM friends
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
    LIMIT 1
");
$check->execute([$sender_id, $recipient_id, $recipient_id, $sender_id]);

if (!$check->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not friends']);
    exit;
}

$message = '__CALL_EVENT__:' . $type . '|' . $caller_id;

$insert = $pdo->prepare("
    INSERT INTO messages (sender_id, recipient_id, message, created_at)
    VALUES (?, ?, ?, NOW())
");
$insert->execute([$sender_id, $recipient_id, $message]);

$messageId = (int)$pdo->lastInsertId();

$fetch = $pdo->prepare("
    SELECT id, sender_id, recipient_id, message, created_at
    FROM messages
    WHERE id = ?
    LIMIT 1
");
$fetch->execute([$messageId]);
$loggedMessage = $fetch->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'message' => $loggedMessage
]);
