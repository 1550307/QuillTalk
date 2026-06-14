<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim((string)($input['token'] ?? ''));
$chatKey = trim((string)($input['chat_key'] ?? ''));

if (!$token || !$chatKey) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate session
$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$userId = (int)$session['user_id'];

try {
    // Convert chat key to notification identifier format
    $chatIdentifier = '';
    if (str_starts_with($chatKey, 'group:')) {
        // For group chats, we need to get the group name
        $groupId = (int)substr($chatKey, 6);
        $groupStmt = $pdo->prepare('SELECT name FROM chat_groups WHERE id = ? LIMIT 1');
        $groupStmt->execute([$groupId]);
        $groupName = $groupStmt->fetchColumn();
        if ($groupName) {
            $chatIdentifier = 'group:' . $groupName;
        }
    } elseif (str_starts_with($chatKey, 'ai:')) {
        // For AI chats, use the AI name
        $aiId = (int)substr($chatKey, 3);
        $aiStmt = $pdo->prepare('SELECT display_name FROM ai_chats WHERE id = ? LIMIT 1');
        $aiStmt->execute([$aiId]);
        $aiName = $aiStmt->fetchColumn();
        if ($aiName) {
            $chatIdentifier = 'direct:' . $aiName;
        }
    } else {
        // For direct chats, get the other user's name
        $otherUserId = (int)$chatKey;
        $userStmt = $pdo->prepare('SELECT COALESCE(NULLIF(display_name, ""), username) as name FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$otherUserId]);
        $userName = $userStmt->fetchColumn();
        if ($userName) {
            $chatIdentifier = 'direct:' . $userName;
        }
    }
    
    if ($chatIdentifier) {
        // Mark notifications as read for this chat
        $updateStmt = $pdo->prepare('
            UPDATE notification_batch_tracker 
            SET is_read = 1 
            WHERE user_id = ? AND chat_identifier = ? AND is_read = 0
        ');
        $updateStmt->execute([$userId, $chatIdentifier]);
        
        $rowsUpdated = $updateStmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'chat_identifier' => $chatIdentifier,
            'notifications_marked_read' => $rowsUpdated
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Could not determine chat identifier'
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>