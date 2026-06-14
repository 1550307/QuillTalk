<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

function get_or_create_reaction_ai_user_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR display_name = ? LIMIT 1");
    $stmt->execute(['quilltalk_ai', 'QuillTalk AI']);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingUser) {
        return (int)$existingUser['id'];
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO users (username, display_name, email, password_hash, bio, profile_pic, created_at, is_passkey_user)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
    ");
    $insertStmt->execute([
        'quilltalk_ai',
        'QuillTalk AI',
        'ai@quilltalk.internal',
        password_hash('ai_system_account_' . bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'I am QuillTalk AI, your helpful assistant built into the messaging platform.',
        'images/default-profile.png',
    ]);

    return (int)$pdo->lastInsertId();
}

// Force utf8mb4 on this connection so emoji store correctly
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// Ensure table exists with correct utf8mb4 charset for emoji storage
$pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_type ENUM('direct','group','ai') NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reaction (message_type, message_id, user_id, emoji),
    KEY idx_message (message_type, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try {
    $pdo->exec("ALTER TABLE message_reactions MODIFY COLUMN emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
} catch (Throwable $e) { /* already correct */ }
try {
    $pdo->exec("ALTER TABLE message_reactions MODIFY COLUMN message_type ENUM('direct','group','ai') NOT NULL");
} catch (Throwable $e) { /* already correct */ }

$data = json_decode(file_get_contents('php://input') ?: '[]', true);
$token = trim((string)($data['token'] ?? ''));
$msgType = trim((string)($data['message_type'] ?? ''));
$msgId = (int)($data['message_id'] ?? 0);
$emoji = trim((string)($data['emoji'] ?? ''));
$action = trim((string)($data['action'] ?? 'toggle'));
$reactorMode = trim((string)($data['reactor_mode'] ?? 'self'));

if ($token === '' || !in_array($msgType, ['direct', 'group', 'ai'], true) || $msgId <= 0 || $emoji === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}
$userId = (int)$session['user_id'];

if ($msgType === 'group') {
    $gStmt = $pdo->prepare("SELECT group_id FROM group_messages WHERE id = ? LIMIT 1");
    $gStmt->execute([$msgId]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gRow || !qt_user_can_access_group($pdo, $userId, (int)$gRow['group_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
} elseif ($msgType === 'ai') {
    $aiStmt = $pdo->prepare("
        SELECT m.id
        FROM ai_chat_messages m
        JOIN ai_chats ac ON ac.id = m.ai_chat_id
        WHERE m.id = ? AND ac.user_id = ?
        LIMIT 1
    ");
    $aiStmt->execute([$msgId, $userId]);
    if (!$aiStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
} else {
    $mStmt = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?) LIMIT 1");
    $mStmt->execute([$msgId, $userId, $userId]);
    if (!$mStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$actorUserId = $userId;
if ($reactorMode === 'ai') {
    try {
        $actorUserId = get_or_create_reaction_ai_user_id($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not resolve AI reactor']);
        exit;
    }
}

$existing = $pdo->prepare("SELECT id FROM message_reactions WHERE message_type=? AND message_id=? AND user_id=? AND BINARY emoji=? LIMIT 1");
$existing->execute([$msgType, $msgId, $actorUserId, $emoji]);
$exists = (bool)$existing->fetch();

if ($action === 'toggle') {
    if ($exists) {
        $pdo->prepare("DELETE FROM message_reactions WHERE message_type=? AND message_id=? AND user_id=? AND BINARY emoji=?")
            ->execute([$msgType, $msgId, $actorUserId, $emoji]);
    } else {
        $pdo->prepare("INSERT INTO message_reactions (message_type, message_id, user_id, emoji) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE created_at = created_at")
            ->execute([$msgType, $msgId, $actorUserId, $emoji]);
    }
} elseif ($action === 'add' && !$exists) {
    $pdo->prepare("INSERT INTO message_reactions (message_type, message_id, user_id, emoji) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE created_at = created_at")
        ->execute([$msgType, $msgId, $actorUserId, $emoji]);
} elseif ($action === 'remove' && $exists) {
    $pdo->prepare("DELETE FROM message_reactions WHERE message_type=? AND message_id=? AND user_id=? AND BINARY emoji=?")
        ->execute([$msgType, $msgId, $actorUserId, $emoji]);
}

$rStmt = $pdo->prepare("
    SELECT mr.emoji,
           COUNT(*) AS cnt,
           GROUP_CONCAT(
               CASE
                   WHEN mr.message_type = 'ai' AND u.username = 'quilltalk_ai'
                       THEN COALESCE(NULLIF(ac.display_name,''), COALESCE(NULLIF(u.display_name,''), u.username))
                   ELSE COALESCE(NULLIF(u.display_name,''), u.username)
               END
               ORDER BY mr.created_at
               SEPARATOR '||'
           ) AS reactors,
           MAX(CASE WHEN mr.user_id = ? THEN 1 ELSE 0 END) AS self_reacted
    FROM message_reactions mr
    JOIN users u ON u.id = mr.user_id
    LEFT JOIN ai_chat_messages aim
        ON mr.message_type = 'ai'
        AND aim.id = mr.message_id
    LEFT JOIN ai_chats ac
        ON aim.ai_chat_id = ac.id
    WHERE mr.message_type = ? AND mr.message_id = ?
    GROUP BY mr.emoji
    ORDER BY MIN(mr.created_at) ASC
");
$rStmt->execute([$userId, $msgType, $msgId]);
$reactions = $rStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'reactions' => $reactions], JSON_UNESCAPED_UNICODE);
