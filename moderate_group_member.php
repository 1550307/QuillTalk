<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST ?: [];
}
$token = trim((string)($data['token'] ?? ''));
$groupId = (int)($data['group_id'] ?? 0);
$memberId = (int)($data['member_id'] ?? 0);
$action = strtolower(trim((string)($data['action'] ?? '')));

if ($token === '' || $groupId <= 0 || $memberId <= 0 || $action === '') {
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

if (!qt_group_can_manage_member($viewerMember['role'] ?? '', $targetMember['role'] ?? '', $viewerId, $memberId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot moderate that member']);
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
$memberDisplayName = qt_history_fetch_user_display_name($pdo, $memberId, 'Member');
$timeoutMinutes = null;
$historyEventType = '';
$historyEventValue = null;
$newsPayload = null;

try {
    $pdo->beginTransaction();

    switch ($action) {
        case 'timeout':
        case 'timeout_custom':
            $timeoutMinutes = max(1, min(365 * 24 * 60, (int)($data['minutes'] ?? 15)));
            $stmt = $pdo->prepare("
                UPDATE chat_group_members
                SET muted_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$timeoutMinutes, $groupId, $memberId]);
            break;

        case 'timeout_15m':
            $timeoutMinutes = 15;
            $stmt = $pdo->prepare("
                UPDATE chat_group_members
                SET muted_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $memberId]);
            break;

        case 'timeout_1h':
            $timeoutMinutes = 60;
            $stmt = $pdo->prepare("
                UPDATE chat_group_members
                SET muted_until = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $memberId]);
            break;

        case 'timeout_24h':
            $timeoutMinutes = 1440;
            $stmt = $pdo->prepare("
                UPDATE chat_group_members
                SET muted_until = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $memberId]);
            break;

        case 'clear_timeout':
            $stmt = $pdo->prepare("
                UPDATE chat_group_members
                SET muted_until = NULL
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $memberId]);
            break;

        case 'ban':
            $stmt = $pdo->prepare("
                DELETE FROM chat_group_members
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $memberId]);
            $historyEventType = 'group_member_removed';
            $newsPayload = [
                'type' => 'member_removed',
                'subject_name' => $memberDisplayName,
            ];
            break;

        default:
            throw new RuntimeException('Unknown moderation action');
    }

    if ($timeoutMinutes !== null) {
        $timeoutLabel = qt_format_history_duration_minutes($timeoutMinutes);
        $historyEventType = 'group_member_timed_out';
        $historyEventValue = $timeoutLabel;
        $newsPayload = [
            'type' => 'member_timed_out',
            'subject_name' => $memberDisplayName,
            'duration_text' => $timeoutLabel,
        ];
    }

    if (!$shouldSkipGroupHistory && $historyEventType !== '') {
        qt_log_history_event($pdo, [
            'actor_user_id' => $viewerId,
            'subject_user_id' => $memberId,
            'chat_type' => 'group',
            'chat_id' => $groupId,
            'event_type' => $historyEventType,
            'event_value' => $historyEventValue,
        ]);

        if (is_array($newsPayload)) {
            qt_insert_group_news_message($pdo, $groupId, $viewerId, $newsPayload);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($e instanceof RuntimeException ? 400 : 500);
    echo json_encode([
        'success' => false,
        'error' => $e instanceof RuntimeException
            ? 'Unknown moderation action'
            : 'Unable to update that member right now.'
    ]);
    exit;
}

$details = qt_fetch_group_details($pdo, $viewerId, $groupId);
echo json_encode([
    'success' => true,
    'removed_member_id' => $action === 'ban' ? $memberId : null,
    'group' => $details['group'],
    'members' => $details['members'],
], JSON_UNESCAPED_UNICODE);
