<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'error' => 'Invalid request method'], 405);
    }
    
    // Get and validate token
    $token = $_POST['token'] ?? '';
    if (empty($token)) {
        respond(['success' => false, 'error' => 'Token is required'], 400);
    }
    
    // Verify token and get user
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    
    if (!$session) {
        respond(['success' => false, 'error' => 'Invalid token'], 401);
    }
    
    $userId = $session['user_id'];
    
    // Get and validate AI chat ID
    $aiChatId = $_POST['ai_chat_id'] ?? '';
    if (empty($aiChatId) || !is_numeric($aiChatId)) {
        respond(['success' => false, 'error' => 'Valid AI chat ID is required'], 400);
    }
    
    // Verify the AI chat belongs to this user
    $stmt = $pdo->prepare("SELECT id FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$aiChatId, $userId]);
    $aiChat = $stmt->fetch();
    
    if (!$aiChat) {
        respond(['success' => false, 'error' => 'AI chat not found or access denied'], 403);
    }
    
    // Clear AI memory by adding a special system message that resets context
    // This approach preserves message history while clearing AI's memory
    $memoryResetMessage = "SYSTEM: AI memory has been cleared. Previous conversation context is no longer available.";
    
    $stmt = $pdo->prepare("
        INSERT INTO ai_chat_messages (ai_chat_id, user_id, sender_type, message, created_at) 
        VALUES (?, ?, 'ai', ?, NOW())
    ");
    $stmt->execute([$aiChatId, $userId, $memoryResetMessage]);
    
    // Return success response
    respond([
        'success' => true,
        'message' => 'AI memory cleared successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("Clear AI Memory Database Error: " . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    error_log("Clear AI Memory Error: " . $e->getMessage());
    respond(['success' => false, 'error' => $e->getMessage()], 400);
}
?>