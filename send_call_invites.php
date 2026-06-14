<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/groups.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$invitedUserIds = $input['invited_user_ids'] ?? [];
$callType = $input['call_type'] ?? 'direct';
$groupCallId = $input['group_call_id'] ?? null;
$currentParticipants = $input['current_participants'] ?? [];

if (!$token || empty($invitedUserIds)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Validate session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$inviterId = $session['user_id'];
$normalizedInvitedUserIds = array_values(array_unique(array_filter(
    array_map(static fn($value): int => (int)$value, is_array($invitedUserIds) ? $invitedUserIds : []),
    static fn(int $invitedUserId): bool => $invitedUserId > 0
)));

if (!$normalizedInvitedUserIds) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Get inviter info
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(NULLIF(display_name, ''), username) AS display_name,
        profile_pic
    FROM users 
    WHERE id = ?
");
$stmt->execute([$inviterId]);
$inviter = $stmt->fetch();

if (!$inviter) {
    echo json_encode(['success' => false, 'error' => 'Inviter not found']);
    exit;
}

try {
    $groupCallGroupId = null;
    if ($callType === 'group' && $groupCallId) {
        $groupCallStmt = $pdo->prepare("
            SELECT group_id
            FROM group_calls
            WHERE id = ?
            LIMIT 1
        ");
        $groupCallStmt->execute([(int)$groupCallId]);
        $groupCall = $groupCallStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $groupCallGroupId = $groupCall ? (int)($groupCall['group_id'] ?? 0) : null;
    }

    // Create call invite records
    $stmt = $pdo->prepare("
        INSERT INTO call_invites 
        (inviter_id, invited_user_id, call_type, group_call_id, current_participants, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");

    $participantsJson = json_encode($currentParticipants);

    foreach ($normalizedInvitedUserIds as $invitedUserId) {
        $stmt->execute([
            $inviterId,
            $invitedUserId,
            $callType,
            $groupCallId,
            $participantsJson
        ]);
    }

    if ($callType === 'group' && $groupCallId) {
        foreach ($normalizedInvitedUserIds as $invitedUserId) {
            qt_ensure_group_call_member($pdo, (int)$groupCallId, (int)$groupCallGroupId, $invitedUserId, QT_GROUP_CALL_MEMBER_PENDING);
        }
        qt_refresh_group_call_session_status($pdo, (int)$groupCallId);
    }

    echo json_encode([
        'success' => true,
        'invited_count' => count($normalizedInvitedUserIds)
    ]);
} catch (Exception $e) {
    error_log("Send call invites error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to send invites']);
}
