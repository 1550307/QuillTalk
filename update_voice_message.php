<?php
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

if ($token === '' || $messageId <= 0) {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
    respond(['success' => false, 'error' => 'No attachment provided'], 400);
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
    }
}

if ($table === '') {
    respond(['success' => false, 'error' => 'Message not found'], 404);
}

// Only sender can edit
if (!isset($row['sender_id']) || (int)$row['sender_id'] !== $userId) {
    respond(['success' => false, 'error' => 'Not authorized'], 403);
}

// Verify it's an attachment message
if (!is_string($row['message']) || !str_starts_with($row['message'], '__ATTACHMENT__:')) {
    respond(['success' => false, 'error' => 'Not an attachment message'], 400);
}

// Parse the old attachment to get the old file path
$oldAttachmentData = json_decode(substr($row['message'], strlen('__ATTACHMENT__:')), true);
$oldFilePath = $oldAttachmentData['url'] ?? '';

// Handle the new attachment upload
$uploadDir = __DIR__ . '/uploads/chat/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$file = $_FILES['attachment'];
$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExts = ['webm', 'ogg', 'mp3', 'wav', 'm4a'];

if (!in_array($fileExt, $allowedExts, true)) {
    respond(['success' => false, 'error' => 'Invalid file type'], 400);
}

$newFileName = md5(uniqid((string)mt_rand(), true)) . '.' . $fileExt;
$newFilePath = $uploadDir . $newFileName;

if (!move_uploaded_file($file['tmp_name'], $newFilePath)) {
    respond(['success' => false, 'error' => 'Failed to save file'], 500);
}

// Get waveform and duration from POST
$waveform = isset($_POST['attachment_waveform']) ? json_decode($_POST['attachment_waveform'], true) : [];
$duration = isset($_POST['attachment_duration']) ? (float)$_POST['attachment_duration'] : 0;

if (!is_array($waveform)) {
    $waveform = [];
}

// Build new attachment message
$newAttachment = [
    'kind' => 'audio',
    'url' => 'uploads/chat/' . $newFileName,
    'name' => $file['name'],
    'size' => $file['size'],
    'mime' => $file['type'],
    'waveform' => $waveform,
    'duration' => $duration
];

$newMessage = '__ATTACHMENT__:' . json_encode($newAttachment, JSON_UNESCAPED_UNICODE);

try {
    if ($table === 'messages') {
        $up = $pdo->prepare("UPDATE messages SET message = ? WHERE id = ?");
        $up->execute([$newMessage, $messageId]);
    } else {
        $up = $pdo->prepare("UPDATE group_messages SET message = ? WHERE id = ?");
        $up->execute([$newMessage, $messageId]);
    }

    $historyChatType = $table === 'messages' ? 'direct' : 'group';
    $historyChatId = $table === 'messages'
        ? (int)($row['recipient_id'] ?? 0)
        : (int)($row['group_id'] ?? 0);

    qt_log_history_event($pdo, [
        'actor_user_id' => $userId,
        'chat_type' => $historyChatType,
        'chat_id' => $historyChatId > 0 ? $historyChatId : null,
        'event_type' => 'voice_message_rerecorded',
        'event_value' => 'Voice message',
    ]);
    
    // Delete old file if it exists
    if ($oldFilePath && file_exists(__DIR__ . '/' . $oldFilePath)) {
        @unlink(__DIR__ . '/' . $oldFilePath);
    }
    
    respond(['success' => true, 'message' => ['id' => $messageId, 'message' => $newMessage]]);
} catch (Throwable $e) {
    error_log('[update_voice_message] ' . $e->getMessage());
    // Clean up the new file if database update failed
    if (file_exists($newFilePath)) {
        @unlink($newFilePath);
    }
    respond(['success' => false, 'error' => 'Database error'], 500);
}
