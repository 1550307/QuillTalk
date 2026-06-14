<?php
declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Create ai_chats table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_chats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            bio TEXT,
            notes TEXT,
            profile_pic VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Create ai_chat_messages table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ai_chat_id INT NOT NULL,
            user_id INT NOT NULL,
            sender_type ENUM('user', 'ai') NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_chat (ai_chat_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (ai_chat_id) REFERENCES ai_chats(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo json_encode([
        'success' => true,
        'message' => 'AI chat tables created successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>