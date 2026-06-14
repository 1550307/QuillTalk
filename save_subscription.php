<?php
require __DIR__ . '/includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? null;
$sub   = $data['subscription'] ?? null;

if (!$token || !$sub) {
    http_response_code(400);
    exit('Missing data');
}

// resolve user via token
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    exit('Invalid token');
}

$stmt = $pdo->prepare("
  INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
  VALUES (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    endpoint = VALUES(endpoint),
    p256dh = VALUES(p256dh),
    auth = VALUES(auth)
");

$stmt->execute([
  $user['user_id'],
  $sub['endpoint'],
  $sub['keys']['p256dh'],
  $sub['keys']['auth']
]);

echo json_encode(['success' => true]);
