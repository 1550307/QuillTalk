<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

$data = json_decode(file_get_contents('php://input'), true);
$token = trim((string)($data['token'] ?? ''));
$groupId = (int)($data['group_id'] ?? 0);
$memberId = (int)($data['member_id'] ?? 0);
$role = qt_group_normalize_role((string)($data['role'] ?? ''));

if ($token === '' || $groupId <= 0 || $memberId <= 0 || !in_array($role, [QT_GROUP_ROLE_ADMIN, QT_GROUP_ROLE_MEMBER], true)) {
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
$viewerMember = qt_get_group_member_record($pdo, $groupId, $viewerId);
$targetMember = qt_get_group_member_record($pdo, $groupId, $memberId);

if (!$viewerMember || !$targetMember) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group member not found']);
    exit;
}

if (!qt_group_can_change_member_role($viewerMember['role'] ?? '', $targetMember['role'] ?? '', $viewerId, $memberId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot change that member role']);
    exit;
}

ensure_history_events_schema($pdo);

$groupInfoStmt = $pdo->prepare("
    SELECT description
    FROM chat_groups
    WHERE id = ?
    LIMIT 1
");
$groupInfoStmt->execute([$groupId]);
$groupInfo = $groupInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$shouldSkipGroupHistory = qt_is_call_only_group_description((string)($groupInfo['description'] ?? ''));
$previousRole = qt_group_normalize_role((string)($targetMember['role'] ?? ''));
$roleChanged = $previousRole !== $role;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE chat_group_members
        SET role = ?
        WHERE group_id = ? AND user_id = ?
    ");
    $stmt->execute([$role, $groupId, $memberId]);

    if ($roleChanged && !$shouldSkipGroupHistory) {
        $roleLabel = qt_history_group_role_label($role);
        $memberDisplayName = qt_history_fetch_user_display_name($pdo, $memberId, 'Member');

        qt_log_history_event($pdo, [
            'actor_user_id' => $viewerId,
            'subject_user_id' => $memberId,
            'chat_type' => 'group',
            'chat_id' => $groupId,
            'event_type' => 'group_role_changed',
            'event_value' => $roleLabel,
        ]);

        qt_insert_group_news_message($pdo, $groupId, $viewerId, [
            'type' => 'role_changed',
            'subject_name' => $memberDisplayName,
            'role_label' => $roleLabel,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update that member role']);
    exit;
}

$details = qt_fetch_group_details($pdo, $viewerId, $groupId);
echo json_encode([
    'success' => true,
    'group' => $details['group'],
    'members' => $details['members'],
], JSON_UNESCAPED_UNICODE);
