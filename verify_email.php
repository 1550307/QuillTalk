<?php
/**
 * verify_email.php
 * Verifies that the provided email matches the current user's email
 */

header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['token']) || !isset($input['email'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    $token = $input['token'];
    $email = trim($input['email']);
    
    // Get user from session token
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Invalid session']);
        exit;
    }
    
    // Get user email
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$session['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Compare emails (case-insensitive)
    if (strtolower($user['email']) === strtolower($email)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email does not match']);
    }
    
} catch (Exception $e) {
    error_log('verify_email.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
