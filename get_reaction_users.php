<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

function users_exit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$token   = trim((string)($_GET['token'] ?? ''));
$msgType = trim((string)($_GET['message_type'] ?? ''));
$msgId   = (int)($_GET['message_id'] ?? 0);
$emoji   = trim((string)($_GET['emoji'] ?? ''));

if ($token === '' || !in_array($msgType, ['direct', 'group', 'ai'], true) || $msgId <= 0 || $emoji === '') {
    users_exit(['users' => []]);
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    users_exit(['users' => []]);
}
$userId = (int)$session['user_id'];

if ($msgType === 'group') {
    $gStmt = $pdo->prepare("SELECT group_id FROM group_messages WHERE id = ? LIMIT 1");
    $gStmt->execute([$msgId]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gRow || !qt_user_can_access_group($pdo, $userId, (int)$gRow['group_id'])) {
        users_exit(['users' => []]);
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
        users_exit(['users' => []]);
    }
} else {
    $mStmt = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?) LIMIT 1");
    $mStmt->execute([$msgId, $userId, $userId]);
    if (!$mStmt->fetch()) {
        users_exit(['users' => []]);
    }
}

$listStmt = $pdo->prepare("
    SELECT u.id AS user_id,
           CASE
               WHEN mr.message_type = 'ai' AND u.username = 'quilltalk_ai'
                   THEN COALESCE(NULLIF(ac.display_name,''), COALESCE(NULLIF(u.display_name,''),u.username))
               ELSE COALESCE(NULLIF(u.display_name,''),u.username)
           END AS display_name,
           CASE
               WHEN mr.message_type = 'ai' AND u.username = 'quilltalk_ai'
                   THEN COALESCE(NULLIF(ac.profile_pic,''), 'images/default-ai.png')
               ELSE COALESCE(NULLIF(u.profile_pic,''),'images/default-profile.png')
           END AS profile_pic,
           CASE
               WHEN mr.message_type = 'ai' AND u.username = 'quilltalk_ai' THEN 1
               ELSE COALESCE(u.online,0)
           END AS online,
           mr.created_at
    FROM message_reactions mr
    JOIN users u ON u.id = mr.user_id
    LEFT JOIN ai_chat_messages aim
        ON mr.message_type = 'ai'
        AND aim.id = mr.message_id
    LEFT JOIN ai_chats ac
        ON aim.ai_chat_id = ac.id
    WHERE mr.message_type = ? AND mr.message_id = ? AND mr.emoji = ?
    ORDER BY mr.created_at ASC
");
$listStmt->execute([$msgType, $msgId, $emoji]);
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);

users_exit(['users' => $users]);
