<?php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

try {
    $tables = [];
    
    // Check polls table
    $stmt = $pdo->query("SHOW TABLES LIKE 'polls'");
    $tables['polls'] = $stmt->rowCount() > 0;
    
    // Check poll_options table
    $stmt = $pdo->query("SHOW TABLES LIKE 'poll_options'");
    $tables['poll_options'] = $stmt->rowCount() > 0;
    
    // Check poll_votes table
    $stmt = $pdo->query("SHOW TABLES LIKE 'poll_votes'");
    $tables['poll_votes'] = $stmt->rowCount() > 0;
    
    // Get table structures if they exist
    $structures = [];
    
    if ($tables['polls']) {
        $stmt = $pdo->query("DESCRIBE polls");
        $structures['polls'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($tables['poll_options']) {
        $stmt = $pdo->query("DESCRIBE poll_options");
        $structures['poll_options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($tables['poll_votes']) {
        $stmt = $pdo->query("DESCRIBE poll_votes");
        $structures['poll_votes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'tables' => $tables,
        'structures' => $structures
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
