<?php
declare(strict_types=1);

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/game_debug.log');
ini_set('display_errors', '0');

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/chat_push.php';
require __DIR__ . '/includes/poll_auth.php';
require_once __DIR__ . '/includes/games.php';

header('Content-Type: application/json');

function respond(array $data, int $code = 200): void
{
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
$chatTarget = qt_parse_chat_target($input['chat_with'] ?? '');
$gameType = strtolower(trim((string)($input['game_type'] ?? '')));
$gameOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
$opponentMode = qt_game_normalize_opponent_mode($gameOptions['opponent_mode'] ?? ($input['opponent_mode'] ?? ''));
$colorPreference = qt_game_normalize_color_preference($gameOptions['color_choice'] ?? ($input['color_choice'] ?? ''));
$timeControlSeconds = qt_game_normalize_time_control_seconds($gameOptions['time_control_seconds'] ?? ($input['time_control_seconds'] ?? QT_GAME_DEFAULT_TIME_CONTROL_SECONDS));

error_log('Create game input: ' . json_encode($input));
error_log('Create game parsed target: ' . json_encode($chatTarget));
error_log('Create game parsed type: ' . $gameType);

if ($chatTarget['type'] === 'unknown' || !qt_game_is_supported_type($gameType)) {
    respond(['success' => false, 'error' => 'Unsupported game request'], 400);
}

if (!qt_game_supports_bot($gameType)) {
    $opponentMode = QT_GAME_OPPONENT_HUMAN;
}
if ($gameType === QT_GAME_TYPE_SKETCHOFF) {
    $timeControlSeconds = QT_SKETCHOFF_ROUND_SECONDS;
}

$initialState = qt_game_initial_state_for_type($gameType);
if ($initialState === null) {
    respond(['success' => false, 'error' => 'Game setup is unavailable'], 400);
}

try {
    $pdo->beginTransaction();

    $isGroup = $chatTarget['type'] === 'group';
    $groupId = $isGroup ? (int)$chatTarget['id'] : null;
    $recipientId = $isGroup ? null : (int)$chatTarget['id'];

    if ($isGroup) {
        error_log("Create game group chat detected. Group ID: $groupId");
        $groupSendState = qt_get_group_send_state($pdo, $userId, (int)$groupId);
        if (empty($groupSendState['allowed'])) {
            $pdo->rollBack();
            respond([
                'success' => false,
                'error' => (string)($groupSendState['error'] ?? 'Group chat not found'),
                'muted_until' => $groupSendState['muted_until'] ?? null,
            ], 403);
        }
    } else {
        error_log("Create game direct chat detected. Recipient ID: $recipientId");
        if ($recipientId === null || $recipientId <= 0) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid recipient'], 400);
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM friends
            WHERE (user_id = ? AND friend_id = ?)
               OR (user_id = ? AND friend_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$userId, $recipientId, $recipientId, $userId]);
        $friendshipExists = (bool)$stmt->fetchColumn();
        error_log('Create game friendship check result: ' . ($friendshipExists ? 'true' : 'false'));
        if (!$friendshipExists) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Not friends with this user'], 403);
        }

        $blockRelationship = qt_get_block_relationship($pdo, $userId, $recipientId);
        if (!empty($blockRelationship['viewer_has_blocked'])) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'You cannot start games with users you have blocked.'], 403);
        }
        if (!empty($blockRelationship['blocked_viewer'])) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'You cannot start games with users who have blocked you.'], 403);
        }
    }

    $statePayload = json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($statePayload) || $statePayload === '') {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Failed to prepare game state'], 500);
    }

    $creatorColor = qt_game_resolve_creator_color($colorPreference);
    $opponentColor = qt_game_opponent_color($creatorColor);
    $botEnabled = $opponentMode === QT_GAME_OPPONENT_BOT;

    $whiteUserId = 0;
    $blackUserId = 0;
    if ($creatorColor === 'w') {
        $whiteUserId = $userId;
        if (!$botEnabled && !$isGroup && $recipientId !== null && $recipientId > 0) {
            $blackUserId = $recipientId;
        }
    } else {
        $blackUserId = $userId;
        if (!$botEnabled && !$isGroup && $recipientId !== null && $recipientId > 0) {
            $whiteUserId = $recipientId;
        }
    }

    $gameStatus = $botEnabled ? QT_GAME_STATUS_ACTIVE : QT_GAME_STATUS_WAITING;
    $initialClockMs = $timeControlSeconds * 1000;
    $startedAt = $gameStatus === QT_GAME_STATUS_ACTIVE ? date('Y-m-d H:i:s') : null;
    $turnStartedAt = $gameStatus === QT_GAME_STATUS_ACTIVE ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("
        INSERT INTO chat_games (
            game_type,
            creator_user_id,
            white_user_id,
            black_user_id,
            group_id,
            recipient_id,
            bot_enabled,
            bot_color,
            time_control_seconds,
            white_time_ms,
            black_time_ms,
            turn_started_at,
            status,
            state_payload,
            created_at,
            updated_at,
            started_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
    ");
    error_log('Create game inserting into chat_games');
    $stmt->execute([
        $gameType,
        $userId,
        $whiteUserId,
        $blackUserId,
        $groupId,
        $recipientId,
        $botEnabled ? 1 : 0,
        $botEnabled ? $opponentColor : null,
        $timeControlSeconds,
        $initialClockMs,
        $initialClockMs,
        $turnStartedAt,
        $gameStatus,
        $statePayload,
        $startedAt,
    ]);
    $gameId = (int)$pdo->lastInsertId();

    $gameMessage = qt_build_game_message($gameId, $gameType);

    if ($isGroup) {
        $stmt = $pdo->prepare("
            INSERT INTO group_messages (group_id, sender_id, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([(int)$groupId, $userId, $gameMessage]);
        $messageId = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, recipient_id, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, (int)$recipientId, $gameMessage]);
        $messageId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
    error_log("Create game committed successfully. Game ID: $gameId Message ID: $messageId");

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
                    $gameMessage,
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
                    $gameMessage,
                    $senderDisplayName !== '' ? $senderDisplayName : 'QuillTalk',
                    $fullIconUrl
                );
                qt_chat_push_send_to_user($pdo, (int)$recipientId, $payload);
            }
        }
    } catch (Throwable $pushError) {
        error_log('Create game push error: ' . $pushError->getMessage());
    }

    respond([
        'success' => true,
        'game_id' => $gameId,
        'message_id' => $messageId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Create game error: ' . $e->getMessage());
    error_log('Create game trace: ' . $e->getTraceAsString());
    respond(['success' => false, 'error' => 'Failed to create game', 'debug' => $e->getMessage()], 500);
}
