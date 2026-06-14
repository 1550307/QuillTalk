<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? '';
$other_user_id = (int)($data['user_id'] ?? 0);

if ($token === '' || !$other_user_id) {
    http_response_code(400);
    echo json_encode(['are_friends' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(401);
    echo json_encode(['are_friends' => false, 'error' => 'Invalid session']);
    exit;
}

$current_user_id = (int)$session['user_id'];

// Check if users are friends
$friendshipCheck = $pdo->prepare("
    SELECT 1
    FROM friends
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
    LIMIT 1
");
$friendshipCheck->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
$areFriends = (bool)$friendshipCheck->fetch();

echo json_encode([
    'are_friends' => $areFriends,
    'current_user_id' => $current_user_id,
    'other_user_id' => $other_user_id
]);
