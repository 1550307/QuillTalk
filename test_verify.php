<?php
// Test file to debug verify_password.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

echo "Step 1: Starting test\n";

try {
    echo "Step 2: Including db.php\n";
    require_once __DIR__ . '/includes/db.php';
    echo "Step 3: db.php included successfully\n";
    echo "PDO object exists: " . (isset($pdo) ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    echo "ERROR in db.php: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit;
}

echo "Step 4: Testing password_verify function\n";
$test_hash = password_hash('test123', PASSWORD_DEFAULT);
echo "Hash created: " . substr($test_hash, 0, 20) . "...\n";
$verify_result = password_verify('test123', $test_hash);
echo "Verify result: " . ($verify_result ? 'SUCCESS' : 'FAILED') . "\n";

echo "\nStep 5: Testing database query\n";
try {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Query successful. User found: " . (isset($user['id']) ? 'YES (ID: ' . $user['id'] . ')' : 'NO') . "\n";
} catch (Throwable $e) {
    echo "ERROR in query: " . $e->getMessage() . "\n";
}

echo "\nAll tests completed!\n";
