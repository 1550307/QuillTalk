<?php
/**
 * send_email_verification.php
 * Sends a verification code to the new email address
 */

header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';
require __DIR__ . '/quilltalk-backend/vendor/autoload.php';

use \Mailjet\Resources;

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['token']) || !isset($input['new_email'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    $token = $input['token'];
    $newEmail = trim($input['new_email']);
    
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
    
    // Check if email is already in use by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmt->execute([$newEmail, $session['user_id']]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        echo json_encode(['success' => false, 'error' => 'Email is already in use']);
        exit;
    }
    
    // Generate 6-digit verification code
    $verificationCode = rand(100000, 999999);
    
    // Store verification code in database (temporary storage)
    try {
        $stmt = $pdo->prepare("UPDATE users SET email_verification_code = ?, email_verification_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?");
        $stmt->execute([$verificationCode, $session['user_id']]);
    } catch (PDOException $e) {
        // Check if columns don't exist
        if (strpos($e->getMessage(), 'email_verification_code') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            error_log('Email verification columns missing. Run add_email_verification_columns.php');
            echo json_encode(['success' => false, 'error' => 'Database not configured. Please contact administrator.']);
            exit;
        }
        throw $e;
    }
    
    // Get user display name for email
    $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$session['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $displayName = $user['display_name'] ?? 'User';
    $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    
    // Send verification email using Mailjet
    $mj = new \Mailjet\Client(
        '7e8fccae9161da8d7c240c379532756d',
        '7b0d2ccc3317330e1aa4e11563bed8d3',
        true,
        ['version' => 'v3.1']
    );
    
    $body = [
        'Messages' => [
            [
                'From' => ['Email' => "noreply@quilltalk.org", 'Name' => "QuillTalk"],
                'To'   => [['Email' => $newEmail, 'Name' => $displayName]],
                'Subject' => "Verify Your New Email Address",
                'HTMLPart' => "<p>Hey {$safeDisplayName},</p><p>You requested to change your email address. Your verification code is:</p><h2>{$verificationCode}</h2><p>This code will expire in 15 minutes.</p><p>If you didn't request this change, please ignore this email.</p>"
            ]
        ]
    ];
    
    $response = $mj->post(Resources::$Email, ['body' => $body]);
    
    if (!$response->success()) {
        error_log('Mailjet error: ' . json_encode($response->getData()));
        echo json_encode(['success' => false, 'error' => 'Failed to send verification email']);
        exit;
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('send_email_verification.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
