<?php
require __DIR__ . '/includes/db.php';

try {
    // Test if tables exist
    $stmt = $pdo->query("SHOW TABLES LIKE 'ai_chats'");
    $aiChatsExists = $stmt->rowCount() > 0;
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'ai_chat_messages'");
    $aiMessagesExists = $stmt->rowCount() > 0;
    
    echo "AI Chats table exists: " . ($aiChatsExists ? "YES" : "NO") . "\n";
    echo "AI Messages table exists: " . ($aiMessagesExists ? "YES" : "NO") . "\n";
    
    if (!$aiChatsExists || !$aiMessagesExists) {
        echo "\nCreating missing tables...\n";
        
        if (!$aiChatsExists) {
            $pdo->exec("
                CREATE TABLE ai_chats (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    display_name VARCHAR(255) NOT NULL,
                    bio TEXT,
                    notes TEXT,
                    profile_pic VARCHAR(500),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "Created ai_chats table\n";
        }
        
        if (!$aiMessagesExists) {
            $pdo->exec("
                CREATE TABLE ai_chat_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ai_chat_id INT NOT NULL,
                    user_id INT NOT NULL,
                    sender_type ENUM('user', 'ai') NOT NULL,
                    message TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ai_chat (ai_chat_id),
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "Created ai_chat_messages table\n";
        }
    }
    
    echo "\nTables are ready!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>