<?php
header("Access-Control-Allow-Origin: *");
require __DIR__ . '/includes/db.php';

$token = $_POST['token'] ?? '';
$receiver_id = $_POST['receiver_id'] ?? '';

if (!$token || !$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Validate session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$user_id = $stmt->fetchColumn();
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

if ($user_id == $receiver_id) {
    echo json_encode(['success' => false, 'error' => 'You cannot send a friend request to yourself']);
    exit;
}

// Check if friend request or friendship already exists
$check = $pdo->prepare("SELECT * FROM friend_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
$check->execute([$user_id, $receiver_id]);
if ($check->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Friend request already sent']);
    exit;
}

// Check if already friends
$checkFriend = $pdo->prepare("SELECT * FROM friends WHERE user_id = ? AND friend_id = ?");
$checkFriend->execute([$user_id, $receiver_id]);
if ($checkFriend->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Already friends']);
    exit;
}

// Insert friend request
$insert = $pdo->prepare("INSERT INTO friend_requests (sender_id, receiver_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
$insert->execute([$user_id, $receiver_id]);

echo json_encode(['success' => true]);
