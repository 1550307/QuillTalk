<?php
declare(strict_types=1);
ob_start();
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/ai_debug.log');
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_client_message_id(int $messageId): string
{
    return 'ai-msg-' . max(0, $messageId);
}

function ensure_ai_chat_message_reply_schema(PDO $pdo): void
{
    $tableStmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_chat_messages'
    ");
    $tableStmt->execute();
    if ((int)$tableStmt->fetchColumn() <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_chat_messages'
          AND COLUMN_NAME = 'reply_to_id'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE ai_chat_messages ADD COLUMN reply_to_id INT UNSIGNED NULL DEFAULT NULL AFTER message");
}

// Get POST data
$token = trim((string)($_POST['token'] ?? ''));
$aiChatKey = trim((string)($_POST['ai_chat_key'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$senderType = trim((string)($_POST['sender_type'] ?? 'user')); // 'user' or 'ai'
$replyToId = max(0, (int)($_POST['reply_to_id'] ?? 0));

if ($token === '' || $aiChatKey === '' || $message === '') {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

// Extract AI chat ID from key (format: ai:123)
if (!str_starts_with($aiChatKey, 'ai:')) {
    respond(['success' => false, 'error' => 'Invalid AI chat key'], 400);
}

$aiChatId = (int)substr($aiChatKey, 3);
if ($aiChatId <= 0) {
    respond(['success' => false, 'error' => 'Invalid AI chat ID'], 400);
}

// Validate session
$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

// Verify AI chat ownership
$checkStmt = $pdo->prepare('SELECT id, display_name, profile_pic FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1');
$checkStmt->execute([$aiChatId, $userId]);
$aiChat = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$aiChat) {
    respond(['success' => false, 'error' => 'AI chat not found or access denied'], 403);
}

// Store the message
try {
    ensure_ai_chat_message_reply_schema($pdo);

    if ($replyToId > 0) {
        $replyCheckStmt = $pdo->prepare('SELECT id FROM ai_chat_messages WHERE id = ? AND ai_chat_id = ? LIMIT 1');
        $replyCheckStmt->execute([$replyToId, $aiChatId]);
        if (!$replyCheckStmt->fetchColumn()) {
            $replyToId = 0;
        }
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO ai_chat_messages (ai_chat_id, user_id, sender_type, message, reply_to_id, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    // Try to insert, if table doesn't exist, create it
    try {
        $insertStmt->execute([$aiChatId, $userId, $senderType, $message, $replyToId > 0 ? $replyToId : null]);
    } catch (PDOException $e) {
        // Create table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ai_chat_id INT NOT NULL,
                user_id INT NOT NULL,
                sender_type ENUM('user', 'ai') NOT NULL,
                message TEXT NOT NULL,
                reply_to_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_chat (ai_chat_id),
                INDEX idx_user (user_id),
                FOREIGN KEY (ai_chat_id) REFERENCES ai_chats(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        ensure_ai_chat_message_reply_schema($pdo);
        
        // Retry insert
        $insertStmt = $pdo->prepare("
            INSERT INTO ai_chat_messages (ai_chat_id, user_id, sender_type, message, reply_to_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([$aiChatId, $userId, $senderType, $message, $replyToId > 0 ? $replyToId : null]);
    }
    
    $messageId = (int)$pdo->lastInsertId();
    
    // Get sender info for response
    if ($senderType === 'ai') {
        // AI sender
        $senderInfo = [
            'display_name' => $aiChat['display_name'],
            'username' => '',
            'profile_pic' => $aiChat['profile_pic'] ?? 'images/default-ai.png'
        ];
    } else {
        // User sender
        $userStmt = $pdo->prepare('SELECT display_name, username, profile_pic FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $senderInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
    }

    $replyInfo = null;
    if ($replyToId > 0) {
        $replyStmt = $pdo->prepare("
            SELECT
                m.id,
                m.sender_type,
                m.message,
                u.display_name AS user_display_name,
                u.username AS user_username
            FROM ai_chat_messages m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.id = ? AND m.ai_chat_id = ?
            LIMIT 1
        ");
        $replyStmt->execute([$replyToId, $aiChatId]);
        $replyInfo = $replyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $replyDisplayName = '';
    $replyMessageBody = '';
    if ($replyInfo) {
        $replyDisplayName = $replyInfo['sender_type'] === 'ai'
            ? (string)($aiChat['display_name'] ?? 'QuillTalk AI')
            : (string)($replyInfo['user_display_name'] ?? $replyInfo['user_username'] ?? 'You');
        $replyMessageBody = (string)($replyInfo['message'] ?? '');
    }
    
    respond([
        'success' => true,
        'message' => [
            'id' => $messageId,
            'client_id' => ai_client_message_id($messageId),
            'ai_chat_id' => $aiChatId,
            'chat_key' => $aiChatKey,
            'sender_id' => $senderType === 'ai' ? $aiChatKey : $userId,
            'sender_type' => $senderType,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
            'sender_display_name' => $senderInfo['display_name'] ?? $senderInfo['username'] ?? ($senderType === 'ai' ? 'AI' : 'User'),
            'sender_profile_pic' => $senderInfo['profile_pic'] ?? ($senderType === 'ai' ? 'images/default-ai.png' : 'images/default-profile.png'),
            'sender_online' => 1,
            'self' => $senderType === 'user' ? 1 : 0,
            'is_ai_response' => $senderType === 'ai' ? 1 : 0,  // Mark AI responses
            'reply_to_id' => $replyToId > 0 ? $replyToId : null,
            'reply_to_ref_id' => $replyToId > 0 ? $replyToId : null,
            'reply_to_display_name' => $replyDisplayName,
            'reply_to_message_body' => $replyMessageBody
        ],
        'trigger_ai_reply' => ($senderType === 'user'), // Only trigger AI reply for user messages
        'ai_chat_key' => 'ai:' . $aiChatId
    ]);
    
} catch (PDOException $e) {
    error_log('AI message storage error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error'], 500);
}
