<?php
header("Access-Control-Allow-Origin: *");
require __DIR__ . '/includes/db.php';

$token = $_GET['token'] ?? '';
if (!$token) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing token']);
  exit;
}

// Get the logged-in user's ID from the session token
$stmt = $pdo->prepare("
  SELECT user_id
  FROM sessions
  WHERE token = ?
");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
  http_response_code(403);
  echo json_encode(['error' => 'Invalid session']);
  exit;
}

$user_id = $user['user_id'];

// Fetch pending friend requests where this user is the receiver
$stmt = $pdo->prepare("
  SELECT
    fr.id,
    u.username,
    COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name
  FROM friend_requests fr
  JOIN users u ON fr.sender_id = u.id
  WHERE fr.receiver_id = ?
  AND fr.status = 'pending'
");
$stmt->execute([$user_id]);
$requests = [];

while ($row = $stmt->fetch()) {
  $requests[] = [
    'id' => $row['id'],
    'username' => $row['username'],
    'display_name' => $row['display_name']
  ];
}

header('Content-Type: application/json');
echo json_encode($requests, JSON_UNESCAPED_UNICODE);
