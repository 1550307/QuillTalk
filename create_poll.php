<?php
// Last updated: 2026-04-15 20:45:00 - Fixed friendship check
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/chat_push.php';
require __DIR__ . '/includes/poll_auth.php';
require __DIR__ . '/includes/history_events.php';

header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method'], 405);
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$input = qt_poll_json_input() ?? [];

// Log the input for debugging
error_log('Create poll input: ' . json_encode($input));

$chatTarget = qt_parse_chat_target($input['chat_with'] ?? '');
$chatWith = $chatTarget['key'];
$title = trim($input['title'] ?? '');
$options = $input['options'] ?? [];
$rawEndDate = $input['end_date'] ?? null;
$endDate = qt_poll_normalize_input_datetime($rawEndDate);
$endResponses = isset($input['end_responses']) ? (int)$input['end_responses'] : null;

if ($chatTarget['type'] === 'unknown' || !$title || empty($options)) {
    respond(['success' => false, 'error' => 'Missing required fields', 'debug' => [
        'chat_with' => $chatWith,
        'chat_type' => $chatTarget['type'] ?? 'unknown',
        'title' => $title,
        'options_count' => count($options)
    ]], 400);
}

if (count($options) < 2) {
    respond(['success' => false, 'error' => 'Poll must have at least 2 options'], 400);
}

if (!qt_poll_is_blank_datetime($rawEndDate) && $endDate === null) {
    respond(['success' => false, 'error' => 'Invalid poll end date'], 400);
}

if ($endDate !== null && qt_poll_has_expired($endDate)) {
    respond(['success' => false, 'error' => 'Poll end date must be in the future'], 400);
}

if ($endResponses !== null && $endResponses <= 0) {
    $endResponses = null;
}

