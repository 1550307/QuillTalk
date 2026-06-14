<?php
// Direct test of verify_password.php logic
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

echo "Testing verify_password.php logic:\n\n";

try {
    require_once __DIR__ . '/includes/db.php';
    echo "✓ DB included\n";
} catch (Throwable $e) {
    echo "✗ DB error: " . $e->getMessage() . "\n";
    exit;
}

// Simulate the input
$token = '92fdfb2e7aba49b829b5502c37b39510a32883c695220f0a99f7ba8a7d4c70f9';
$password = 'test123'; // Use your actual password

echo "Token: " . substr($token, 0, 20) . "...\n";
echo "Password: " . str_repeat('*', strlen($password)) . "\n\n";

try {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE token = ?");
    echo "✓ Statement prepared\n";
    
    $stmt->execute([$token]);
    echo "✓ Statement executed\n";
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "✗ No user found with this token\n";
        exit;
    }
    
    echo "✓ User found (ID: " . $user['id'] . ")\n";
    echo "✓ Password hash exists: " . (isset($user['password_hash']) ? 'YES' : 'NO') . "\n";
    
    if (isset($user['password_hash'])) {
        echo "Hash preview: " . substr($user['password_hash'], 0, 20) . "...\n";
        
        // Test password_verify
        $result = password_verify($password, $user['password_hash']);
        echo "\nPassword verify result: " . ($result ? '✓ MATCH' : '✗ NO MATCH') . "\n";
    }
    
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
