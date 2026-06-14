<?php
declare(strict_types=1);

ob_start();
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/ai_image_debug.log');
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

$qtAiImageResponseSent = false;
register_shutdown_function(static function () use (&$qtAiImageResponseSent): void {
    $lastError = error_get_last();
    if ($lastError === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $message = trim((string)($lastError['message'] ?? 'Fatal error'));
    $file = trim((string)($lastError['file'] ?? ''));
    $line = (int)($lastError['line'] ?? 0);
    error_log('[AI IMAGE FATAL] ' . $message . ($file !== '' ? ' in ' . $file . ($line > 0 ? ':' . $line : '') : ''));

    if ($qtAiImageResponseSent || headers_sent()) {
        return;
    }

    http_response_code(500);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode([
        'success' => false,
        'error' => 'The AI image endpoint crashed before it could finish.',
        'fatal' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
require __DIR__ . '/includes/groups.php';
require_once __DIR__ . '/includes/ai_image.php';

function respond(array $data, int $status = 200): void
{
    global $qtAiImageResponseSent;
    $qtAiImageResponseSent = true;
    http_response_code($status);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_input(): array
{
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function require_session_user_id(PDO $pdo, string $token): int
{
    $stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        respond(['success' => false, 'error' => 'Invalid session'], 401);
    }
    return (int)($session['user_id'] ?? 0);
}

function ensure_direct_chat_access(PDO $pdo, int $viewerUserId, int $otherUserId): void
{
    if ($otherUserId <= 0) {
        respond(['success' => false, 'error' => 'Invalid recipient'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM friends
        WHERE (user_id = ? AND friend_id = ?)
           OR (user_id = ? AND friend_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$viewerUserId, $otherUserId, $otherUserId, $viewerUserId]);
    if (!$stmt->fetch()) {
        respond(['success' => false, 'error' => 'Not friends'], 403);
    }

    $blockRelationship = qt_get_block_relationship($pdo, $viewerUserId, $otherUserId);
    if (!empty($blockRelationship['viewer_has_blocked'])) {
        respond(['success' => false, 'error' => 'You cannot generate images in chats with users you have blocked.'], 403);
    }
    if (!empty($blockRelationship['blocked_viewer'])) {
        respond(['success' => false, 'error' => 'You cannot generate images in chats with users who have blocked you.'], 403);
    }
}

function ensure_group_chat_access(PDO $pdo, int $viewerUserId, int $groupId): void
{
    $groupSendState = qt_get_group_send_state($pdo, $viewerUserId, $groupId);
    if (empty($groupSendState['allowed'])) {
        respond([
            'success' => false,
            'error' => (string)($groupSendState['error'] ?? 'Group chat not found'),
            'muted_until' => $groupSendState['muted_until'] ?? null,
        ], 403);
    }
}

function build_public_ai_direct_message(PDO $pdo, array $row, int $viewerUserId): array
{
    $recipientId = (int)($row['recipient_id'] ?? 0);
    $originUserId = (int)($row['ai_origin_user_id'] ?? 0);
    $chatKey = (string)(
        $originUserId > 0
            ? ($originUserId === $viewerUserId ? $recipientId : $originUserId)
            : ($recipientId === $viewerUserId ? (int)($row['sender_id'] ?? 0) : $recipientId)
    );

    return [
        'id' => (int)($row['id'] ?? 0),
        'sender_id' => (int)($row['sender_id'] ?? 0),
        'recipient_id' => $recipientId,
        'group_id' => null,
        'self' => 0,
        'username' => trim((string)($row['username'] ?? 'quilltalk_ai')),
        'display_name' => trim((string)($row['display_name'] ?? 'QuillTalk AI')),
        'sender_display_name' => trim((string)($row['display_name'] ?? 'QuillTalk AI')),
        'sender_profile_pic' => trim((string)($row['profile_pic'] ?? 'images/default-ai.png')),
        'sender_chat_nickname' => '',
        'chat_key' => $chatKey,
        'is_ai_response' => 1,
        'original_user_id' => $originUserId > 0 ? $originUserId : null,
        'message' => trim((string)($row['message'] ?? '')),
        'created_at' => trim((string)($row['created_at'] ?? '')),
        'reply_to_id' => (int)($row['reply_to_id'] ?? 0) ?: null,
        'reply_to_ref_id' => (int)($row['reply_to_ref_id'] ?? 0) ?: null,
        'reply_to_display_name' => trim((string)($row['reply_to_display_name'] ?? '')),
        'reply_to_message_body' => trim((string)($row['reply_to_message_body'] ?? '')),
        'forward_from_user_id' => null,
        'forward_from_display_name' => '',
    ];
}

function fetch_public_ai_direct_message(PDO $pdo, int $messageId, int $viewerUserId): array
{
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.sender_id,
            m.recipient_id,
            m.message,
            m.created_at,
            m.ai_origin_user_id,
            m.reply_to_id,
            u.username,
            COALESCE(NULLIF(m.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-ai.png') AS profile_pic,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username, '') AS reply_to_display_name,
            COALESCE(rm.message, '') AS reply_to_message_body
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages rm ON m.reply_to_id = rm.id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('The generated AI image message could not be loaded.');
    }
    return build_public_ai_direct_message($pdo, $row, $viewerUserId);
}

function fetch_public_ai_group_message(PDO $pdo, int $messageId): array
{
    $stmt = $pdo->prepare("
        SELECT
            gm.id,
            gm.group_id,
            gm.sender_id,
            gm.message,
            gm.created_at,
            gm.reply_to_id,
            u.username,
            COALESCE(NULLIF(gm.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-ai.png') AS profile_pic,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username, '') AS reply_to_display_name,
            COALESCE(rm.message, '') AS reply_to_message_body
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        LEFT JOIN group_messages rm
            ON gm.reply_to_id = rm.id AND rm.group_id = gm.group_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE gm.id = ?
        LIMIT 1
    ");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('The generated AI image message could not be loaded.');
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'sender_id' => (int)($row['sender_id'] ?? 0),
        'recipient_id' => null,
        'group_id' => (int)($row['group_id'] ?? 0),
        'self' => 0,
        'username' => trim((string)($row['username'] ?? 'quilltalk_ai')),
        'display_name' => trim((string)($row['display_name'] ?? 'QuillTalk AI')),
        'sender_display_name' => trim((string)($row['display_name'] ?? 'QuillTalk AI')),
        'sender_profile_pic' => trim((string)($row['profile_pic'] ?? 'images/default-ai.png')),
        'sender_chat_nickname' => '',
        'chat_key' => QT_GROUP_CHAT_PREFIX . (int)($row['group_id'] ?? 0),
        'is_ai_response' => 1,
        'original_user_id' => null,
        'message' => trim((string)($row['message'] ?? '')),
        'created_at' => trim((string)($row['created_at'] ?? '')),
        'reply_to_id' => (int)($row['reply_to_id'] ?? 0) ?: null,
        'reply_to_ref_id' => (int)($row['reply_to_ref_id'] ?? 0) ?: null,
        'reply_to_display_name' => trim((string)($row['reply_to_display_name'] ?? '')),
        'reply_to_message_body' => trim((string)($row['reply_to_message_body'] ?? '')),
        'forward_from_user_id' => null,
        'forward_from_display_name' => '',
    ];
}

function fetch_ai_chat_message_payload(PDO $pdo, int $messageId, int $aiChatId): array
{
    $stmt = $pdo->prepare("
        SELECT id, display_name, profile_pic
        FROM ai_chats
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$aiChatId]);
    $aiChat = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $msgStmt = $pdo->prepare("
        SELECT
            m.id,
            m.message,
            m.created_at,
            m.reply_to_id,
            reply_m.id AS reply_to_ref_id,
            reply_m.sender_type AS reply_sender_type,
            reply_m.message AS reply_to_message_body,
            reply_u.display_name AS reply_user_display_name,
            reply_u.username AS reply_user_username
        FROM ai_chat_messages m
        LEFT JOIN ai_chat_messages reply_m
            ON m.reply_to_id = reply_m.id AND reply_m.ai_chat_id = m.ai_chat_id
        LEFT JOIN users reply_u ON reply_m.user_id = reply_u.id
        WHERE m.id = ? AND m.ai_chat_id = ?
        LIMIT 1
    ");
    $msgStmt->execute([$messageId, $aiChatId]);
    $message = $msgStmt->fetch(PDO::FETCH_ASSOC);
    if (!$message) {
        throw new RuntimeException('The AI chat image message could not be loaded.');
    }

    $replyDisplayName = '';
    if (!empty($message['reply_to_ref_id'])) {
        $replyDisplayName = $message['reply_sender_type'] === 'ai'
            ? (string)($aiChat['display_name'] ?? 'QuillTalk AI')
            : (string)($message['reply_user_display_name'] ?? $message['reply_user_username'] ?? 'You');
    }

    return [
        'id' => (int)($message['id'] ?? 0),
        'client_id' => 'ai-msg-' . (int)($message['id'] ?? 0),
        'ai_chat_id' => $aiChatId,
        'chat_key' => 'ai:' . $aiChatId,
        'sender_id' => 'ai:' . $aiChatId,
        'sender_type' => 'ai',
        'message' => trim((string)($message['message'] ?? '')),
        'created_at' => trim((string)($message['created_at'] ?? '')),
        'sender_display_name' => trim((string)($aiChat['display_name'] ?? 'QuillTalk AI')),
        'sender_profile_pic' => trim((string)($aiChat['profile_pic'] ?? 'images/default-ai.png')),
        'sender_online' => 1,
        'self' => 0,
        'is_ai_response' => 1,
        'reply_to_id' => (int)($message['reply_to_id'] ?? 0) ?: null,
        'reply_to_ref_id' => (int)($message['reply_to_ref_id'] ?? 0) ?: null,
        'reply_to_display_name' => $replyDisplayName,
        'reply_to_message_body' => trim((string)($message['reply_to_message_body'] ?? '')),
    ];
}

function ensure_ai_chat_message_reply_schema(PDO $pdo): void
{
    $tableStmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_chat_messages'
    ");
    $tableStmt->execute();
    if ((int)$tableStmt->fetchColumn() <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_chat_messages'
          AND COLUMN_NAME = 'reply_to_id'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE ai_chat_messages ADD COLUMN reply_to_id INT UNSIGNED NULL DEFAULT NULL AFTER message");
}

function require_editable_ai_image_payload(?string $rawMessage): array
{
    $payload = qt_ai_image_parse_attachment_payload($rawMessage);
    $kind = strtolower(trim((string)($payload['kind'] ?? '')));
    $isAiGenerated = !empty($payload['ai_generated']);
    $imageUrl = trim((string)($payload['url'] ?? ''));

    if (!$payload || $kind !== 'image' || !$isAiGenerated || $imageUrl === '') {
        throw new RuntimeException('Only AI-generated images can be improvised.');
    }

    return $payload;
}

function fetch_editable_ai_chat_image_row(PDO $pdo, int $messageId, int $aiChatId): array
{
    $stmt = $pdo->prepare("
        SELECT id, message
        FROM ai_chat_messages
        WHERE id = ? AND ai_chat_id = ? AND sender_type = 'ai'
        LIMIT 1
    ");
    $stmt->execute([$messageId, $aiChatId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('The AI image message could not be found.');
    }

    $row['attachment_payload'] = require_editable_ai_image_payload((string)($row['message'] ?? ''));
    return $row;
}

function fetch_editable_public_group_ai_image_row(PDO $pdo, int $messageId, int $groupId, int $aiUserId): array
{
    $stmt = $pdo->prepare("
        SELECT id, message
        FROM group_messages
        WHERE id = ? AND group_id = ? AND sender_id = ?
        LIMIT 1
    ");
    $stmt->execute([$messageId, $groupId, $aiUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('The AI image message could not be found.');
    }

    $row['attachment_payload'] = require_editable_ai_image_payload((string)($row['message'] ?? ''));
    return $row;
}

function fetch_editable_public_direct_ai_image_row(PDO $pdo, int $messageId, int $aiUserId, int $viewerUserId, int $otherUserId): array
{
    $stmt = $pdo->prepare("
        SELECT id, message
        FROM messages
        WHERE id = ?
          AND sender_id = ?
          AND (
                (recipient_id = ? AND COALESCE(ai_origin_user_id, 0) = ?)
             OR (recipient_id = ? AND COALESCE(ai_origin_user_id, 0) = ?)
          )
        LIMIT 1
    ");
    $stmt->execute([$messageId, $aiUserId, $otherUserId, $viewerUserId, $viewerUserId, $otherUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('The AI image message could not be found.');
    }

    $row['attachment_payload'] = require_editable_ai_image_payload((string)($row['message'] ?? ''));
    return $row;
}

function find_existing_public_direct_ai_image(PDO $pdo, int $aiUserId, int $viewerUserId, int $otherUserId, int $originMessageId): ?array
{
    if ($originMessageId <= 0) {
        return null;
    }

    $needle = '%"origin_message_id":' . $originMessageId . '%';
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.sender_id,
            m.recipient_id,
            m.message,
            m.created_at,
            m.ai_origin_user_id,
            m.reply_to_id,
            u.username,
            COALESCE(NULLIF(m.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-ai.png') AS profile_pic,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username, '') AS reply_to_display_name,
            COALESCE(rm.message, '') AS reply_to_message_body
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages rm ON m.reply_to_id = rm.id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE m.sender_id = ?
          AND COALESCE(m.is_ai_response, 0) = 1
          AND (
                (m.recipient_id = ? AND COALESCE(m.ai_origin_user_id, 0) = ?)
             OR (m.recipient_id = ? AND COALESCE(m.ai_origin_user_id, 0) = ?)
          )
          AND m.message LIKE ?
        ORDER BY m.id DESC
        LIMIT 1
    ");
    $stmt->execute([$aiUserId, $otherUserId, $viewerUserId, $viewerUserId, $otherUserId, $needle]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? build_public_ai_direct_message($pdo, $row, $viewerUserId) : null;
}

function find_existing_public_group_ai_image(PDO $pdo, int $aiUserId, int $groupId, int $originMessageId): ?array
{
    if ($originMessageId <= 0) {
        return null;
    }

    $needle = '%"origin_message_id":' . $originMessageId . '%';
    $stmt = $pdo->prepare("
        SELECT gm.id
        FROM group_messages gm
        WHERE gm.sender_id = ?
          AND gm.group_id = ?
          AND gm.message LIKE ?
        ORDER BY gm.id DESC
        LIMIT 1
    ");
    $stmt->execute([$aiUserId, $groupId, $needle]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    return $existingId > 0 ? fetch_public_ai_group_message($pdo, $existingId) : null;
}

function find_existing_ai_chat_image(PDO $pdo, int $aiChatId, int $originMessageId): ?array
{
    if ($originMessageId <= 0) {
        return null;
    }

    $needle = '%"origin_message_id":' . $originMessageId . '%';
    $stmt = $pdo->prepare("
        SELECT id
        FROM ai_chat_messages
        WHERE ai_chat_id = ?
          AND sender_type = 'ai'
          AND message LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$aiChatId, $needle]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    return $existingId > 0 ? fetch_ai_chat_message_payload($pdo, $existingId, $aiChatId) : null;
}

function qt_ai_image_acquire_origin_lock(PDO $pdo, string $scope, int $originMessageId, int $timeoutSeconds = 15): string
{
    if ($originMessageId <= 0) {
        return '';
    }

    $normalizedScope = preg_replace('/[^a-z0-9:_-]+/i', '_', strtolower(trim($scope)));
    if (!is_string($normalizedScope) || $normalizedScope === '') {
        $normalizedScope = 'message';
    }

    $lockName = 'qt_ai_image_' . substr(sha1($normalizedScope . ':' . $originMessageId), 0, 40);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$lockName, $timeoutSeconds]);
    $acquired = $stmt->fetchColumn();

    if ((string)$acquired !== '1') {
        throw new RuntimeException('Another AI image is already being generated for this message. Please wait a moment and try again.');
    }

    return $lockName;
}

function qt_ai_image_release_origin_lock(PDO $pdo, string $lockName): void
{
    if ($lockName === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    } catch (Throwable $error) {
        error_log('[AI IMAGE LOCK RELEASE] ' . $error->getMessage());
    }
}

function qt_ai_image_with_origin_lock(PDO $pdo, string $scope, int $originMessageId, callable $callback): array
{
    $lockName = qt_ai_image_acquire_origin_lock($pdo, $scope, $originMessageId);

    try {
        $result = $callback();
        return is_array($result) ? $result : [];
    } finally {
        qt_ai_image_release_origin_lock($pdo, $lockName);
    }
}

$input = request_input();
$token = trim((string)($input['token'] ?? ''));
$chatKey = trim((string)($input['chat_key'] ?? $input['recipient_id'] ?? ''));
$prompt = qt_ai_image_sanitize_prompt((string)($input['prompt'] ?? ''));
$mode = strtolower(trim((string)($input['mode'] ?? 'public')));
$originMessageId = max(0, (int)($input['origin_message_id'] ?? 0));
$editMessageId = max(0, (int)($input['edit_message_id'] ?? 0));
$sourceAttachmentUrl = trim((string)($input['source_attachment_url'] ?? ''));
$sourcePrompt = qt_ai_image_sanitize_prompt((string)($input['source_prompt'] ?? ''));

if ($token === '' || $prompt === '') {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$viewerUserId = require_session_user_id($pdo, $token);
$validationError = qt_ai_image_validate_prompt($prompt);
if ($validationError !== null) {
    respond(['success' => false, 'error' => $validationError], 400);
}

$meta = [
    'origin_message_id' => $originMessageId > 0 ? $originMessageId : null,
    'mode' => $mode,
];
$meta = array_filter($meta, static fn($value) => $value !== null && $value !== '');

try {
    if ($mode === 'private') {
        $payload = $sourceAttachmentUrl !== ''
            ? qt_ai_image_edit_attachment_payload($sourceAttachmentUrl, $sourcePrompt, $prompt, $meta)
            : qt_ai_image_generate_attachment_payload($prompt, $meta);
        respond([
            'success' => true,
            'mode' => 'private',
            'attachment_payload' => $payload,
            'attachment_message' => qt_ai_image_attachment_message($payload),
        ]);
    }

    if ($mode === 'ai_chat') {
        if (!str_starts_with($chatKey, 'ai:')) {
            respond(['success' => false, 'error' => 'Invalid AI chat'], 400);
        }

        $aiChatId = max(0, (int)substr($chatKey, 3));
        if ($aiChatId <= 0) {
            respond(['success' => false, 'error' => 'Invalid AI chat'], 400);
        }

        $chatStmt = $pdo->prepare('SELECT id FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1');
        $chatStmt->execute([$aiChatId, $viewerUserId]);
        if (!$chatStmt->fetch()) {
            respond(['success' => false, 'error' => 'AI chat not found or access denied'], 403);
        }
        ensure_ai_chat_message_reply_schema($pdo);

        if ($editMessageId > 0) {
            $responseData = qt_ai_image_with_origin_lock(
                $pdo,
                'ai_chat_edit_message',
                $editMessageId,
                static function () use ($pdo, $aiChatId, $editMessageId, $prompt): array {
                    $sourceRow = fetch_editable_ai_chat_image_row($pdo, $editMessageId, $aiChatId);
                    $sourcePayload = is_array($sourceRow['attachment_payload'] ?? null) ? $sourceRow['attachment_payload'] : [];
                    $existingMeta = is_array($sourcePayload['ai_meta'] ?? null) ? $sourcePayload['ai_meta'] : [];
                    $editMeta = array_filter(array_merge($existingMeta, [
                        'edited_from_message_id' => $editMessageId,
                        'edited_at' => gmdate('c'),
                    ]), static fn($value) => $value !== null && $value !== '');
                    $payload = qt_ai_image_edit_attachment_payload(
                        (string)($sourcePayload['url'] ?? ''),
                        (string)($sourcePayload['ai_prompt'] ?? ''),
                        $prompt,
                        $editMeta
                    );
                    $messageText = qt_ai_image_attachment_message($payload);

                    $updateStmt = $pdo->prepare("
                        UPDATE ai_chat_messages
                        SET message = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $updateStmt->execute([$messageText, $editMessageId]);

                    return [
                        'success' => true,
                        'mode' => 'ai_chat',
                        'message' => fetch_ai_chat_message_payload($pdo, $editMessageId, $aiChatId),
                        'edited' => true,
                    ];
                }
            );

            respond($responseData);
        }

        $responseData = qt_ai_image_with_origin_lock(
            $pdo,
            'ai_chat_message',
            $originMessageId,
            static function () use ($pdo, $aiChatId, $viewerUserId, $originMessageId, $prompt, $meta): array {
                $existing = find_existing_ai_chat_image($pdo, $aiChatId, $originMessageId);
                if ($existing) {
                    return [
                        'success' => true,
                        'mode' => 'ai_chat',
                        'message' => $existing,
                        'generated' => false,
                        'deduped' => true,
                    ];
                }

                $payload = qt_ai_image_generate_attachment_payload($prompt, $meta);
                $messageText = qt_ai_image_attachment_message($payload);

                $insertStmt = $pdo->prepare("
                    INSERT INTO ai_chat_messages (ai_chat_id, user_id, sender_type, message, reply_to_id, created_at)
                    VALUES (?, ?, 'ai', ?, ?, NOW())
                ");
                $insertStmt->execute([
                    $aiChatId,
                    $viewerUserId,
                    $messageText,
                    $originMessageId > 0 ? $originMessageId : null,
                ]);
                $messageId = (int)$pdo->lastInsertId();

                return [
                    'success' => true,
                    'mode' => 'ai_chat',
                    'message' => fetch_ai_chat_message_payload($pdo, $messageId, $aiChatId),
                    'generated' => true,
                ];
            }
        );

        respond($responseData);
    }

    $target = qt_parse_chat_target($chatKey);
    if ($target['type'] === 'unknown' || (int)($target['id'] ?? 0) <= 0) {
        respond(['success' => false, 'error' => 'Invalid chat target'], 400);
    }

    $aiUserId = qt_ai_image_get_or_create_ai_user_id($pdo);
    if ($target['type'] === 'group') {
        $groupId = (int)$target['id'];
        ensure_group_chat_access($pdo, $viewerUserId, $groupId);

        if ($editMessageId > 0) {
            $responseData = qt_ai_image_with_origin_lock(
                $pdo,
                'group_edit_message',
                $editMessageId,
                static function () use ($pdo, $aiUserId, $groupId, $editMessageId, $prompt): array {
                    $sourceRow = fetch_editable_public_group_ai_image_row($pdo, $editMessageId, $groupId, $aiUserId);
                    $sourcePayload = is_array($sourceRow['attachment_payload'] ?? null) ? $sourceRow['attachment_payload'] : [];
                    $existingMeta = is_array($sourcePayload['ai_meta'] ?? null) ? $sourcePayload['ai_meta'] : [];
                    $editMeta = array_filter(array_merge($existingMeta, [
                        'edited_from_message_id' => $editMessageId,
                        'edited_at' => gmdate('c'),
                    ]), static fn($value) => $value !== null && $value !== '');
                    $payload = qt_ai_image_edit_attachment_payload(
                        (string)($sourcePayload['url'] ?? ''),
                        (string)($sourcePayload['ai_prompt'] ?? ''),
                        $prompt,
                        $editMeta
                    );
                    $messageText = qt_ai_image_attachment_message($payload);

                    $updateStmt = $pdo->prepare("
                        UPDATE group_messages
                        SET message = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $updateStmt->execute([$messageText, $editMessageId]);

                    return [
                        'success' => true,
                        'mode' => 'public',
                        'message' => fetch_public_ai_group_message($pdo, $editMessageId),
                        'edited' => true,
                    ];
                }
            );

            respond($responseData);
        }

        $responseData = qt_ai_image_with_origin_lock(
            $pdo,
            'group_message',
            $originMessageId,
            static function () use ($pdo, $aiUserId, $groupId, $originMessageId, $prompt, $meta): array {
                $existing = find_existing_public_group_ai_image($pdo, $aiUserId, $groupId, $originMessageId);
                if ($existing) {
                    return [
                        'success' => true,
                        'mode' => 'public',
                        'message' => $existing,
                        'generated' => false,
                        'deduped' => true,
                    ];
                }

                $payload = qt_ai_image_generate_attachment_payload($prompt, $meta);
                $messageText = qt_ai_image_attachment_message($payload);

                $insertStmt = $pdo->prepare("
                    INSERT INTO group_messages (
                        group_id,
                        sender_id,
                        message,
                        reply_to_id,
                        is_ai_response,
                        ai_sender_display_name,
                        ai_origin_message_id,
                        created_at
                    ) VALUES (?, ?, ?, ?, 1, ?, ?, NOW())
                ");
                $insertStmt->execute([
                    $groupId,
                    $aiUserId,
                    $messageText,
                    $originMessageId > 0 ? $originMessageId : null,
                    'QuillTalk AI',
                    $originMessageId > 0 ? $originMessageId : null,
                ]);
                $messageId = (int)$pdo->lastInsertId();

                return [
                    'success' => true,
                    'mode' => 'public',
                    'message' => fetch_public_ai_group_message($pdo, $messageId),
                    'generated' => true,
                ];
            }
        );

        respond($responseData);
    }

    $otherUserId = (int)$target['id'];
    ensure_direct_chat_access($pdo, $viewerUserId, $otherUserId);

    if ($editMessageId > 0) {
        $responseData = qt_ai_image_with_origin_lock(
            $pdo,
            'direct_edit_message',
            $editMessageId,
            static function () use ($pdo, $aiUserId, $viewerUserId, $otherUserId, $editMessageId, $prompt): array {
                $sourceRow = fetch_editable_public_direct_ai_image_row($pdo, $editMessageId, $aiUserId, $viewerUserId, $otherUserId);
                $sourcePayload = is_array($sourceRow['attachment_payload'] ?? null) ? $sourceRow['attachment_payload'] : [];
                $existingMeta = is_array($sourcePayload['ai_meta'] ?? null) ? $sourcePayload['ai_meta'] : [];
                $editMeta = array_filter(array_merge($existingMeta, [
                    'edited_from_message_id' => $editMessageId,
                    'edited_at' => gmdate('c'),
                ]), static fn($value) => $value !== null && $value !== '');
                $payload = qt_ai_image_edit_attachment_payload(
                    (string)($sourcePayload['url'] ?? ''),
                    (string)($sourcePayload['ai_prompt'] ?? ''),
                    $prompt,
                    $editMeta
                );
                $messageText = qt_ai_image_attachment_message($payload);

                $updateStmt = $pdo->prepare("
                    UPDATE messages
                    SET message = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                $updateStmt->execute([$messageText, $editMessageId]);

                return [
                    'success' => true,
                    'mode' => 'public',
                    'message' => fetch_public_ai_direct_message($pdo, $editMessageId, $viewerUserId),
                    'edited' => true,
                ];
            }
        );

        respond($responseData);
    }

    $responseData = qt_ai_image_with_origin_lock(
        $pdo,
        'direct_message',
        $originMessageId,
        static function () use ($pdo, $aiUserId, $viewerUserId, $otherUserId, $originMessageId, $prompt, $meta): array {
            $existing = find_existing_public_direct_ai_image($pdo, $aiUserId, $viewerUserId, $otherUserId, $originMessageId);
            if ($existing) {
                return [
                    'success' => true,
                    'mode' => 'public',
                    'message' => $existing,
                    'generated' => false,
                    'deduped' => true,
                ];
            }

            $payload = qt_ai_image_generate_attachment_payload($prompt, $meta);
            $messageText = qt_ai_image_attachment_message($payload);

            $insertStmt = $pdo->prepare("
                INSERT INTO messages (
                    sender_id,
                    recipient_id,
                    message,
                    reply_to_id,
                    is_ai_response,
                    ai_sender_display_name,
                    ai_origin_user_id,
                    ai_origin_message_id,
                    created_at
                ) VALUES (?, ?, ?, ?, 1, ?, ?, ?, NOW())
            ");
            $insertStmt->execute([
                $aiUserId,
                $otherUserId,
                $messageText,
                $originMessageId > 0 ? $originMessageId : null,
                'QuillTalk AI',
                $viewerUserId,
                $originMessageId > 0 ? $originMessageId : null,
            ]);
            $messageId = (int)$pdo->lastInsertId();

            return [
                'success' => true,
                'mode' => 'public',
                'message' => fetch_public_ai_direct_message($pdo, $messageId, $viewerUserId),
                'generated' => true,
            ];
        }
    );

    respond($responseData);
} catch (Throwable $error) {
    error_log('[AI IMAGE] ' . $error->getMessage());
    respond([
        'success' => false,
        'error' => $error->getMessage(),
        'hint' => 'Image generation is not available right now. Please try again later.',
    ], 500);
}
