<?php
declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_client_message_id(int $messageId): string
{
    return 'ai-msg-' . max(0, $messageId);
}

function ensure_ai_chat_message_reply_schema(PDO $pdo): void
{
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

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$aiChatKey = trim((string)($_GET['ai_chat_key'] ?? $_POST['ai_chat_key'] ?? ''));
$lastId = (int)($_GET['last_id'] ?? $_POST['last_id'] ?? 0);

if ($token === '' || $aiChatKey === '') {
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

// Fetch messages
try {
    // Check if ai_chat_messages table exists
    $tableCheckStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_chat_messages'
    ");
    $tableCheckStmt->execute();
    $tableExists = (int)($tableCheckStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
    
    if (!$tableExists) {
        respond([]);
    }

    ensure_ai_chat_message_reply_schema($pdo);
    
    $sql = "
        SELECT 
            m.id,
            m.ai_chat_id,
            m.sender_type,
            m.message,
            m.reply_to_id,
            m.created_at,
            m.user_id,
            u.display_name as user_display_name,
            u.username as user_username,
            u.profile_pic as user_profile_pic,
            reply_m.id AS reply_to_ref_id,
            reply_m.sender_type AS reply_sender_type,
            reply_m.message AS reply_to_message_body,
            reply_u.display_name AS reply_user_display_name,
            reply_u.username AS reply_user_username
        FROM ai_chat_messages m
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN ai_chat_messages reply_m
            ON m.reply_to_id = reply_m.id AND reply_m.ai_chat_id = m.ai_chat_id
        LEFT JOIN users reply_u ON reply_m.user_id = reply_u.id
        WHERE m.ai_chat_id = ?
    ";
    
    $params = [$aiChatId];
    
    if ($lastId > 0) {
        $sql .= " AND m.id > ?";
        $params[] = $lastId;
    }
    
    $sql .= " ORDER BY m.created_at ASC";
    
    // For initial load (lastId = 0), load all messages
    // For polling (lastId > 0), limit to recent messages
    if ($lastId > 0) {
        $sql .= " LIMIT 50";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format messages to match the expected structure
    $formattedMessages = [];
    foreach ($messages as $msg) {
        $replyDisplayName = '';
        if (!empty($msg['reply_to_ref_id'])) {
            $replyDisplayName = $msg['reply_sender_type'] === 'ai'
                ? (string)($aiChat['display_name'] ?? 'QuillTalk AI')
                : (string)($msg['reply_user_display_name'] ?? $msg['reply_user_username'] ?? 'You');
        }
        if ($msg['sender_type'] === 'ai') {
            // AI message
            $formattedMessages[] = [
                'id' => ai_client_message_id((int)$msg['id']),
                'client_id' => ai_client_message_id((int)$msg['id']),
                'message_id' => (int)$msg['id'],
                'server_id' => (int)$msg['id'],
                'sender_id' => $aiChatKey,
                'sender_display_name' => $aiChat['display_name'],
                'sender_username' => '',
                'sender_profile_pic' => $aiChat['profile_pic'] ?? 'images/default-ai.png',
                'sender_online' => 1,
                'message' => $msg['message'],
                'created_at' => $msg['created_at'],
                'chat_key' => $aiChatKey,
                'sender_type' => 'ai',
                'self' => 0,
                'is_ai_response' => 1,  // Mark AI responses
                'reply_to_id' => (int)($msg['reply_to_id'] ?? 0) ?: null,
                'reply_to_ref_id' => (int)($msg['reply_to_ref_id'] ?? 0) ?: null,
                'reply_to_display_name' => $replyDisplayName,
                'reply_to_message_body' => $msg['reply_to_message_body'] ?? ''
            ];
        } else {
            // User message
            $formattedMessages[] = [
                'id' => ai_client_message_id((int)$msg['id']),
                'client_id' => ai_client_message_id((int)$msg['id']),
                'message_id' => (int)$msg['id'],
                'server_id' => (int)$msg['id'],
                'sender_id' => (int)$msg['user_id'],
                'sender_display_name' => $msg['user_display_name'] ?? 'User',
                'sender_username' => $msg['user_username'] ?? '',
                'sender_profile_pic' => $msg['user_profile_pic'] ?? 'images/default-profile.png',
                'sender_online' => 1,
                'message' => $msg['message'],
                'created_at' => $msg['created_at'],
                'chat_key' => $aiChatKey,
                'sender_type' => 'user',
                'self' => 1,
                'is_ai_response' => 0,  // Mark user messages
                'reply_to_id' => (int)($msg['reply_to_id'] ?? 0) ?: null,
                'reply_to_ref_id' => (int)($msg['reply_to_ref_id'] ?? 0) ?: null,
                'reply_to_display_name' => $replyDisplayName,
                'reply_to_message_body' => $msg['reply_to_message_body'] ?? ''
            ];
        }
    }
    
    respond($formattedMessages);
    
} catch (PDOException $e) {
    error_log('Fetch AI messages error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error'], 500);
}
