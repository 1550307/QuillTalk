<?php
header("Access-Control-Allow-Origin: *");
require __DIR__ . '/includes/db.php';

try {
    $pdo->exec("ALTER TABLE friend_requests ADD COLUMN responded_at DATETIME NULL DEFAULT NULL AFTER created_at");
} catch (Throwable $e) {
    // Column already exists or is managed elsewhere.
}

$token = $_POST['token'] ?? '';
$request_id = $_POST['request_id'] ?? '';
$action = $_POST['action'] ?? '';

if (!$token || !$request_id || !$action) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Validate session and user
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$user_id = $stmt->fetchColumn();
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

// Fetch friend request, must be pending and receiver must be current user
$frStmt = $pdo->prepare("SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = 'pending'");
$frStmt->execute([$request_id, $user_id]);
$fr = $frStmt->fetch();
if (!$fr) {
    echo json_encode(['success' => false, 'error' => 'Friend request not found']);
    exit;
}

$sender_id = $fr['sender_id'];

if ($action === 'accept') {
    // Update friend request status
    $update = $pdo->prepare("UPDATE friend_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?");
    $update->execute([$request_id]);

    // Add friends both ways, ignore duplicates
    $insert1 = $pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?)");
    $insert1->execute([$user_id, $sender_id]);
    $insert2 = $pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?)");
    $insert2->execute([$sender_id, $user_id]);

    // Fetch sender info to return
    $userStmt = $pdo->prepare("
        SELECT
            id,
            username,
            COALESCE(NULLIF(display_name, ''), username) AS display_name,
            profile_pic,
            online,
            COALESCE(bio, '') AS bio,
            created_at
        FROM users
        WHERE id = ?
    ");
    $userStmt->execute([$sender_id]);
    $contact = $userStmt->fetch(PDO::FETCH_ASSOC);

    $contact['profile_pic'] = !empty($contact['profile_pic'])
        ? $contact['profile_pic']
        : 'images/default-profile.png';

    echo json_encode([
        'success' => true,
        'contact' => $contact,
        'message' => 'Friend request accepted'
    ]);
    exit;
} elseif ($action === 'reject') {
    // Update friend request status to rejected
    $update = $pdo->prepare("UPDATE friend_requests SET status = 'rejected', responded_at = NOW() WHERE id = ?");
    $update->execute([$request_id]);

    // You might want to notify sender via some system, here we just say success
    echo json_encode([
        'success' => true,
        'message' => 'Friend request rejected',
        'rejected_user_id' => $sender_id
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
