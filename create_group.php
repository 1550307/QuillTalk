<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/history_events.php';

const MAX_GROUP_ICON_SIZE = 8 * 1024 * 1024;

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    if (ob_get_length() > 0) {
        ob_clean();
    }
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $encoded = json_encode($data, $flags);
    if ($encoded === false) {
        $encoded = json_encode([
            'success' => false,
            'error' => 'Could not encode server response'
        ]);
    }
    echo $encoded;
    exit;
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    if (ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode([
        'success' => false,
        'error' => 'Fatal group chat creation error',
        'detail' => (string)($error['message'] ?? 'Unknown fatal error')
    ], JSON_UNESCAPED_UNICODE);
});

function sanitize_group_file_name(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
    $safe = trim((string)$safe, '._-');
    return $safe !== '' ? substr($safe, 0, 120) : 'group';
}

$token = trim((string)($_POST['token'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$memberIdsRaw = trim((string)($_POST['member_ids'] ?? ''));
$iconFile = $_FILES['icon'] ?? null;

try {
    if ($token === '' || $memberIdsRaw === '') {
        respond(['success' => false, 'error' => 'Missing parameters'], 400);
    }

    $sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
    $sessionStmt->execute([$token]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        respond(['success' => false, 'error' => 'Invalid session'], 401);
    }

    $viewerId = (int)($session['user_id'] ?? 0);
    if ($viewerId <= 0) {
        respond(['success' => false, 'error' => 'Invalid session'], 401);
    }

    $decodedMemberIds = json_decode($memberIdsRaw, true);
    if (!is_array($decodedMemberIds)) {
        respond(['success' => false, 'error' => 'Invalid group members'], 400);
    }

    $memberIds = [];
    foreach ($decodedMemberIds as $memberId) {
        $normalizedId = (int)$memberId;
        if ($normalizedId > 0 && $normalizedId !== $viewerId) {
            $memberIds[$normalizedId] = true;
        }
    }
    $memberIds = array_keys($memberIds);

    if (!$memberIds) {
        respond(['success' => false, 'error' => 'Choose at least one person for the group chat'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $friendStmt = $pdo->prepare("
        SELECT
            u.id,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
            MAX(CASE WHEN viewer_block.blocker_id IS NULL THEN 0 ELSE 1 END) AS viewer_has_blocked,
            MAX(CASE WHEN reverse_block.blocker_id IS NULL THEN 0 ELSE 1 END) AS blocked_viewer
        FROM users u
        JOIN friends f
            ON f.user_id = ?
           AND f.friend_id = u.id
        LEFT JOIN user_blocks viewer_block
            ON viewer_block.blocker_id = ?
           AND viewer_block.blocked_id = u.id
        LEFT JOIN user_blocks reverse_block
            ON reverse_block.blocker_id = u.id
           AND reverse_block.blocked_id = ?
        WHERE u.id IN ($placeholders)
        GROUP BY u.id, display_name
    ");
    $friendStmt->execute(array_merge([$viewerId, $viewerId, $viewerId], $memberIds));
    $validMembers = $friendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($validMembers) !== count($memberIds)) {
        respond(['success' => false, 'error' => 'You can only invite people from your contacts list'], 403);
    }

    foreach ($validMembers as $member) {
        if (!empty($member['viewer_has_blocked']) || !empty($member['blocked_viewer'])) {
            respond(['success' => false, 'error' => 'Blocked users cannot be added to a group chat'], 403);
        }
    }

    $validMemberNames = [];
    foreach ($validMembers as $member) {
        $validMemberId = (int)($member['id'] ?? 0);
        if ($validMemberId > 0) {
            $validMemberNames[$validMemberId] = trim((string)($member['display_name'] ?? '')) ?: ('User ' . $validMemberId);
        }
    }

    if ($name === '') {
        $name = qt_build_group_default_name(array_column($validMembers, 'display_name'));
    }

    $name = function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);
    $description = function_exists('mb_substr') ? mb_substr($description, 0, 1000) : substr($description, 0, 1000);
    ensure_history_events_schema($pdo);

    $iconPath = null;
    if (is_array($iconFile) && (($iconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        if (($iconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            respond(['success' => false, 'error' => 'Group icon upload failed'], 400);
        }

        $fileSize = (int)($iconFile['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > MAX_GROUP_ICON_SIZE) {
            respond(['success' => false, 'error' => 'Group icon is too large'], 400);
        }

        $originalName = sanitize_group_file_name((string)($iconFile['name'] ?? 'group'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            respond(['success' => false, 'error' => 'Unsupported group icon type'], 400);
        }

        $tmpPath = (string)($iconFile['tmp_name'] ?? '');
        $mime = '';
        if (class_exists('finfo') && $tmpPath !== '') {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmpPath);
        }
        if ($mime !== '' && !str_starts_with($mime, 'image/')) {
            respond(['success' => false, 'error' => 'Unsupported group icon type'], 400);
        }

        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'groups';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            respond(['success' => false, 'error' => 'Could not save group icon'], 500);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $storedAbsolutePath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpPath, $storedAbsolutePath)) {
            respond(['success' => false, 'error' => 'Could not save group icon'], 500);
        }

        $iconPath = 'uploads/groups/' . $storedName;
    }

    $pdo->beginTransaction();

    $groupStmt = $pdo->prepare("
        INSERT INTO chat_groups (name, description, icon_path, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    $groupStmt->execute([$name, $description, $iconPath, $viewerId]);
    $groupId = (int)$pdo->lastInsertId();

    $memberInsert = $pdo->prepare("
        INSERT INTO chat_group_members (group_id, user_id, role, joined_at, last_seen_message_id)
        VALUES (?, ?, ?, NOW(), 0)
    ");

    $memberInsert->execute([$groupId, $viewerId, QT_GROUP_ROLE_OWNER]);
    foreach ($memberIds as $memberId) {
        $memberInsert->execute([$groupId, $memberId, QT_GROUP_ROLE_MEMBER]);
    }

    $shouldSkipGroupHistory = qt_is_call_only_group_description($description);
    if (!$shouldSkipGroupHistory) {
        qt_log_history_event($pdo, [
            'actor_user_id' => $viewerId,
            'chat_type' => 'group',
            'chat_id' => $groupId,
            'event_type' => 'group_created',
        ]);

        foreach ($memberIds as $memberId) {
            qt_log_history_event($pdo, [
                'actor_user_id' => $viewerId,
                'subject_user_id' => $memberId,
                'chat_type' => 'group',
                'chat_id' => $groupId,
                'event_type' => 'group_member_invited',
            ]);

            qt_insert_group_news_message($pdo, $groupId, $viewerId, [
                'type' => 'member_invited',
                'subject_name' => $validMemberNames[$memberId] ?? ('User ' . $memberId),
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[GROUP CHAT CREATE] ' . $e->getMessage());
    $message = stripos($e->getMessage(), 'chat_group') !== false
        || stripos($e->getMessage(), 'group_messages') !== false
        || stripos($e->getMessage(), 'chat_groups') !== false
        ? 'Group chat database setup is still finishing. Please try again in a moment.'
        : 'Unable to create group chat';
    respond(['success' => false, 'error' => $message], 500);
}

$contact = qt_fetch_group_contact_row($pdo, $viewerId, $groupId);
if (!$contact) {
    respond(['success' => false, 'error' => 'Group chat was created but could not be loaded'], 500);
}

respond([
    'success' => true,
    'contact' => $contact,
]);
