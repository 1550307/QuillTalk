<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

function qt_client_history_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = is_string($rawInput) && trim($rawInput) !== ''
    ? json_decode($rawInput, true)
    : null;

if (!is_array($input)) {
    qt_client_history_respond(['success' => false, 'error' => 'Invalid input'], 400);
}

$token = trim((string)($input['token'] ?? ''));
$eventType = trim((string)($input['event_type'] ?? ''));
$eventValue = array_key_exists('event_value', $input) ? (string)$input['event_value'] : null;
$chatKey = trim((string)($input['chat_key'] ?? ''));
$subjectUserId = isset($input['subject_user_id']) ? (int)$input['subject_user_id'] : 0;

if ($token === '' || $eventType === '') {
    qt_client_history_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$allowedEventTypes = [
    'message_translated',
    'voice_transcription_used',
    'voice_message_transcribed',
];
if (!in_array($eventType, $allowedEventTypes, true)) {
    qt_client_history_respond(['success' => false, 'error' => 'Unsupported event type'], 400);
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    qt_client_history_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)($session['user_id'] ?? 0);
if ($userId <= 0) {
    qt_client_history_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$chatTarget = qt_parse_chat_target($chatKey);
$chatType = in_array((string)($chatTarget['type'] ?? ''), ['direct', 'group'], true)
    ? (string)$chatTarget['type']
    : null;
$chatId = $chatType !== null && (int)($chatTarget['id'] ?? 0) > 0
    ? (int)$chatTarget['id']
    : null;

try {
    qt_log_history_event($pdo, [
        'actor_user_id' => $userId,
        'subject_user_id' => $subjectUserId > 0 ? $subjectUserId : null,
        'chat_type' => $chatType,
        'chat_id' => $chatId,
        'event_type' => $eventType,
        'event_value' => $eventValue,
    ]);
} catch (Throwable $e) {
    error_log('[log_client_history_event] ' . $e->getMessage());
    qt_client_history_respond(['success' => false, 'error' => 'Could not record history event'], 500);
}

qt_client_history_respond(['success' => true]);
