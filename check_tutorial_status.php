<?php
/**
 * Quick script to check tutorial status for all users
 * This helps verify the tutorial system is working correctly
 */

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

try {
    // Get tutorial statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN tutorial_completed = 1 THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN tutorial_skipped = 1 THEN 1 ELSE 0 END) as skipped,
            SUM(CASE WHEN tutorial_completed = 0 AND tutorial_skipped = 0 THEN 1 ELSE 0 END) as pending
        FROM users
    ");
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get sample users who haven't completed tutorial
    $stmt = $pdo->query("
        SELECT id, username, display_name, tutorial_completed, tutorial_skipped
        FROM users
        WHERE tutorial_completed = 0 AND tutorial_skipped = 0
        LIMIT 5
    ");
    
    $pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'statistics' => $stats,
        'sample_pending_users' => $pendingUsers,
        'message' => 'Tutorial system is active. Users with pending=1 will see the tutorial on next login.'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
