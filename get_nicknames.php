<?php
@ini_set('display_errors', '0');
@error_reporting(0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/includes/db.php';
} catch (Exception $e) {
    die(json_encode(['success' => true, 'nicknames' => []]));
}

$token = isset($_GET['token']) ? trim($_GET['token']) : (isset($_POST['token']) ? trim($_POST['token']) : '');

if (empty($token)) {
    die(json_encode(['success' => false, 'error' => 'Missing token']));
}

try {
    // Get user_id from session token
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session || !isset($session['user_id'])) {
        die(json_encode(['success' => false, 'error' => 'Invalid token']));
    }
    
    $user_id = $session['user_id'];
    
    // Check if table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_nicknames'");
    if (!$tableCheck || !$tableCheck->fetchColumn()) {
        die(json_encode(['success' => true, 'nicknames' => []]));
    }
    
    // Get all nicknames for this user
    $stmt = $pdo->prepare("SELECT target_user_id, nickname FROM user_nicknames WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $nicknames = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($nicknames as $row) {
        $result[$row['target_user_id']] = $row['nickname'];
    }
    
    die(json_encode(['success' => true, 'nicknames' => $result]));
    
} catch (Exception $e) {
    @error_log('get_nicknames error: ' . $e->getMessage());
    die(json_encode(['success' => true, 'nicknames' => []]));
}
