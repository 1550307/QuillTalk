<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($token === '' || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$userId = (int)$session['user_id'];

$check = $pdo->prepare("SELECT caller_id, callee_id, status FROM call_requests WHERE id = ? LIMIT 1");
$check->execute([$id]);
$row = $check->fetch(PDO::FETCH_ASSOC);

if (
    !$row ||
    (
        (int)$row['caller_id'] !== $userId &&
        (int)$row['callee_id'] !== $userId
    )
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => $row['status'] ?? 'pending'
]);
