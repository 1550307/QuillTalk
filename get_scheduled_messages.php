<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput === false ? '' : $rawInput, true);
if (!is_array($data)) {
    $data = [];
}

$token = trim((string)($data['token'] ?? $_POST['token'] ?? $_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

// Validate session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    echo json_encode([]);
    exit;
}
$user_id = (int)$session['user_id'];

// Get user's pending scheduled messages
$getScheduled = $pdo->prepare("
    SELECT id, recipient_id, message, scheduled_time, created_at
    FROM scheduled_messages
    WHERE sender_id = ? AND status = 'pending'
    ORDER BY scheduled_time ASC
");
$getScheduled->execute([$user_id]);
$scheduled = $getScheduled->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($scheduled);
