<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/groups.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$inviteId = $input['invite_id'] ?? 0;
$response = $input['response'] ?? ''; // 'accepted' or 'rejected'

if (!$token || !$inviteId || !in_array($response, ['accepted', 'rejected'])) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid fields']);
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

$userId = $session['user_id'];

try {
    // Get invite details
    $stmt = $pdo->prepare("
        SELECT ci.inviter_id, ci.invited_user_id, ci.call_type, ci.group_call_id, gc.group_id
        FROM call_invites ci
        LEFT JOIN group_calls gc ON gc.id = ci.group_call_id
        WHERE ci.id = ? AND ci.invited_user_id = ? AND ci.status = 'pending'
    ");
    $stmt->execute([$inviteId, $userId]);
    $invite = $stmt->fetch();

    if (!$invite) {
        echo json_encode(['success' => false, 'error' => 'Invite not found or already responded']);
        exit;
    }

    // Update invite status
    $stmt = $pdo->prepare("
        UPDATE call_invites 
        SET status = ?, responded_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$response, $inviteId]);

    // If rejected, notify the inviter
    if ($response === 'rejected') {
        if (!empty($invite['group_call_id'])) {
            qt_ensure_group_call_member($pdo, (int)$invite['group_call_id'], (int)($invite['group_id'] ?? 0), $userId, QT_GROUP_CALL_MEMBER_REJECTED);
            qt_set_group_call_member_status($pdo, (int)$invite['group_call_id'], $userId, QT_GROUP_CALL_MEMBER_REJECTED);
            qt_refresh_group_call_session_status($pdo, (int)$invite['group_call_id']);
        }

        // Get user info for notification
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(display_name, ''), username) AS display_name
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Store rejection notification
        $stmt = $pdo->prepare("
            INSERT INTO call_invite_rejections 
            (inviter_id, rejected_by_user_id, rejected_by_display_name, invite_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $invite['inviter_id'],
            $userId,
            $user['display_name'] ?? 'User',
            $inviteId
        ]);
    }

    echo json_encode([
        'success' => true,
        'response' => $response,
        'call_type' => $invite['call_type'],
        'group_call_id' => $invite['group_call_id'],
        'group_id' => isset($invite['group_id']) ? (int)$invite['group_id'] : 0,
        'group_key' => !empty($invite['group_id'])
            ? ('group:' . (int)$invite['group_id'])
            : (!empty($invite['group_call_id']) ? ('call:' . (int)$invite['group_call_id']) : null)
    ]);
} catch (Exception $e) {
    error_log("Respond call invite error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to respond to invite']);
}
