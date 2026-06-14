<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$token = trim((string)($_GET['token'] ?? ''));
$target = qt_parse_chat_target($_GET['with'] ?? ($_GET['group_id'] ?? ''));

if ($token === '' || $target['type'] !== 'group' || $target['id'] <= 0) {
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

$viewerId = (int)($session['user_id'] ?? 0);
$details = qt_fetch_group_details($pdo, $viewerId, (int)$target['id']);
if (!$details) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group chat not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'group' => $details['group'],
    'members' => $details['members'],
], JSON_UNESCAPED_UNICODE);
