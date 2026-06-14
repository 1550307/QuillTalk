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
$message = (string)($data['message'] ?? $_POST['message'] ?? $_GET['message'] ?? '');
$scheduled_time = (string)($data['scheduled_time'] ?? $_POST['scheduled_time'] ?? $_GET['scheduled_time'] ?? '');

if ($token === '' || !$scheduled_id || $message === '' || $scheduled_time === '') {
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
    SELECT sender_id, recipient_id, status
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
    echo json_encode(['success' => false, 'error' => 'Message already sent or cancelled']);
    exit;
}

// Parse the scheduled time
$scheduled_timestamp = strtotime($scheduled_time);
if ($scheduled_timestamp === false) {
    $scheduled_timestamp = strtotime(str_replace('T', ' ', $scheduled_time));
}

if ($scheduled_timestamp === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid time format']);
    exit;
}

// Validate time is in the future
$now = time();
if ($scheduled_timestamp < ($now - 30)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Scheduled time must be in the future']);
    exit;
}

// Update scheduled message
$update = $pdo->prepare("
    UPDATE scheduled_messages
    SET message = ?, scheduled_time = ?
    WHERE id = ?
");
$update->execute([$message, date('Y-m-d H:i:s', $scheduled_timestamp), $scheduled_id]);

$historyChatTarget = qt_parse_chat_target((string)($scheduled['recipient_id'] ?? ''));
$historyChatType = in_array((string)($historyChatTarget['type'] ?? ''), ['direct', 'group'], true)
    ? (string)$historyChatTarget['type']
    : null;
$historyChatId = $historyChatType !== null ? (int)($historyChatTarget['id'] ?? 0) : 0;

qt_log_history_event($pdo, [
    'actor_user_id' => $user_id,
    'chat_type' => $historyChatType,
    'chat_id' => $historyChatId > 0 ? $historyChatId : null,
    'event_type' => 'scheduled_message_updated',
    'event_value' => qt_history_describe_message_body((string)$message),
]);

echo json_encode([
    'success' => true,
    'scheduled_time' => date('Y-m-d H:i:s', $scheduled_timestamp)
]);
