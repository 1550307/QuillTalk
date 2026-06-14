<?php
// Password verification endpoint
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
$password = isset($input['password']) ? $input['password'] : '';

if (empty($token) || empty($password)) {
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
    
    // Get user password hash
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$session['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !isset($user['password_hash'])) {
        die(json_encode(['success' => false, 'error' => 'User not found']));
    }
    
    // Verify password
    if (password_verify($password, $user['password_hash'])) {
        die(json_encode(['success' => true]));
    } else {
        die(json_encode(['success' => false, 'error' => 'Invalid password']));
    }
    
} catch (Exception $e) {
    @error_log('verify_password error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Server error']));
}
