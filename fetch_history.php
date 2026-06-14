<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/history_events.php';

function qt_history_name(array $row, string $displayKey, string $usernameKey, string $fallback = 'User'): string
{
    $display = trim((string)($row[$displayKey] ?? ''));
    if ($display !== '') {
        return $display;
    }

    $username = trim((string)($row[$usernameKey] ?? ''));
    if ($username !== '') {
        return $username;
    }

    return $fallback;
}

function qt_history_push(array &$items, array $item): void
{
    $items[] = $item;
}

function qt_history_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
        return (bool)($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function qt_history_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing token']);
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
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

try {
    $pdo->exec("ALTER TABLE friend_requests ADD COLUMN responded_at DATETIME NULL DEFAULT NULL AFTER created_at");
} catch (Throwable $e) {
    // Column already exists or the table schema is managed elsewhere.
}

$friendRequestsHasRespondedAt = false;
try {
    $friendRequestColumnStmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'friend_requests'
          AND COLUMN_NAME = 'responded_at'
    ");
    $friendRequestColumnStmt->execute();
    $friendRequestsHasRespondedAt = (int)($friendRequestColumnStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
} catch (Throwable $e) {
    $friendRequestsHasRespondedAt = false;
}

$hasMessageVisibilityTable = qt_history_table_exists($pdo, 'message_visibility');
$hasMessageReactionsTable = qt_history_table_exists($pdo, 'message_reactions');
$hasFriendRequestsTable = qt_history_table_exists($pdo, 'friend_requests');
$hasUserHistoryEventsTable = qt_history_table_exists($pdo, 'user_history_events');
$messagesHasReplyToId = qt_history_column_exists($pdo, 'messages', 'reply_to_id');
$groupMessagesHasReplyToId = qt_history_column_exists($pdo, 'group_messages', 'reply_to_id');
$messagesHasIsAiResponse = qt_history_column_exists($pdo, 'messages', 'is_ai_response');

$directVisibilityClause = $hasMessageVisibilityTable ? "
          AND NOT EXISTS (
                SELECT 1
                FROM message_visibility mv
                WHERE mv.user_id = {$userId}
                  AND mv.message_type = 'messages'
                  AND mv.message_id = m.id
          )" : '';

$groupVisibilityClause = $hasMessageVisibilityTable ? "
          AND NOT EXISTS (
                SELECT 1
                FROM message_visibility mv
                WHERE mv.user_id = {$userId}
                  AND mv.message_type = 'group_messages'
                  AND mv.message_id = gm.id
          )" : '';

$directIsAiResponseClause = $messagesHasIsAiResponse
    ? "
          AND COALESCE(m.is_ai_response, 0) = 0"
    : '';

$items = [];

try {
    $sentDirectMessagesStmt = $pdo->prepare("
        SELECT
            m.id,
            m.message,
            m.created_at,
            recipient.id AS conversation_id,
            COALESCE(NULLIF(recipient.display_name, ''), recipient.username) AS conversation_name,
            recipient.username AS conversation_username
        FROM messages m
        JOIN users recipient
            ON recipient.id = m.recipient_id
        WHERE m.sender_id = :me_sender
{$directIsAiResponseClause}
          AND m.message NOT LIKE '__CALL_EVENT__:%'
{$directVisibilityClause}
        ORDER BY m.created_at DESC, m.id DESC
    ");
    $sentDirectMessagesStmt->execute([
        ':me_sender' => $userId,
    ]);

    foreach ($sentDirectMessagesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        qt_history_push($items, [
            'id' => 'direct-message-' . (int)$row['id'],
            'kind' => 'message',
            'filter_group' => 'messages',
            'created_at' => (string)($row['created_at'] ?? ''),
            'conversation_type' => 'direct',
            'conversation_id' => (int)($row['conversation_id'] ?? 0),
            'conversation_name' => qt_history_name($row, 'conversation_name', 'conversation_username', 'Contact'),
            'raw_message' => (string)($row['message'] ?? ''),
            'message_id' => (int)($row['id'] ?? 0),
        ]);
    }

    $sentGroupMessagesStmt = $pdo->prepare("
        SELECT
            gm.id,
            gm.message,
            gm.created_at,
            g.id AS conversation_id,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS conversation_name
        FROM group_messages gm
        JOIN chat_groups g
            ON g.id = gm.group_id
        JOIN chat_group_members viewer_member
            ON viewer_member.group_id = gm.group_id
           AND viewer_member.user_id = :me_member
        WHERE gm.sender_id = :me_sender
          AND gm.message NOT LIKE '__GROUP_CALL__:%'
          AND gm.message NOT LIKE '" . QT_GROUP_NEWS_MESSAGE_PREFIX . "%'
{$groupVisibilityClause}
        ORDER BY gm.created_at DESC, gm.id DESC
    ");
    $sentGroupMessagesStmt->execute([
        ':me_member' => $userId,
        ':me_sender' => $userId,
    ]);

    foreach ($sentGroupMessagesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        qt_history_push($items, [
            'id' => 'group-message-' . (int)$row['id'],
            'kind' => 'message',
            'filter_group' => 'messages',
            'created_at' => (string)($row['created_at'] ?? ''),
            'conversation_type' => 'group',
            'conversation_id' => (int)($row['conversation_id'] ?? 0),
            'conversation_name' => trim((string)($row['conversation_name'] ?? '')) !== ''
                ? (string)$row['conversation_name']
                : 'Group',
            'raw_message' => (string)($row['message'] ?? ''),
            'message_id' => (int)($row['id'] ?? 0),
        ]);
    }

    $callHistoryStmt = $pdo->prepare("
        SELECT
            m.id,
            m.message,
            m.created_at,
            m.sender_id,
            m.recipient_id,
            counterpart.id AS counterpart_id,
            COALESCE(NULLIF(counterpart.display_name, ''), counterpart.username) AS counterpart_name,
            counterpart.username AS counterpart_username
        FROM messages m
        JOIN users counterpart
            ON counterpart.id = CASE
                WHEN m.sender_id = :me_counterpart
                    THEN m.recipient_id
                ELSE m.sender_id
            END
        WHERE (m.sender_id = :me_sender OR m.recipient_id = :me_recipient)
          AND m.message LIKE '__CALL_EVENT__:%'
{$directVisibilityClause}
        ORDER BY m.created_at DESC, m.id DESC
    ");
    $callHistoryStmt->execute([
        ':me_counterpart' => $userId,
        ':me_sender' => $userId,
        ':me_recipient' => $userId,
    ]);

    foreach ($callHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rawMessage = (string)($row['message'] ?? '');
        $callPayload = '';
        if (str_starts_with($rawMessage, '__CALL_EVENT__:')) {
            $callPayload = substr($rawMessage, strlen('__CALL_EVENT__:'));
        }
        [$callType, $callerIdRaw] = array_pad(explode('|', $callPayload, 2), 2, '');

        qt_history_push($items, [
            'id' => 'call-' . (int)$row['id'],
            'kind' => 'call',
            'filter_group' => 'calls',
            'created_at' => (string)($row['created_at'] ?? ''),
            'counterpart_id' => (int)($row['counterpart_id'] ?? 0),
            'counterpart_name' => qt_history_name($row, 'counterpart_name', 'counterpart_username', 'Contact'),
            'call_type' => trim($callType) !== '' ? trim($callType) : 'ended',
            'caller_id' => (int)$callerIdRaw,
            'sender_id' => (int)($row['sender_id'] ?? 0),
            'recipient_id' => (int)($row['recipient_id'] ?? 0),
        ]);
    }

    if ($hasMessageReactionsTable) {
    $directReactionHistoryStmt = $pdo->prepare("
        SELECT
            m.id AS message_id,
            MAX(mr.created_at) AS created_at,
            mr.user_id AS actor_id,
            COALESCE(NULLIF(actor.display_name, ''), actor.username) AS actor_name,
            actor.username AS actor_username,
            m.sender_id AS message_owner_id,
            COALESCE(NULLIF(owner.display_name, ''), owner.username) AS message_owner_name,
            owner.username AS message_owner_username,
            counterpart.id AS conversation_id,
            COALESCE(NULLIF(counterpart.display_name, ''), counterpart.username) AS conversation_name,
            counterpart.username AS conversation_username,
            GROUP_CONCAT(mr.emoji ORDER BY mr.created_at ASC SEPARATOR '||') AS emojis,
            m.message AS target_message
        FROM message_reactions mr
        JOIN messages m
            ON mr.message_type = 'direct'
           AND mr.message_id = m.id
        JOIN users actor
            ON actor.id = mr.user_id
        JOIN users owner
            ON owner.id = m.sender_id
        JOIN users counterpart
            ON counterpart.id = CASE
                WHEN m.sender_id = :me_counterpart
                    THEN m.recipient_id
                ELSE m.sender_id
            END
        WHERE (m.sender_id = :me_sender OR m.recipient_id = :me_recipient)
{$directVisibilityClause}
          AND (
                (mr.user_id = :me_actor AND m.sender_id <> :me_actor_sender)
                OR
                (m.sender_id = :me_owner AND mr.user_id <> :me_other_actor)
          )
        GROUP BY
            m.id,
            mr.user_id,
            actor.display_name,
            actor.username,
            m.sender_id,
            owner.display_name,
            owner.username,
            counterpart.id,
            counterpart.display_name,
            counterpart.username,
            m.message
        ORDER BY MAX(mr.created_at) DESC, m.id DESC
    ");
    $directReactionHistoryStmt->execute([
        ':me_counterpart' => $userId,
        ':me_sender' => $userId,
        ':me_recipient' => $userId,
        ':me_actor' => $userId,
        ':me_actor_sender' => $userId,
        ':me_owner' => $userId,
        ':me_other_actor' => $userId,
    ]);

    foreach ($directReactionHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $emojiParts = array_values(array_filter(
            array_map('trim', explode('||', (string)($row['emojis'] ?? ''))),
            static fn(string $emoji): bool => $emoji !== ''
        ));

        qt_history_push($items, [
            'id' => 'direct-reaction-' . (int)$row['message_id'] . '-' . (int)$row['actor_id'],
            'kind' => 'reaction',
            'filter_group' => 'other',
            'created_at' => (string)($row['created_at'] ?? ''),
            'conversation_type' => 'direct',
            'conversation_id' => (int)($row['conversation_id'] ?? 0),
            'conversation_name' => qt_history_name($row, 'conversation_name', 'conversation_username', 'Contact'),
            'actor_id' => (int)($row['actor_id'] ?? 0),
            'actor_name' => qt_history_name($row, 'actor_name', 'actor_username', 'User'),
            'message_owner_id' => (int)($row['message_owner_id'] ?? 0),
            'message_owner_name' => qt_history_name($row, 'message_owner_name', 'message_owner_username', 'User'),
            'target_message' => (string)($row['target_message'] ?? ''),
            'emojis' => $emojiParts,
        ]);
    }
    }

    if ($hasMessageReactionsTable) {
    $groupReactionHistoryStmt = $pdo->prepare("
        SELECT
            gm.id AS message_id,
            MAX(mr.created_at) AS created_at,
            mr.user_id AS actor_id,
            COALESCE(NULLIF(actor.display_name, ''), actor.username) AS actor_name,
            actor.username AS actor_username,
            gm.sender_id AS message_owner_id,
            COALESCE(NULLIF(owner.display_name, ''), owner.username) AS message_owner_name,
            owner.username AS message_owner_username,
            g.id AS conversation_id,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS conversation_name,
            GROUP_CONCAT(mr.emoji ORDER BY mr.created_at ASC SEPARATOR '||') AS emojis,
            gm.message AS target_message
        FROM message_reactions mr
        JOIN group_messages gm
            ON mr.message_type = 'group'
           AND mr.message_id = gm.id
        JOIN chat_groups g
            ON g.id = gm.group_id
        JOIN chat_group_members viewer_member
            ON viewer_member.group_id = gm.group_id
           AND viewer_member.user_id = :me_member
        JOIN users actor
            ON actor.id = mr.user_id
        JOIN users owner
            ON owner.id = gm.sender_id
        WHERE 1 = 1
{$groupVisibilityClause}
          AND (
                (mr.user_id = :me_actor AND gm.sender_id <> :me_actor_sender)
                OR
                (gm.sender_id = :me_owner AND mr.user_id <> :me_other_actor)
          )
        GROUP BY
            gm.id,
            mr.user_id,
            actor.display_name,
            actor.username,
            gm.sender_id,
            owner.display_name,
            owner.username,
            g.id,
            g.name,
            gm.message
        ORDER BY MAX(mr.created_at) DESC, gm.id DESC
    ");
    $groupReactionHistoryStmt->execute([
        ':me_member' => $userId,
        ':me_actor' => $userId,
        ':me_actor_sender' => $userId,
        ':me_owner' => $userId,
        ':me_other_actor' => $userId,
    ]);

    foreach ($groupReactionHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $emojiParts = array_values(array_filter(
            array_map('trim', explode('||', (string)($row['emojis'] ?? ''))),
            static fn(string $emoji): bool => $emoji !== ''
        ));

        qt_history_push($items, [
            'id' => 'group-reaction-' . (int)$row['message_id'] . '-' . (int)$row['actor_id'],
            'kind' => 'reaction',
            'filter_group' => 'other',
            'created_at' => (string)($row['created_at'] ?? ''),
            'conversation_type' => 'group',
            'conversation_id' => (int)($row['conversation_id'] ?? 0),
            'conversation_name' => trim((string)($row['conversation_name'] ?? '')) !== ''
                ? (string)$row['conversation_name']
                : 'Group',
            'actor_id' => (int)($row['actor_id'] ?? 0),
            'actor_name' => qt_history_name($row, 'actor_name', 'actor_username', 'User'),
            'message_owner_id' => (int)($row['message_owner_id'] ?? 0),
            'message_owner_name' => qt_history_name($row, 'message_owner_name', 'message_owner_username', 'User'),
            'target_message' => (string)($row['target_message'] ?? ''),
            'emojis' => $emojiParts,
        ]);
    }
    }

    if ($messagesHasReplyToId) {
    $directReplyHistoryStmt = $pdo->prepare("
        SELECT
            m.id AS message_id,
            m.created_at,
            m.sender_id AS actor_id,
            COALESCE(NULLIF(actor.display_name, ''), actor.username) AS actor_name,
            actor.username AS actor_username,
            rm.sender_id AS target_owner_id,
            COALESCE(NULLIF(owner.display_name, ''), owner.username) AS target_owner_name,
            owner.username AS target_owner_username,
            counterpart.id AS conversation_id,
            COALESCE(NULLIF(counterpart.display_name, ''), counterpart.username) AS conversation_name,
            counterpart.username AS conversation_username,
            rm.message AS target_message
        FROM messages m
        JOIN messages rm
            ON m.reply_to_id = rm.id
           AND (
                (rm.sender_id = m.sender_id AND rm.recipient_id = m.recipient_id)
                OR
                (rm.sender_id = m.recipient_id AND rm.recipient_id = m.sender_id)
           )
        JOIN users actor
            ON actor.id = m.sender_id
        JOIN users owner
            ON owner.id = rm.sender_id
        JOIN users counterpart
            ON counterpart.id = CASE
                WHEN m.sender_id = :me_reply_counterpart
                    THEN m.recipient_id
                ELSE m.sender_id
            END
        WHERE (m.sender_id = :me_reply_sender OR m.recipient_id = :me_reply_recipient)
{$directIsAiResponseClause}
          AND m.reply_to_id IS NOT NULL
{$directVisibilityClause}
          AND (
                (m.sender_id = :me_reply_actor AND rm.sender_id <> :me_reply_actor_sender)
                OR
                (rm.sender_id = :me_reply_owner AND m.sender_id <> :me_reply_other_actor)
          )
        ORDER BY m.created_at DESC, m.id DESC
    ");
    $directReplyHistoryStmt->execute([
        ':me_reply_counterpart' => $userId,
        ':me_reply_sender' => $userId,
        ':me_reply_recipient' => $userId,
        ':me_reply_actor' => $userId,
        ':me_reply_actor_sender' => $userId,
        ':me_reply_owner' => $userId,
        ':me_reply_other_actor' => $userId,
    ]);

    foreach ($directReplyHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        qt_history_push($items, [
            'id' => 'direct-reply-' . (int)$row['message_id'],
            'kind' => 'reply',
            'filter_group' => 'other',
            'created_at' => (string)($row['created_at'] ?? ''),
            'conversation_type' => 'direct',
            'conversation_id' => (int)($row['conversation_id'] ?? 0),
            'conversation_name' => qt_history_name($row, 'conversation_name', 'conversation_username', 'Contact'),
            'actor_id' => (int)($row['actor_id'] ?? 0),
            'actor_name' => qt_history_name($row, 'actor_name', 'actor_username', 'User'),
            'target_owner_id' => (int)($row['target_owner_id'] ?? 0),
            'target_owner_name' => qt_history_name($row, 'target_owner_name', 'target_owner_username', 'User'),
            'target_message' => (string)($row['target_message'] ?? ''),
        ]);
    }
    }

    if ($groupMessagesHasReplyToId) {
    $groupReplyHistoryStmt = $pdo->prepare("
        SELECT
            gm.id AS message_id,
            gm.created_at,
            gm.sender_id AS actor_id,
            COALESCE(NULLIF(actor.display_name, ''), actor.username) AS actor_name,
            actor.username AS actor_username,
            rm.sender_id AS target_owner_id,
            COALESCE(NULLIF(owner.display_name, ''), owner.username) AS target_owner_name,
            owner.username AS target_owner_username,
            g.id AS conversation_id,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS conversation_name,
            rm.message AS target_message
        FROM group_messages gm
        JOIN group_messages rm
            ON gm.reply_to_id = rm.id
           AND rm.group_id = gm.group_id
        JOIN chat_groups g
            ON g.id = gm.group_id
        JOIN chat_group_members viewer_member
            ON viewer_member.group_id = gm.group_id
           AND viewer_member.user_id = :me_group_reply_member
        JOIN users actor
            ON actor.id = gm.sender_id
        JOIN users owner
            ON owner.id = rm.sender_id
        WHERE gm.reply_to_id IS NOT NULL
{$groupVisibilityClause}
          AND (
                (gm.sender_id = :me_group_reply_actor AND rm.sender_id <> :me_group_reply_actor_sender)
                OR
                (rm.sender_id = :me_group_reply_owner AND gm.sender_id <> :me_group_reply_other_actor)
          )
        ORDER BY gm.created_at DESC, gm.id DESC
    ");
    $groupReplyHistoryStmt->execute([
        ':me_group_reply_member' => $userId,
        ':me_group_reply_actor' => $userId,
        ':me_group_reply_actor_sender' => $userId,
        ':me_group_reply_owner' => $userId,
        ':me_group_reply_other_actor' => $userId,
    ]);

    foreach ($groupReplyHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        qt_history_push($items, [
            'id' => 'group-reply-' . (int)$row['message_id'],
            'kind' => 'reply',
            'filter_group' => 'other',
            'created_at' => (string)($row['created_at'] ?? ''),
            'conversation_type' => 'group',
            'conversation_id' => (int)($row['conversation_id'] ?? 0),
            'conversation_name' => trim((string)($row['conversation_name'] ?? '')) !== ''
                ? (string)$row['conversation_name']
                : 'Group',
            'actor_id' => (int)($row['actor_id'] ?? 0),
            'actor_name' => qt_history_name($row, 'actor_name', 'actor_username', 'User'),
            'target_owner_id' => (int)($row['target_owner_id'] ?? 0),
            'target_owner_name' => qt_history_name($row, 'target_owner_name', 'target_owner_username', 'User'),
            'target_message' => (string)($row['target_message'] ?? ''),
        ]);
    }
    }

    if ($hasFriendRequestsTable) {
    $friendRequestRespondedAtSelect = $friendRequestsHasRespondedAt
        ? 'fr.responded_at'
        : 'NULL AS responded_at';
    $friendRequestOrderExpression = $friendRequestsHasRespondedAt
        ? 'COALESCE(fr.responded_at, fr.created_at)'
        : 'fr.created_at';

    $friendRequestHistoryStmt = $pdo->prepare("
        SELECT
            fr.id,
            fr.sender_id,
            fr.receiver_id,
            fr.status,
            fr.created_at,
            {$friendRequestRespondedAtSelect},
            COALESCE(NULLIF(sender.display_name, ''), sender.username) AS sender_name,
            sender.username AS sender_username,
            COALESCE(NULLIF(receiver.display_name, ''), receiver.username) AS receiver_name,
            receiver.username AS receiver_username
        FROM friend_requests fr
        JOIN users sender
            ON sender.id = fr.sender_id
        JOIN users receiver
            ON receiver.id = fr.receiver_id
        WHERE fr.sender_id = :me_sender
           OR fr.receiver_id = :me_receiver
        ORDER BY {$friendRequestOrderExpression} DESC, fr.id DESC
    ");
    $friendRequestHistoryStmt->execute([
        ':me_sender' => $userId,
        ':me_receiver' => $userId,
    ]);

    foreach ($friendRequestHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $role = (int)($row['sender_id'] ?? 0) === $userId ? 'sender' : 'receiver';
        $status = trim((string)($row['status'] ?? 'pending')) ?: 'pending';
        $eventTimestamp = $status === 'pending'
            ? (string)($row['created_at'] ?? '')
            : (string)($row['responded_at'] ?? $row['created_at'] ?? '');

        qt_history_push($items, [
            'id' => 'friend-request-' . (int)$row['id'],
            'kind' => 'friend_request',
            'filter_group' => 'other',
            'created_at' => $eventTimestamp,
            'request_status' => $status,
            'request_role' => $role,
            'counterpart_id' => $role === 'sender'
                ? (int)($row['receiver_id'] ?? 0)
                : (int)($row['sender_id'] ?? 0),
            'counterpart_name' => $role === 'sender'
                ? qt_history_name($row, 'receiver_name', 'receiver_username', 'Contact')
                : qt_history_name($row, 'sender_name', 'sender_username', 'Contact'),
        ]);
    }
    }

    if ($hasUserHistoryEventsTable) {
    $historyEventsStmt = $pdo->prepare("
        SELECT
            he.id,
            he.event_type,
            he.event_value,
            he.created_at,
            he.actor_user_id,
            COALESCE(NULLIF(actor.display_name, ''), actor.username) AS actor_name,
            actor.username AS actor_username,
            he.subject_user_id,
            COALESCE(NULLIF(subject_user.display_name, ''), subject_user.username) AS subject_name,
            subject_user.username AS subject_username,
            he.chat_type,
            he.chat_id,
            COALESCE(NULLIF(direct_user.display_name, ''), direct_user.username) AS direct_chat_name,
            direct_user.username AS direct_chat_username,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS group_chat_name
        FROM user_history_events he
        LEFT JOIN users actor
            ON actor.id = he.actor_user_id
        LEFT JOIN users subject_user
            ON subject_user.id = he.subject_user_id
        LEFT JOIN users direct_user
            ON he.chat_type = 'direct'
           AND direct_user.id = he.chat_id
        LEFT JOIN chat_groups g
            ON he.chat_type = 'group'
           AND g.id = he.chat_id
        WHERE he.actor_user_id = :me_event_actor
           OR he.subject_user_id = :me_event_subject
        ORDER BY he.created_at DESC, he.id DESC
    ");
    $historyEventsStmt->execute([
        ':me_event_actor' => $userId,
        ':me_event_subject' => $userId,
    ]);

    foreach ($historyEventsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conversationType = in_array((string)($row['chat_type'] ?? ''), ['direct', 'group'], true)
            ? (string)$row['chat_type']
            : '';
        $conversationName = '';

        if ($conversationType === 'direct') {
            $conversationName = qt_history_name($row, 'direct_chat_name', 'direct_chat_username', 'Contact');
        } elseif ($conversationType === 'group') {
            $conversationName = trim((string)($row['group_chat_name'] ?? '')) !== ''
                ? (string)$row['group_chat_name']
                : 'Group';
        }

        qt_history_push($items, [
            'id' => 'event-' . (int)$row['id'],
            'kind' => 'event',
            'filter_group' => 'other',
            'created_at' => (string)($row['created_at'] ?? ''),
            'event_type' => (string)($row['event_type'] ?? ''),
            'event_value' => (string)($row['event_value'] ?? ''),
            'actor_id' => (int)($row['actor_user_id'] ?? 0),
            'actor_name' => qt_history_name($row, 'actor_name', 'actor_username', 'User'),
            'subject_id' => (int)($row['subject_user_id'] ?? 0),
            'subject_name' => qt_history_name($row, 'subject_name', 'subject_username', 'User'),
            'conversation_type' => $conversationType,
            'conversation_id' => $conversationType !== ''
                ? (int)($row['chat_id'] ?? 0)
                : 0,
            'conversation_name' => $conversationName,
        ]);
    }
    }

    usort($items, static function (array $left, array $right): int {
        $leftTs = strtotime((string)($left['created_at'] ?? '')) ?: 0;
        $rightTs = strtotime((string)($right['created_at'] ?? '')) ?: 0;
        if ($leftTs !== $rightTs) {
            return $rightTs <=> $leftTs;
        }

        return strcmp((string)($right['id'] ?? ''), (string)($left['id'] ?? ''));
    });

    echo json_encode([
        'success' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[fetch_history] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load history right now.',
    ], JSON_UNESCAPED_UNICODE);
}
