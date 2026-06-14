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
$recipient_id = (string)($data['recipient_id'] ?? $_POST['recipient_id'] ?? $_GET['recipient_id'] ?? '');
$message = (string)($data['message'] ?? $_POST['message'] ?? $_GET['message'] ?? '');
$scheduled_time = (string)($data['scheduled_time'] ?? $_POST['scheduled_time'] ?? $_GET['scheduled_time'] ?? '');

if ($token === '' || $recipient_id === '' || $message === '' || $scheduled_time === '') {
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
$sender_id = (int)$session['user_id'];

// Parse the scheduled time - handle both ISO format and datetime-local format
$scheduled_timestamp = strtotime($scheduled_time);
if ($scheduled_timestamp === false) {
    // Try replacing T with space for datetime-local format
    $scheduled_timestamp = strtotime(str_replace('T', ' ', $scheduled_time));
}

if ($scheduled_timestamp === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid time format']);
    exit;
}

// Allow scheduling for any time in the future (with 30 second buffer for clock differences)
$now = time();
if ($scheduled_timestamp < ($now - 30)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Scheduled time must be in the future',
        'debug' => [
            'scheduled_timestamp' => $scheduled_timestamp,
            'now' => $now,
            'diff' => $scheduled_timestamp - $now,
            'received_time' => $scheduled_time
        ]
    ]);
    exit;
}

// Create scheduled_messages table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS scheduled_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id INT UNSIGNED NOT NULL,
        recipient_id VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        scheduled_time DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'sent', 'cancelled') NOT NULL DEFAULT 'pending',
        sent_message_id INT UNSIGNED NULL,
        INDEX idx_sender_status (sender_id, status),
        INDEX idx_scheduled_time (scheduled_time, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Insert scheduled message
$insert = $pdo->prepare("
    INSERT INTO scheduled_messages (sender_id, recipient_id, message, scheduled_time)
    VALUES (?, ?, ?, ?)
");
$insert->execute([$sender_id, $recipient_id, $message, date('Y-m-d H:i:s', $scheduled_timestamp)]);

$scheduled_id = (int)$pdo->lastInsertId();

$historyChatTarget = qt_parse_chat_target((string)$recipient_id);
$historyChatType = in_array((string)($historyChatTarget['type'] ?? ''), ['direct', 'group'], true)
    ? (string)$historyChatTarget['type']
    : null;
$historyChatId = $historyChatType !== null ? (int)($historyChatTarget['id'] ?? 0) : 0;

qt_log_history_event($pdo, [
    'actor_user_id' => $sender_id,
    'chat_type' => $historyChatType,
    'chat_id' => $historyChatId > 0 ? $historyChatId : null,
    'event_type' => 'scheduled_message_created',
    'event_value' => qt_history_describe_message_body((string)$message),
]);

echo json_encode([
    'success' => true,
    'scheduled_id' => $scheduled_id,
    'scheduled_time' => date('Y-m-d H:i:s', $scheduled_timestamp)
]);
