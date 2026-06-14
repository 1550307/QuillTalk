<?php
declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$results = [];

// Test user_focus_state table
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM user_focus_state LIMIT 1");
    $results['user_focus_state'] = 'EXISTS';
} catch (Exception $e) {
    $results['user_focus_state'] = 'MISSING: ' . $e->getMessage();
}

// Test notification_batch_tracker table
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM notification_batch_tracker LIMIT 1");
    $results['notification_batch_tracker'] = 'EXISTS';
} catch (Exception $e) {
    $results['notification_batch_tracker'] = 'MISSING: ' . $e->getMessage();
}

// Test if we can insert a focus state
try {
    $stmt = $pdo->prepare("INSERT INTO user_focus_state (user_id, is_focused) VALUES (999999, 1) ON DUPLICATE KEY UPDATE is_focused = 1");
    $stmt->execute();
    $results['focus_insert_test'] = 'SUCCESS';
} catch (Exception $e) {
    $results['focus_insert_test'] = 'FAILED: ' . $e->getMessage();
}

// Test if we can insert a batch tracker
try {
    $stmt = $pdo->prepare("INSERT INTO notification_batch_tracker (user_id, chat_identifier) VALUES (999999, 'test:chat')");
    $stmt->execute();
    $results['batch_insert_test'] = 'SUCCESS';
} catch (Exception $e) {
    $results['batch_insert_test'] = 'FAILED: ' . $e->getMessage();
}

// Clean up test data
try {
    $pdo->prepare("DELETE FROM user_focus_state WHERE user_id = 999999")->execute();
    $pdo->prepare("DELETE FROM notification_batch_tracker WHERE user_id = 999999")->execute();
} catch (Exception $e) {
    // Ignore cleanup errors
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>