try {
    $pdo->beginTransaction();

    $isGroup = $chatTarget['type'] === 'group';
    $groupId = $isGroup ? (int)$chatTarget['id'] : null;
    $recipientId = $isGroup ? null : (int)$chatTarget['id'];

    if ($isGroup) {
        error_log("Group chat detected. Group ID: $groupId");

        $groupSendState = qt_get_group_send_state($pdo, $userId, (int)$groupId);
        if (empty($groupSendState['allowed'])) {
            $pdo->rollBack();
            respond([
                'success' => false,
                'error' => (string)($groupSendState['error'] ?? 'Group chat not found'),
                'muted_until' => $groupSendState['muted_until'] ?? null
            ], 403);
        }
    } else {
        error_log("Private chat detected. Recipient ID: $recipientId, Chat with value: '$chatWith'");
        
        if ($recipientId <= 0) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid recipient ID', 'debug' => [
                'chat_with' => $chatWith,
                'recipient_id' => $recipientId
            ]], 400);
        }
        
        // Verify friendship exists
        error_log("Checking friendship for user $userId and recipient $recipientId");
        $stmt = $pdo->prepare("SELECT 1 FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $stmt->execute([$userId, $recipientId, $recipientId, $userId]);
        $friendshipExists = $stmt->fetch();
        error_log("Friendship check result: " . ($friendshipExists ? 'true' : 'false'));
        if (!$friendshipExists) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Not friends with this user', 'debug' => [
                'user_id' => $userId,
                'recipient_id' => $recipientId,
                'chat_with' => $chatWith
            ]], 403);
        }

        $blockRelationship = qt_get_block_relationship($pdo, $userId, $recipientId);
        if (!empty($blockRelationship['viewer_has_blocked'])) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'You cannot send messages to users you have blocked.'], 403);
        }
        if (!empty($blockRelationship['blocked_viewer'])) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'You cannot send messages to users who have blocked you.'], 403);
        }
    }

    // Create the poll
    $stmt = $pdo->prepare("
        INSERT INTO polls (creator_id, group_id, recipient_id, title, end_date, end_responses, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $groupId, $recipientId, $title, $endDate, $endResponses]);
    $pollId = $pdo->lastInsertId();

    // Create poll options
    foreach ($options as $index => $option) {
        $optionText = trim($option['text'] ?? '');
        $optionImage = $option['image'] ?? null;
        
        if (!$optionText) continue;

        $stmt = $pdo->prepare("
            INSERT INTO poll_options (poll_id, option_index, option_text, option_image)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$pollId, $index, $optionText, $optionImage]);
    }

    // Create the message
    $pollMessage = '__POLL__:' . json_encode(['poll_id' => $pollId]);
    
    if ($isGroup) {
        $stmt = $pdo->prepare("
            INSERT INTO group_messages (group_id, sender_id, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$groupId, $userId, $pollMessage]);
        $messageId = $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, recipient_id, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $recipientId, $pollMessage]);
        $messageId = $pdo->lastInsertId();
    }

    $pdo->commit();

    qt_log_history_event($pdo, [
        'actor_user_id' => $userId,
        'chat_type' => $isGroup ? 'group' : 'direct',
        'chat_id' => $isGroup ? (int)$groupId : (int)$recipientId,
        'event_type' => 'poll_created',
        'event_value' => $title,
    ]);

    try {
        $senderStmt = $pdo->prepare("
            SELECT
                username,
                COALESCE(NULLIF(display_name, ''), username) AS display_name,
                COALESCE(NULLIF(profile_pic, ''), 'images/default-profile.png') AS profile_pic
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $senderStmt->execute([$userId]);
        $sender = $senderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $senderDisplayName = trim((string)($sender['display_name'] ?? $sender['username'] ?? ''));
        $fullIconUrl = 'https://quilltalk.org/' . ltrim((string)($sender['profile_pic'] ?? 'images/default-profile.png'), '/');

        if ($isGroup) {
            $groupStmt = $pdo->prepare("
                SELECT COALESCE(NULLIF(name, ''), CONCAT('Group ', id)) AS group_name
                FROM chat_groups
                WHERE id = ?
                LIMIT 1
            ");
            $groupStmt->execute([(int)$groupId]);
            $groupName = (string)($groupStmt->fetchColumn() ?: ('Group ' . (int)$groupId));

            $memberStmt = $pdo->prepare("
                SELECT
                    member.user_id,
                    COALESCE(NULLIF(pref.notify_mode, ''), ?) AS notification_mode
                FROM chat_group_members member
                LEFT JOIN chat_notification_preferences pref
                    ON pref.user_id = member.user_id
                   AND pref.chat_type = 'group'
                   AND pref.chat_id = member.group_id
                WHERE member.group_id = ?
                  AND member.user_id <> ?
            ");
            $memberStmt->execute([QT_CHAT_NOTIFY_ALL, (int)$groupId, $userId]);
            $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($members as $member) {
                $mode = qt_normalize_chat_notification_mode((string)($member['notification_mode'] ?? QT_CHAT_NOTIFY_ALL));
                if ($mode === QT_CHAT_NOTIFY_MENTION) {
                    continue;
                }

                $payload = qt_chat_push_build_group_payload(
                    $pollMessage,
                    $senderDisplayName !== '' ? $senderDisplayName : 'Someone',
                    $groupName,
                    $fullIconUrl
                );
                qt_chat_push_send_to_user($pdo, (int)($member['user_id'] ?? 0), $payload);
            }
        } else {
            $mode = qt_get_chat_notification_mode($pdo, (int)$recipientId, 'direct', $userId);
            if ($mode !== QT_CHAT_NOTIFY_MENTION) {
                $payload = qt_chat_push_build_direct_payload(
                    $pollMessage,
                    $senderDisplayName !== '' ? $senderDisplayName : 'QuillTalk',
                    $fullIconUrl
                );
                qt_chat_push_send_to_user($pdo, (int)$recipientId, $payload);
            }
        }
    } catch (Throwable $pushError) {
        error_log('Create poll push error: ' . $pushError->getMessage());
    }

    respond([
        'success' => true,
        'poll_id' => $pollId,
        'message_id' => $messageId
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Create poll error: ' . $e->getMessage());
    error_log('Create poll trace: ' . $e->getTraceAsString());
    respond(['success' => false, 'error' => 'Failed to create poll', 'debug' => $e->getMessage()], 500);
}
