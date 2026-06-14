<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$data = json_decode(file_get_contents('php://input') ?: '[]', true);
$token = trim((string)($data['token'] ?? ''));
$callId = (int)($data['call_id'] ?? 0);
$action = trim((string)($data['action'] ?? ''));

if ($token === '' || $callId <= 0 || !in_array($action, ['accept', 'reject', 'leave', 'end'], true)) {
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
$callStmt = $pdo->prepare("
    SELECT id, group_id, status
    FROM group_calls
    WHERE id = ?
    LIMIT 1
");
$callStmt->execute([$callId]);
$call = $callStmt->fetch(PDO::FETCH_ASSOC);
if (!$call) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group call not found']);
    exit;
}

$groupId = (int)($call['group_id'] ?? 0);
if (!qt_user_can_access_group_call($pdo, $userId, $callId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot access that group call.']);
    exit;
}

if ($action === 'end' && !qt_is_private_adhoc_group_call($pdo, $callId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only private calls can be ended for everyone.']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'accept') {
        qt_ensure_group_call_member($pdo, $callId, $groupId, $userId, QT_GROUP_CALL_MEMBER_ACCEPTED);
    } elseif ($action === 'reject') {
        qt_ensure_group_call_member($pdo, $callId, $groupId, $userId, QT_GROUP_CALL_MEMBER_REJECTED);
        qt_set_group_call_member_status($pdo, $callId, $userId, QT_GROUP_CALL_MEMBER_REJECTED);
    } elseif ($action === 'end') {
        qt_force_end_group_call_session($pdo, $callId);
    } else {
        qt_ensure_group_call_member($pdo, $callId, $groupId, $userId, QT_GROUP_CALL_MEMBER_LEFT);
        qt_set_group_call_member_status($pdo, $callId, $userId, QT_GROUP_CALL_MEMBER_LEFT);
    }

    qt_refresh_group_call_session_status($pdo, $callId);
    $pdo->commit();

    $details = qt_fetch_group_call_details($pdo, $userId, $callId);
    if (!$details) {
        throw new RuntimeException('Unable to load that group call.');
    }

    echo json_encode([
        'success' => true,
        'call' => $details['call'],
        'group' => $details['group'],
        'participants' => $details['participants'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update that group call right now.']);
}
