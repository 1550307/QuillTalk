<?php
/**
 * change_email.php
 * Changes the user's email address after verifying the code
 */

header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['token']) || !isset($input['new_email']) || !isset($input['verification_code'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    $token = $input['token'];
    $newEmail = trim($input['new_email']);
    $verificationCode = trim($input['verification_code']);
    
    // Validate email format
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email format']);
        exit;
    }
    
    // Get user from session token
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Invalid session']);
        exit;
    }
    
    // Get user's verification code and expiry
    $stmt = $pdo->prepare("SELECT email_verification_code, email_verification_expires FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$session['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Check if code has expired
    if (!$user['email_verification_expires'] || strtotime($user['email_verification_expires']) < time()) {
        echo json_encode(['success' => false, 'error' => 'Verification code has expired']);
        exit;
    }
    
    // Verify the code
    if ($user['email_verification_code'] !== $verificationCode) {
        echo json_encode(['success' => false, 'error' => 'Invalid verification code']);
        exit;
    }
    
    // Check if email is already in use by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmt->execute([$newEmail, $session['user_id']]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        echo json_encode(['success' => false, 'error' => 'Email is already in use']);
        exit;
    }
    
    // Update email and clear verification code
    $stmt = $pdo->prepare("UPDATE users SET email = ?, email_verification_code = NULL, email_verification_expires = NULL WHERE id = ?");
    $stmt->execute([$newEmail, $session['user_id']]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('change_email.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
