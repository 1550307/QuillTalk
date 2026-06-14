<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$token = trim((string)($_GET['token'] ?? ''));
$callId = (int)($_GET['call_id'] ?? 0);

if ($token === '' || $callId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$userId = (int)($session['user_id'] ?? 0);
$details = qt_fetch_group_call_details($pdo, $userId, $callId);
if (!$details) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group call not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'call' => $details['call'],
    'group' => $details['group'],
    'participants' => $details['participants'],
], JSON_UNESCAPED_UNICODE);
