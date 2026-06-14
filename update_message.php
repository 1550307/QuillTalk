<?php
// ================= HARD SAFETY =================
declare(strict_types=1);
ob_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/push_debug.log');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/history_events.php';

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_POST['token'] ?? '';
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$newMessage = trim((string)($_POST['message'] ?? ''));

error_log('[UPDATE MESSAGE] token=' . substr($token, 0, 10) . ', messageId=' . $messageId . ', newMessage length=' . strlen($newMessage));

if ($token === '' || $messageId <= 0 || $newMessage === '') {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

// Try direct messages first
$check = $pdo->prepare("SELECT id, sender_id, recipient_id, message FROM messages WHERE id = ? LIMIT 1");
$check->execute([$messageId]);
$row = $check->fetch(PDO::FETCH_ASSOC);
$table = '';

if ($row) {
    $table = 'messages';
} else {
    $gcheck = $pdo->prepare("SELECT id, sender_id, group_id, message FROM group_messages WHERE id = ? LIMIT 1");
    $gcheck->execute([$messageId]);
    $grow = $gcheck->fetch(PDO::FETCH_ASSOC);
    if ($grow) {
        $table = 'group_messages';
        $row = $grow;
    } else {
        // Check AI messages
        $aicheck = $pdo->prepare("SELECT id, user_id, sender_type, message FROM ai_chat_messages WHERE id = ? LIMIT 1");
        $aicheck->execute([$messageId]);
        $airow = $aicheck->fetch(PDO::FETCH_ASSOC);
        if ($airow) {
            $table = 'ai_chat_messages';
            $row = $airow;
            $row['sender_id'] = (($row['sender_type'] ?? '') === 'user')
                ? (int)$row['user_id']
                : 0;
        }
    }
}

if ($table === '') {
    error_log('[UPDATE MESSAGE] Message not found in any table: messageId=' . $messageId);
    respond(['success' => false, 'error' => 'Message not found'], 404);
}

error_log('[UPDATE MESSAGE] Found message in table: ' . $table . ', sender_id=' . ($row['sender_id'] ?? 'null') . ', current_user=' . $userId);

// Messages can only be edited by the original sender.
$canEdit = isset($row['sender_id']) && (int)$row['sender_id'] === $userId;

if (!$canEdit) {
    error_log('[UPDATE MESSAGE] Not authorized: canEdit=false, table=' . $table . ', sender_id=' . ($row['sender_id'] ?? 'null') . ', user_id=' . $userId);
    respond(['success' => false, 'error' => 'Not authorized'], 403);
}

error_log('[UPDATE MESSAGE] Authorization passed, updating message in table: ' . $table);

$senderIdInt = isset($row['sender_id']) ? (int)$row['sender_id'] : 0;

// Do not allow editing attachments (server-side guard)
if (is_string($row['message']) && str_starts_with($row['message'], '__ATTACHMENT__:')) {
    respond(['success' => false, 'error' => 'Cannot edit attachments'], 400);
}

try {
    if ($table === 'messages') {
        $up = $pdo->prepare("UPDATE messages SET message = ? WHERE id = ?");
        $up->execute([$newMessage, $messageId]);
    } elseif ($table === 'group_messages') {
        $up = $pdo->prepare("UPDATE group_messages SET message = ? WHERE id = ?");
        $up->execute([$newMessage, $messageId]);
    } else { // ai_chat_messages
        $up = $pdo->prepare("UPDATE ai_chat_messages SET message = ? WHERE id = ?");
        $up->execute([$newMessage, $messageId]);
    }

    $historyChatType = null;
    $historyChatId = null;
    if ($table === 'messages') {
        $historyChatType = 'direct';
        $historyChatId = $senderIdInt === $userId
            ? (int)($row['recipient_id'] ?? 0)
            : $senderIdInt;
    } elseif ($table === 'group_messages') {
        $historyChatType = 'group';
        $historyChatId = (int)($row['group_id'] ?? 0);
    }

    qt_log_history_event($pdo, [
        'actor_user_id' => $userId,
        'subject_user_id' => $senderIdInt !== $userId ? $senderIdInt : null,
        'chat_type' => $historyChatType,
        'chat_id' => $historyChatId > 0 ? $historyChatId : null,
        'event_type' => 'message_edited',
        'event_value' => qt_history_describe_message_body($newMessage),
    ]);

    respond(['success' => true]);
} catch (Throwable $e) {
    error_log('[update_message] ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error'], 500);
}
