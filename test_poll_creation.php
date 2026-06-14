<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Poll Creation Debug Test</h1>";
echo "<pre>";

// Check if user is logged in
$userId = (int)($_SESSION['user_id'] ?? 0);
echo "User ID from session: $userId\n\n";

if (!$userId) {
    echo "ERROR: Not logged in\n";
    exit;
}

// Check friends table structure
echo "=== FRIENDS TABLE STRUCTURE ===\n";
try {
    $stmt = $pdo->query("DESCRIBE friends");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "{$col['Field']} - {$col['Type']}\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

// Check poll tables structure
echo "=== POLLS TABLE STRUCTURE ===\n";
try {
    $stmt = $pdo->query("DESCRIBE polls");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "{$col['Field']} - {$col['Type']}\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

// Get user's friends
echo "=== USER'S FRIENDS ===\n";
try {
    $stmt = $pdo->prepare("SELECT * FROM friends WHERE user_id = ? OR friend_id = ? LIMIT 5");
    $stmt->execute([$userId, $userId]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($friends)) {
        echo "No friends found\n";
    } else {
        foreach ($friends as $friend) {
            print_r($friend);
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

// Test friendship check query (the one used in create_poll.php)
echo "=== TEST FRIENDSHIP CHECK ===\n";
if (!empty($friends)) {
    $testRecipientId = $friends[0]['user_id'] == $userId ? $friends[0]['friend_id'] : $friends[0]['user_id'];
    echo "Testing friendship with user ID: $testRecipientId\n";
    
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $stmt->execute([$userId, $testRecipientId, $testRecipientId, $userId]);
        $result = $stmt->fetch();
        echo "Friendship check result: " . ($result ? "FOUND" : "NOT FOUND") . "\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== PHP VERSION ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "PDO Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

echo "\n=== OPCODE CACHE ===\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    echo "OPcache enabled: " . ($status ? "YES" : "NO") . "\n";
    if ($status) {
        echo "OPcache full: " . ($status['opcache_statistics']['oom_restarts'] > 0 ? "YES" : "NO") . "\n";
    }
} else {
    echo "OPcache not available\n";
}

echo "</pre>";
