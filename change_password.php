<?php
@ini_set('display_errors', '0');
@error_reporting(0);

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    require_once __DIR__ . '/includes/db.php';
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'DB connection failed']));
}

$rawInput = @file_get_contents('php://input');
$input = @json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    die(json_encode(['success' => false, 'error' => 'Invalid input']));
}

$token = isset($input['token']) ? trim($input['token']) : '';
$new_password = isset($input['new_password']) ? $input['new_password'] : '';

if (empty($token) || empty($new_password)) {
    die(json_encode(['success' => false, 'error' => 'Missing fields']));
}

try {
    // Get user_id from session token
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session || !isset($session['user_id'])) {
        die(json_encode(['success' => false, 'error' => 'Invalid token']));
    }
    
    // Hash new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update password
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $success = $stmt->execute([$hashed_password, $session['user_id']]);
    
    if ($success) {
        die(json_encode(['success' => true]));
    } else {
        die(json_encode(['success' => false, 'error' => 'Failed to update password']));
    }
    
} catch (Exception $e) {
    @error_log('change_password error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Server error']));
}
