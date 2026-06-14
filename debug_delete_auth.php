<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$messageId = (int)($_POST['message_id'] ?? $_GET['message_id'] ?? 0);

if (!$token || !$messageId) {
    echo json_encode(['error' => 'Missing token or message_id']);
    exit;
}

// Get user from session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    echo json_encode(['error' => 'Invalid session']);
    exit;
}

$userId = (int)$session['user_id'];

// Check if message is in group_messages
$msgStmt = $pdo->prepare("SELECT id, sender_id, group_id FROM group_messages WHERE id = ? LIMIT 1");
$msgStmt->execute([$messageId]);
$msg = $msgStmt->fetch(PDO::FETCH_ASSOC);

if (!$msg) {
    echo json_encode(['error' => 'Message not found in group_messages']);
    exit;
}

$groupId = (int)$msg['group_id'];
$senderId = (int)$msg['sender_id'];

// Check user's role in the group
$roleStmt = $pdo->prepare("
    SELECT role 
    FROM chat_group_members 
    WHERE group_id = ? AND user_id = ? 
    LIMIT 1
");
$roleStmt->execute([$groupId, $userId]);
$memberData = $roleStmt->fetch(PDO::FETCH_ASSOC);

$result = [
    'message_id' => $messageId,
    'message_sender_id' => $senderId,
    'group_id' => $groupId,
    'current_user_id' => $userId,
    'is_sender' => $senderId === $userId,
    'user_in_group' => $memberData ? true : false,
    'user_role' => $memberData ? $memberData['role'] : null,
    'is_admin_or_owner' => $memberData && in_array($memberData['role'], ['admin', 'owner']),
    'can_delete' => ($senderId === $userId) || ($memberData && in_array($memberData['role'], ['admin', 'owner'])),
    'authorization_reason' => null
];

if ($result['can_delete']) {
    if ($result['is_sender']) {
        $result['authorization_reason'] = 'User is the message sender';
    } elseif ($result['is_admin_or_owner']) {
        $result['authorization_reason'] = 'User is admin/owner of the group';
    }
} else {
    if (!$result['user_in_group']) {
        $result['authorization_reason'] = 'User is not a member of the group';
    } elseif (!$result['is_admin_or_owner']) {
        $result['authorization_reason'] = 'User is a member but not admin/owner';
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>