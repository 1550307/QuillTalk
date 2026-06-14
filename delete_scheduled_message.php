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
$scope = (string)($data['scope'] ?? $_POST['scope'] ?? $_GET['scope'] ?? 'me');

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

// Get scheduled message and verify ownership
$getScheduled = $pdo->prepare("
    SELECT sender_id, recipient_id, message, status
    FROM scheduled_messages
    WHERE id = ?
    LIMIT 1
");
$getScheduled->execute([$scheduled_id]);
$scheduled = $getScheduled->fetch(PDO::FETCH_ASSOC);

if (!$scheduled) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Scheduled message not found']);
    exit;
}

if ((int)$scheduled['sender_id'] !== $user_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

if ($scheduled['status'] !== 'pending') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message already sent']);
    exit;
}

if ($scope === 'everyone') {
    // Delete for everyone - completely remove the scheduled message
    $delete = $pdo->prepare("DELETE FROM scheduled_messages WHERE id = ?");
    $delete->execute([$scheduled_id]);

    $historyEventType = 'scheduled_message_deleted';
} else {
    // Delete for me - just mark as cancelled (so it still sends to others)
    // For now, we'll just return success and let the client hide it
    // In a real implementation, you might want to track who has hidden it
    $historyEventType = 'scheduled_message_hidden';
}

$historyChatTarget = qt_parse_chat_target((string)($scheduled['recipient_id'] ?? ''));
$historyChatType = in_array((string)($historyChatTarget['type'] ?? ''), ['direct', 'group'], true)
    ? (string)$historyChatTarget['type']
    : null;
$historyChatId = $historyChatType !== null ? (int)($historyChatTarget['id'] ?? 0) : 0;

qt_log_history_event($pdo, [
    'actor_user_id' => $user_id,
    'chat_type' => $historyChatType,
    'chat_id' => $historyChatId > 0 ? $historyChatId : null,
    'event_type' => $historyEventType,
    'event_value' => qt_history_describe_message_body((string)($scheduled['message'] ?? '')),
]);

echo json_encode(['success' => true]);
