<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

function json_exit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$token   = trim((string)($_GET['token'] ?? ''));
$msgType = trim((string)($_GET['message_type'] ?? ''));
$msgIds  = array_values(array_filter(array_map('intval', explode(',', $_GET['message_ids'] ?? '')), fn($id) => $id > 0));

if ($token === '' || !in_array($msgType, ['direct','group','ai'], true) || empty($msgIds)) {
    json_exit([]);
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { json_exit([]); }
$userId = (int)$session['user_id'];

// Force utf8mb4 so emoji are read correctly
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// Ensure table exists with correct utf8mb4 charset for emoji storage
$pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_type ENUM('direct','group','ai') NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reaction (message_type, message_id, user_id, emoji),
    KEY idx_message (message_type, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Ensure emoji column uses utf8mb4 (fix if table was created with wrong charset)
try {
    $pdo->exec("ALTER TABLE message_reactions MODIFY COLUMN emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
} catch (Throwable $e) { /* already correct */ }
try {
    $pdo->exec("ALTER TABLE message_reactions MODIFY COLUMN message_type ENUM('direct','group','ai') NOT NULL");
} catch (Throwable $e) { /* already correct */ }

if ($msgType === 'ai') {
    $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
    $allowedStmt = $pdo->prepare("
        SELECT m.id
        FROM ai_chat_messages m
        JOIN ai_chats ac ON ac.id = m.ai_chat_id
        WHERE ac.user_id = ? AND m.id IN ($placeholders)
    ");
    $allowedStmt->execute(array_merge([$userId], $msgIds));
    $msgIds = array_values(array_map('intval', $allowedStmt->fetchAll(PDO::FETCH_COLUMN)));
    if ($msgIds === []) {
        json_exit([]);
    }
}

$placeholders = implode(',', array_fill(0, count($msgIds), '?'));
$params = array_merge([$userId, $msgType], $msgIds);

$rStmt = $pdo->prepare("
    SELECT mr.message_id, mr.emoji, COUNT(*) AS cnt,
           GROUP_CONCAT(
               CASE
                   WHEN mr.message_type = 'ai' AND u.username = 'quilltalk_ai'
                       THEN COALESCE(NULLIF(ac.display_name,''), COALESCE(NULLIF(u.display_name,''), u.username))
                   ELSE COALESCE(NULLIF(u.display_name,''),u.username)
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
    WHERE mr.message_type = ? AND mr.message_id IN ($placeholders)
    GROUP BY mr.message_id, mr.emoji
    ORDER BY mr.message_id, cnt DESC
");
$rStmt->execute($params);
$rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by message_id
$result = [];
foreach ($rows as $row) {
    $mid = (int)$row['message_id'];
    if (!isset($result[$mid])) $result[$mid] = [];
    $result[$mid][] = [
        'emoji'        => $row['emoji'],
        'cnt'          => (int)$row['cnt'],
        'reactors'     => $row['reactors'],
        'self_reacted' => (int)$row['self_reacted'],
    ];
}

json_exit($result);
