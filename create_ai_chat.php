<?php
declare(strict_types=1);
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Get POST data
$token = trim((string)($_POST['token'] ?? ''));
$displayName = trim((string)($_POST['display_name'] ?? ''));
$bio = trim((string)($_POST['bio'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($token === '') {
    respond(['success' => false, 'error' => 'Missing token'], 400);
}

// Validate session
$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

// Set default display name if empty
if ($displayName === '') {
    $displayName = 'QuillTalk AI';
}

// Handle profile picture upload
$profilePicPath = null;
if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads/ai_profiles/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExt = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($fileExt, $allowedExts, true)) {
        $fileName = uniqid('ai_', true) . '.' . $fileExt;
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $targetPath)) {
            $profilePicPath = 'uploads/ai_profiles/' . $fileName;
        }
    }
}

// Use default AI image if no upload
if ($profilePicPath === null) {
    $profilePicPath = 'images/default-ai.png';
}

// Create AI chat in database
try {
    // Check if ai_chats table exists
    $tableCheckStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_chats'
    ");
    $tableCheckStmt->execute();
    $tableExists = (int)($tableCheckStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
    
    // Create ai_chats table if it doesn't exist
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE ai_chats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                bio TEXT,
                notes TEXT,
                profile_pic VARCHAR(500),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    $insertStmt = $pdo->prepare("
        INSERT INTO ai_chats (user_id, display_name, bio, notes, profile_pic)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $insertStmt->execute([
        $userId,
        $displayName,
        $bio,
        $notes,
        $profilePicPath
    ]);
    
    $aiChatId = (int)$pdo->lastInsertId();
    
    respond([
        'success' => true,
        'ai_chat_id' => $aiChatId,
        'display_name' => $displayName,
        'profile_pic' => $profilePicPath
    ]);
    
} catch (PDOException $e) {
    error_log('AI chat creation error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log('AI chat creation error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

