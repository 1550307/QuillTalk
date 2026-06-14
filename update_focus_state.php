<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim((string)($input['token'] ?? ''));
$isFocused = (bool)($input['is_focused'] ?? false);

if (!$token) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing token']);
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
    // Update or insert focus state
    $stmt = $pdo->prepare('
        INSERT INTO user_focus_state (user_id, is_focused, last_activity) 
        VALUES (?, ?, NOW()) 
        ON DUPLICATE KEY UPDATE 
            is_focused = VALUES(is_focused), 
            last_activity = NOW()
    ');
    $stmt->execute([$userId, $isFocused]);
    
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'is_focused' => $isFocused
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>