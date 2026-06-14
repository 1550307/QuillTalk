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
$aiChatId = (int)($_POST['ai_chat_id'] ?? 0);
$displayName = trim((string)($_POST['display_name'] ?? ''));
$bio = trim((string)($_POST['bio'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$clearProfilePic = trim((string)($_POST['clear_profile_pic'] ?? '')) === '1';
$defaultProfilePicPath = 'images/default-ai.png';

if ($token === '' || $aiChatId === 0) {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

// Validate session
$stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

// Verify ownership and load current profile picture
$checkStmt = $pdo->prepare('SELECT id, profile_pic FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1');
$checkStmt->execute([$aiChatId, $userId]);
$existingAiChat = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$existingAiChat) {
    respond(['success' => false, 'error' => 'AI chat not found or access denied'], 403);
}

$existingProfilePicPath = trim((string)($existingAiChat['profile_pic'] ?? ''));

function maybeDeleteAiProfilePicFile(string $profilePicPath): void
{
    $normalizedPath = trim($profilePicPath);
    if ($normalizedPath === '' || stripos($normalizedPath, 'uploads/ai_profiles/') !== 0) {
        return;
    }

    $absolutePath = __DIR__ . '/' . str_replace(['\\', '..'], ['/', ''], $normalizedPath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
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
            maybeDeleteAiProfilePicFile($existingProfilePicPath);
        }
    }
} elseif ($clearProfilePic) {
    maybeDeleteAiProfilePicFile($existingProfilePicPath);
    $profilePicPath = $defaultProfilePicPath;
}

// Update AI chat
try {
    if ($profilePicPath !== null) {
        $updateStmt = $pdo->prepare("
            UPDATE ai_chats
            SET display_name = ?, bio = ?, notes = ?, profile_pic = ?
            WHERE id = ? AND user_id = ?
        ");
        $updateStmt->execute([$displayName, $bio, $notes, $profilePicPath, $aiChatId, $userId]);
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE ai_chats
            SET display_name = ?, bio = ?, notes = ?
            WHERE id = ? AND user_id = ?
        ");
        $updateStmt->execute([$displayName, $bio, $notes, $aiChatId, $userId]);
        
        // Get current profile pic
        $picStmt = $pdo->prepare('SELECT profile_pic FROM ai_chats WHERE id = ? LIMIT 1');
        $picStmt->execute([$aiChatId]);
        $picData = $picStmt->fetch(PDO::FETCH_ASSOC);
        $profilePicPath = $picData['profile_pic'] ?? $defaultProfilePicPath;
    }
    
    respond([
        'success' => true,
        'display_name' => $displayName,
        'profile_pic' => $profilePicPath
    ]);
    
} catch (PDOException $e) {
    error_log('AI chat update error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log('AI chat update error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}
