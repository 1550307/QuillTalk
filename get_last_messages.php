<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

ensure_message_metadata_schema($pdo);

$token = $_GET['token'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT user_id 
    FROM sessions 
    WHERE token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode([]);
    exit;
}

$user_id = (int)$user['user_id'];

$directStmt = $pdo->prepare("
    SELECT 
        CAST(
            CASE
                WHEN COALESCE(is_ai_response, 0) = 1 AND ai_origin_user_id IS NOT NULL THEN
                    CASE
                        WHEN ai_origin_user_id = ? THEN recipient_id
                        ELSE ai_origin_user_id
                    END
                ELSE IF(sender_id = ?, recipient_id, sender_id)
            END AS CHAR
        ) AS chat_key,
        CASE
            WHEN COALESCE(is_ai_response, 0) = 1 AND ai_origin_user_id IS NOT NULL THEN
                CASE
                    WHEN ai_origin_user_id = ? THEN recipient_id
                    ELSE ai_origin_user_id
                END
            ELSE IF(sender_id = ?, recipient_id, sender_id)
        END AS friend_id,
        sender_id,
        message,
        created_at,
        NULL AS group_id,
        COALESCE(NULLIF(ai_sender_display_name, ''), NULL) AS sender_display_name,
        COALESCE(is_ai_response, 0) AS is_ai_response
    FROM messages
    WHERE (
        (
            COALESCE(is_ai_response, 0) = 0
            AND (sender_id = ? OR recipient_id = ?)
        )
        OR
        (
            COALESCE(is_ai_response, 0) = 1
            AND (ai_origin_user_id = ? OR recipient_id = ?)
        )
    )
    AND NOT EXISTS (
        SELECT 1 FROM message_visibility mv
        WHERE mv.user_id = ?
          AND mv.message_type = 'messages'
          AND mv.message_id = messages.id
    )
    ORDER BY created_at DESC
");
$directStmt->execute([
    $user_id,
    $user_id,
    $user_id,
    $user_id,
    $user_id,
    $user_id,
    $user_id,
    $user_id,
    $user_id,
]);

$groupStmt = $pdo->prepare("
    SELECT
        CONCAT('" . QT_GROUP_CHAT_PREFIX . "', gm.group_id) AS chat_key,
        NULL AS friend_id,
        gm.sender_id,
        gm.message,
        gm.created_at,
        gm.group_id,
        COALESCE(NULLIF(gm.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS sender_display_name
    FROM group_messages gm
    JOIN chat_group_members viewer_member
        ON viewer_member.group_id = gm.group_id
       AND viewer_member.user_id = ?
    JOIN users u
        ON u.id = gm.sender_id
    WHERE NOT EXISTS (
        SELECT 1 FROM message_visibility mv
        WHERE mv.user_id = ?
          AND mv.message_type = 'group_messages'
          AND mv.message_id = gm.id
    )
    ORDER BY gm.created_at DESC
");
$groupStmt->execute([$user_id, $user_id]);

$seen = [];
$out  = [];

foreach ([$directStmt, $groupStmt] as $stmt) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $chatKey = (string)($row['chat_key'] ?? '');
        if ($chatKey === '' || isset($seen[$chatKey])) {
            continue;
        }
        $seen[$chatKey] = true;
        $out[] = $row;
    }
}

usort($out, static function (array $a, array $b): int {
    return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
});

echo json_encode($out, JSON_UNESCAPED_UNICODE);
