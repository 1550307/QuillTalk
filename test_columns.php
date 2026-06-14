<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

require_once __DIR__ . '/includes/db.php';

echo "Checking users table columns:\n\n";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "Column: " . $col['Field'] . " (Type: " . $col['Type'] . ")\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}
