<?php
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

try {
    // Get friends table structure
    $stmt = $pdo->query("DESCRIBE friends");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'columns' => $columns,
        'has_status_column' => in_array('status', array_column($columns, 'Field'))
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
