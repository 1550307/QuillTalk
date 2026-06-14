<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

$token = $_GET['token'] ?? '';

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Missing token']);
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
    // Get pending call invites for this user
    $stmt = $pdo->prepare("
        SELECT 
            ci.id,
            ci.inviter_id,
            ci.call_type,
            ci.group_call_id,
            gc.group_id,
            ci.current_participants,
            ci.created_at,
            u.username AS inviter_username,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS inviter_display_name,
            u.profile_pic AS inviter_profile_pic,
            CASE
                WHEN gc.group_id > 0 THEN COALESCE(NULLIF(cg.name, ''), CONCAT('Group ', gc.group_id))
                WHEN ci.group_call_id IS NOT NULL THEN 'Group call'
                ELSE ''
            END AS group_name,
            CASE
                WHEN gc.group_id > 0 THEN COALESCE(NULLIF(cg.icon_path, ''), 'images/default-group.svg')
                ELSE 'images/default-group.svg'
            END AS group_icon
        FROM call_invites ci
        JOIN users u ON ci.inviter_id = u.id
        LEFT JOIN group_calls gc ON gc.id = ci.group_call_id
        LEFT JOIN chat_groups cg ON cg.id = gc.group_id
        WHERE ci.invited_user_id = ?
        AND ci.status = 'pending'
        AND (ci.group_call_id IS NULL OR gc.status IS NULL OR gc.status <> 'ended')
        AND ci.created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ORDER BY ci.created_at DESC
    ");
    $stmt->execute([$userId]);
    $invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode current_participants JSON for each invite
    foreach ($invites as &$invite) {
        $invite['current_participants'] = json_decode($invite['current_participants'] ?? '[]', true);
        $invite['group_id'] = isset($invite['group_id']) ? (int)$invite['group_id'] : 0;
        $invite['group_key'] = !empty($invite['group_id'])
            ? ('group:' . (int)$invite['group_id'])
            : (!empty($invite['group_call_id']) ? ('call:' . (int)$invite['group_call_id']) : null);
    }

    echo json_encode([
        'success' => true,
        'invites' => $invites
    ]);
} catch (Exception $e) {
    error_log("Poll call invites error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch invites']);
}
