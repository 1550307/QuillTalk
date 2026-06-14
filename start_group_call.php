<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

$data = json_decode(file_get_contents('php://input') ?: '[]', true);
$token = trim((string)($data['token'] ?? ''));
$groupId = (int)($data['group_id'] ?? 0);
$suppressMessage = !empty($data['suppress_message']);
$participantIds = array_values(array_unique(array_filter(
    array_map(static fn($value): int => (int)$value, is_array($data['participant_ids'] ?? null) ? $data['participant_ids'] : []),
    static fn(int $participantId): bool => $participantId > 0
)));

if ($token === '' || ($groupId <= 0 && !$participantIds)) {
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
if ($groupId > 0 && !qt_user_can_access_group($pdo, $userId, $groupId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot call that group chat.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $startedNew = false;
    $callMsgId = 0;

    if ($groupId > 0) {
        $existing = qt_find_active_group_call_for_group($pdo, $groupId);
        if ($existing) {
            $callId = (int)($existing['id'] ?? 0);
            qt_ensure_group_call_member($pdo, $callId, $groupId, $userId, QT_GROUP_CALL_MEMBER_ACCEPTED);
            qt_refresh_group_call_session_status($pdo, $callId);
        } else {
            $callId = qt_create_group_call_session($pdo, $groupId, $userId);
            $startedNew = true;

            if (!$suppressMessage) {
                // Insert a group call event message so members see it in the chat.
                $callMsgPayload = json_encode([
                    'call_id'          => $callId,
                    'group_id'         => $groupId,
                    'status'           => 'ongoing',
                    'started_at'       => date('Y-m-d H:i:s'),
                    'started_at_unix'  => time(),
                    'ended_at'         => null,
                ], JSON_UNESCAPED_UNICODE);
                try {
                    $pdo->prepare("
                        INSERT INTO group_messages (group_id, sender_id, message, created_at)
                        VALUES (?, ?, ?, NOW())
                    ")->execute([$groupId, $userId, '__GROUP_CALL__:' . $callMsgPayload]);
                    $callMsgId = (int)$pdo->lastInsertId();
                } catch (Throwable $msgErr) {
                    error_log('[GROUP_CALL_MSG_INSERT] ' . $msgErr->getMessage());
                    $callMsgId = 0;
                }
            }
        }
    } else {
        $normalizedParticipantIds = array_values(array_unique(array_filter(
            $participantIds,
            static fn(int $participantId): bool => $participantId !== $userId
        )));
        if (!$normalizedParticipantIds) {
            throw new RuntimeException('Missing parameters');
        }

        $callId = qt_create_adhoc_group_call_session($pdo, $userId, $normalizedParticipantIds);
        $startedNew = true;
    }

    $pdo->commit();

    $details = qt_fetch_group_call_details($pdo, $userId, $callId);
    if (!$details) {
        throw new RuntimeException('Unable to load the group call session.');
    }

    echo json_encode([
        'success' => true,
        'started_new' => $startedNew,
        'call_msg_id' => $callMsgId ?? 0,
        'call' => $details['call'],
        'group' => $details['group'],
        'participants' => $details['participants'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[START_GROUP_CALL] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to start that group call right now.'
    ]);
}
