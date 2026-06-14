<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput === false ? '' : $rawInput, true);
if (!is_array($data)) {
    $data = [];
}

$token = trim((string)($data['token'] ?? $_POST['token'] ?? $_GET['token'] ?? ''));
$scheduled_id = (int)($data['scheduled_id'] ?? $_POST['scheduled_id'] ?? $_GET['scheduled_id'] ?? 0);

if ($token === '' || !$scheduled_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}
$user_id = (int)$session['user_id'];

// Get scheduled message
$getScheduled = $pdo->prepare("
    SELECT sender_id, recipient_id, message, status
    FROM scheduled_messages
    WHERE id = ? AND sender_id = ?
    LIMIT 1
");
$getScheduled->execute([$scheduled_id, $user_id]);
$scheduled = $getScheduled->fetch(PDO::FETCH_ASSOC);

if (!$scheduled) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Scheduled message not found']);
    exit;
}

if ($scheduled['status'] !== 'pending') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message already sent or cancelled']);
    exit;
}

// Send the message now
$insertMsg = $pdo->prepare("
    INSERT INTO messages (sender_id, recipient_id, message, created_at)
    VALUES (?, ?, ?, NOW())
");
$insertMsg->execute([$user_id, $scheduled['recipient_id'], $scheduled['message']]);
$message_id = (int)$pdo->lastInsertId();

// Update scheduled message status
$updateScheduled = $pdo->prepare("
    UPDATE scheduled_messages
    SET status = 'sent', sent_message_id = ?
    WHERE id = ?
");
$updateScheduled->execute([$message_id, $scheduled_id]);

$historyChatTarget = qt_parse_chat_target((string)($scheduled['recipient_id'] ?? ''));
$historyChatType = in_array((string)($historyChatTarget['type'] ?? ''), ['direct', 'group'], true)
    ? (string)$historyChatTarget['type']
    : null;
$historyChatId = $historyChatType !== null ? (int)($historyChatTarget['id'] ?? 0) : 0;

qt_log_history_event($pdo, [
    'actor_user_id' => $user_id,
    'chat_type' => $historyChatType,
    'chat_id' => $historyChatId > 0 ? $historyChatId : null,
    'event_type' => 'scheduled_message_sent_now',
    'event_value' => qt_history_describe_message_body((string)($scheduled['message'] ?? '')),
]);

echo json_encode([
    'success' => true,
    'message_id' => $message_id
]);
