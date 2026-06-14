<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$data = json_decode(file_get_contents('php://input') ?: '[]', true);
$token   = trim((string)($data['token'] ?? ''));
$callId  = (int)($data['call_id'] ?? 0);
$groupId = (int)($data['group_id'] ?? 0);

if ($token === '' || $callId <= 0 || $groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { http_response_code(401); echo json_encode(['success' => false]); exit; }

// Fetch the current call status
$callStmt = $pdo->prepare("SELECT status, created_at, ended_at FROM group_calls WHERE id = ? LIMIT 1");
$callStmt->execute([$callId]);
$call = $callStmt->fetch(PDO::FETCH_ASSOC);
if (!$call) { echo json_encode(['success' => false, 'error' => 'Call not found']); exit; }

$status    = $call['status'] === 'ended' ? 'ended' : 'ongoing';
$startedAt = $call['created_at'];
$endedAt   = $call['ended_at'];

// Find the group_messages row for this call
$msgStmt = $pdo->prepare("
    SELECT id, message FROM group_messages
    WHERE group_id = ? AND message LIKE '__GROUP_CALL__%'
    ORDER BY id DESC LIMIT 10
");
$msgStmt->execute([$groupId]);
$rows = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

$targetMsgId = null;
foreach ($rows as $row) {
    $raw = $row['message'];
    if (!str_starts_with($raw, '__GROUP_CALL__:')) continue;
    $payload = json_decode(substr($raw, strlen('__GROUP_CALL__:')), true);
    if (is_array($payload) && (int)($payload['call_id'] ?? 0) === $callId) {
        $targetMsgId = (int)$row['id'];
        break;
    }
}

if (!$targetMsgId) {
    echo json_encode(['success' => false, 'error' => 'Call message not found']);
    exit;
}

$newPayload = json_encode([
    'call_id'    => $callId,
    'group_id'   => $groupId,
    'status'     => $status,
    'started_at' => $startedAt,
    'ended_at'   => $endedAt,
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("UPDATE group_messages SET message = ? WHERE id = ?")
    ->execute(['__GROUP_CALL__:' . $newPayload, $targetMsgId]);

echo json_encode(['success' => true, 'message_id' => $targetMsgId, 'status' => $status]);
