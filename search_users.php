<?php
// search_users.php
header("Access-Control-Allow-Origin: *");
require __DIR__ . '/includes/db.php';

$token = $_GET['token'] ?? '';
$query = trim($_GET['query'] ?? '');

if (!$token || !$query) {
    echo json_encode([]);
    exit;
}

// Validate session and get current user id
$stmt = $pdo->prepare("
  SELECT user_id FROM sessions WHERE token = ?
");
$stmt->execute([$token]);
$session = $stmt->fetch();
if (!$session) {
    echo json_encode([]);
    exit;
}

$user_id = $session['user_id'];

// Search users by username LIKE query, excluding self
$stmt = $pdo->prepare("
  SELECT
    id,
    username,
    COALESCE(NULLIF(display_name, ''), username) AS display_name,
    COALESCE(profile_pic, '') AS profile_pic,
    online
  FROM users
  WHERE (username LIKE ? OR display_name LIKE ?) AND id != ?
  LIMIT 10
");
$searchTerm = "%$query%";
$stmt->execute([$searchTerm, $searchTerm, $user_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results, JSON_UNESCAPED_UNICODE);
