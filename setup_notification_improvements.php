<?php
declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Create user focus state table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_focus_state (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            is_focused BOOLEAN NOT NULL DEFAULT FALSE,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_last_activity (last_activity),
            UNIQUE KEY unique_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Create notification batch tracker table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_batch_tracker (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            chat_identifier VARCHAR(255) NOT NULL,
            is_read BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_chat (user_id, chat_identifier),
            INDEX idx_created_at (created_at),
            INDEX idx_is_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Add is_read column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE notification_batch_tracker ADD COLUMN is_read BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
    
    try {
        $pdo->exec("ALTER TABLE notification_batch_tracker ADD INDEX idx_is_read (is_read)");
    } catch (Exception $e) {
        // Index might already exist, ignore error
    }
    
    // Clean up old notification batch records (older than 1 hour)
    $pdo->exec("
        DELETE FROM notification_batch_tracker 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification improvement tables created successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>