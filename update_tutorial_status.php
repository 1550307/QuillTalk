<?php
/**
 * update_tutorial_status.php
 * Updates the user's tutorial completion status
 */

header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['token'])) {
        echo json_encode(['success' => false, 'error' => 'Missing token']);
        exit;
    }
    
    $token = $input['token'];
    $completed = isset($input['completed']) ? (int)$input['completed'] : 0;
    $skipped = isset($input['skipped']) ? (int)$input['skipped'] : 0;
    
    // Get user from session token
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Invalid session']);
        exit;
    }
    
    // Update tutorial status
    $stmt = $pdo->prepare("UPDATE users SET tutorial_completed = ?, tutorial_skipped = ? WHERE id = ?");
    $stmt->execute([$completed, $skipped, $session['user_id']]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('update_tutorial_status.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
