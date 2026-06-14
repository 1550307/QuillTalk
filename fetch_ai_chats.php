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

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

if ($token === '') {
    respond(['success' => false, 'error' => 'Missing token'], 400);
}

// Validate session
$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

// Fetch user's AI chats
try {
    // Check if ai_chats table exists
    $tableCheckStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_chats'
    ");
    $tableCheckStmt->execute();
    $tableExists = (int)($tableCheckStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
    
    if (!$tableExists) {
        respond(['success' => true, 'ai_chats' => []]);
    }
    
    // Check if ai_chat_messages table exists
    $messagesTableCheckStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_chat_messages'
    ");
    $messagesTableCheckStmt->execute();
    $messagesTableExists = (int)($messagesTableCheckStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
    
    error_log('AI Chats: Messages table exists: ' . ($messagesTableExists ? 'yes' : 'no'));
    
    if ($messagesTableExists) {
        // Include last message in query
        error_log('AI Chats: Using query WITH last message');
        $stmt = $pdo->prepare("
            SELECT 
                ac.id, 
                ac.display_name, 
                ac.bio, 
                ac.notes, 
                ac.profile_pic, 
                ac.created_at,
                (
                    SELECT m.message 
                    FROM ai_chat_messages m 
                    WHERE m.ai_chat_id = ac.id 
                    ORDER BY m.created_at DESC 
                    LIMIT 1
                ) as last_message,
                (
                    SELECT m.created_at 
                    FROM ai_chat_messages m 
                    WHERE m.ai_chat_id = ac.id 
                    ORDER BY m.created_at DESC 
                    LIMIT 1
                ) as last_message_at
            FROM ai_chats ac
            WHERE ac.user_id = ?
            ORDER BY 
                COALESCE(
                    (SELECT m.created_at FROM ai_chat_messages m WHERE m.ai_chat_id = ac.id ORDER BY m.created_at DESC LIMIT 1),
                    ac.created_at
                ) DESC
        ");
    } else {
        // Messages table doesn't exist, just get AI chats without last message
        error_log('AI Chats: Using query WITHOUT last message');
        $stmt = $pdo->prepare("
            SELECT 
                ac.id, 
                ac.display_name, 
                ac.bio, 
                ac.notes, 
                ac.profile_pic, 
                ac.created_at,
                NULL as last_message,
                NULL as last_message_at
            FROM ai_chats ac
            WHERE ac.user_id = ?
            ORDER BY ac.created_at DESC
        ");
    }
    
    $stmt->execute([$userId]);
    $aiChats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log('AI Chats: Fetched ' . count($aiChats) . ' chats');
    if (count($aiChats) > 0) {
        error_log('AI Chats: First chat last_message: ' . ($aiChats[0]['last_message'] ?? 'NULL'));
    }
    
    respond([
        'success' => true,
        'ai_chats' => $aiChats
    ]);
    
} catch (PDOException $e) {
    error_log('Fetch AI chats error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error'], 500);
}
