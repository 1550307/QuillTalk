<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

$data = json_decode(file_get_contents('php://input') ?: '[]', true);
$token = trim((string)($data['token'] ?? ''));
$groupId = (int)($data['group_id'] ?? 0);
$memberIds = array_values(array_filter(array_map('intval', (array)($data['member_ids'] ?? [])), fn($id) => $id > 0));

if ($token === '' || $groupId <= 0 || !$memberIds) {
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
if (!$viewerMember) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You are not a member of this group.']);
    exit;
}

// Check add_members_permission
$groupStmt = $pdo->prepare("SELECT add_members_permission, description FROM chat_groups WHERE id = ? LIMIT 1");
$groupStmt->execute([$groupId]);
$group = $groupStmt->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

$addPerm = qt_group_normalize_add_permission($group['add_members_permission'] ?? '');
$viewerRole = qt_group_normalize_role($viewerMember['role'] ?? '');
$viewerCanAdd = ($addPerm === QT_GROUP_ADD_PERMISSION_ALL) || qt_group_role_can_manage($viewerRole);
$shouldSkipGroupHistory = qt_is_call_only_group_description((string)($group['description'] ?? ''));

if (!$viewerCanAdd) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to add members.']);
    exit;
}

ensure_history_events_schema($pdo);

// Verify each user is a friend of the viewer (security: can only add friends)
$friendCheck = $pdo->prepare("
    SELECT 1 FROM friends
    WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
    LIMIT 1
");

$insert = $pdo->prepare("
    INSERT IGNORE INTO chat_group_members (group_id, user_id, role, joined_at, last_seen_message_id)
    VALUES (?, ?, ?, NOW(), 0)
");
$memberNameStmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(display_name, ''), username) AS display_name
    FROM users
    WHERE id = ?
    LIMIT 1
");

$added = 0;
try {
    $pdo->beginTransaction();

    foreach ($memberIds as $memberId) {
        if ($memberId === $viewerId) {
            continue;
        }

        // Must be friends
        $friendCheck->execute([$viewerId, $memberId, $memberId, $viewerId]);
        if (!$friendCheck->fetch()) {
            continue;
        }

        // Skip if already a member
        if (qt_get_group_member_record($pdo, $groupId, $memberId)) {
            continue;
        }

        $insert->execute([$groupId, $memberId, QT_GROUP_ROLE_MEMBER]);
        $added++;

        if ($shouldSkipGroupHistory) {
            continue;
        }

        $memberNameStmt->execute([$memberId]);
        $memberDisplayName = trim((string)($memberNameStmt->fetchColumn() ?: '')) ?: ('User ' . $memberId);

        qt_log_history_event($pdo, [
            'actor_user_id' => $viewerId,
            'subject_user_id' => $memberId,
            'chat_type' => 'group',
            'chat_id' => $groupId,
            'event_type' => 'group_member_invited',
        ]);

        qt_insert_group_news_message($pdo, $groupId, $viewerId, [
            'type' => 'member_invited',
            'subject_name' => $memberDisplayName,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to add members right now.']);
    exit;
}

$details = qt_fetch_group_details($pdo, $viewerId, $groupId);
echo json_encode([
    'success' => true,
    'added' => $added,
    'group' => $details['group'] ?? null,
    'members' => $details['members'] ?? [],
], JSON_UNESCAPED_UNICODE);
