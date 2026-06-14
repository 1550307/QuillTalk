<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

ensure_message_metadata_schema($pdo);

$token   = $_GET['token'] ?? '';
$target  = qt_parse_chat_target($_GET['with'] ?? '');
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if (!$token || $target['type'] === 'unknown' || $target['id'] <= 0) {
    echo json_encode([]);
    exit;
}

/* -------- GET USER FROM SESSION -------- */
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    echo json_encode([]);
    exit;
}

$user_id = (int)$session['user_id'];

try {
/* -------- CHECK FRIENDSHIP -------- */
if ($target['type'] === 'group') {
    if (!qt_user_can_access_group($pdo, $user_id, $target['id'])) {
        echo json_encode([]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            gm.id,
            gm.message,
            gm.created_at,
            gm.sender_id,
            NULL AS recipient_id,
            gm.group_id,
            CASE WHEN gm.sender_id = :me_case THEN 1 ELSE 0 END AS self,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS sender_display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS sender_profile_pic,
            COALESCE(u.online, 0) AS sender_online,
            COALESCE(NULLIF(sender_chat_nick.nickname, ''), '') AS sender_chat_nickname,
            gm.reply_to_id,
            gm.forward_from_user_id,
            gm.forward_from_display_name,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username) AS reply_to_display_name,
            rm.message AS reply_to_message_body
        FROM group_messages gm
        JOIN users u
            ON u.id = gm.sender_id
        LEFT JOIN chat_user_nicknames sender_chat_nick
            ON sender_chat_nick.user_id = gm.sender_id
           AND sender_chat_nick.chat_type = 'group'
           AND sender_chat_nick.chat_id = gm.group_id
        LEFT JOIN group_messages rm
            ON gm.reply_to_id = rm.id AND rm.group_id = gm.group_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE gm.group_id = :group_id
                    AND gm.id > :last_id
                    AND NOT EXISTS (
                            SELECT 1 FROM message_visibility mv
                            WHERE mv.user_id = :me_vis
                                AND mv.message_type = 'group_messages'
                                AND mv.message_id = gm.id
                    )
        ORDER BY gm.id ASC
    ");
    $stmt->execute([
        ':me_case' => $user_id,
        ':me_vis' => $user_id,
        ':group_id' => $target['id'],
        ':last_id' => $last_id
    ]);
} else {
    $check = $pdo->prepare("
        SELECT 1 FROM friends
        WHERE (user_id = ? AND friend_id = ?)
           OR (user_id = ? AND friend_id = ?)
        LIMIT 1
    ");
    $check->execute([$user_id, $target['id'], $target['id'], $user_id]);

    if (!$check->fetch()) {
        echo json_encode([]);
        exit;
    }

    /* -------- FETCH MESSAGES (NO PARAM REUSE) -------- */
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.message,
            m.created_at,
            m.sender_id,
            m.recipient_id,
            CAST(
                CASE
                    WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN
                        CASE
                            WHEN m.ai_origin_user_id = :me_chat_origin THEN m.recipient_id
                            ELSE m.ai_origin_user_id
                        END
                    ELSE
                        CASE
                            WHEN m.sender_id = :me_chat_sender THEN m.recipient_id
                            ELSE m.sender_id
                        END
                END AS CHAR
            ) AS chat_key,
            NULL AS group_id,
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 THEN 0
                WHEN m.sender_id = :me_case THEN 1
                ELSE 0
            END AS self,
            COALESCE(NULLIF(m.ai_sender_display_name, ''), NULLIF(u.display_name, ''), u.username) AS sender_display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS sender_profile_pic,
            COALESCE(u.online, 0) AS sender_online,
            COALESCE(NULLIF(sender_chat_nick.nickname, ''), '') AS sender_chat_nickname,
            COALESCE(m.is_ai_response, 0) AS is_ai_response,
            m.ai_origin_user_id AS original_user_id,
            m.reply_to_id,
            m.forward_from_user_id,
            m.forward_from_display_name,
            rm.id AS reply_to_ref_id,
            COALESCE(NULLIF(ru.display_name, ''), ru.username) AS reply_to_display_name,
            rm.message AS reply_to_message_body
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN chat_user_nicknames sender_chat_nick
            ON sender_chat_nick.user_id = m.sender_id
           AND sender_chat_nick.chat_type = 'direct'
           AND sender_chat_nick.chat_id = m.recipient_id
        LEFT JOIN messages rm ON m.reply_to_id = rm.id AND (
            (rm.sender_id = :me_r1 AND rm.recipient_id = :them_r1)
            OR (rm.sender_id = :them_r2 AND rm.recipient_id = :me_r2)
        )
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE
            (
                (
                    COALESCE(m.is_ai_response, 0) = 0
                    AND (
                        (m.sender_id = :me1 AND m.recipient_id = :them1)
                        OR
                        (m.sender_id = :them2 AND m.recipient_id = :me2)
                    )
                )
                OR
                (
                    COALESCE(m.is_ai_response, 0) = 1
                    AND (
                        (m.ai_origin_user_id = :me_ai1 AND m.recipient_id = :them_ai1)
                        OR
                        (m.ai_origin_user_id = :them_ai2 AND m.recipient_id = :me_ai2)
                    )
                )
            )
            AND m.id > :last_id
            AND NOT EXISTS (
                SELECT 1 FROM message_visibility mv
                WHERE mv.user_id = :me_vis
                  AND mv.message_type = 'messages'
                  AND mv.message_id = m.id
            )
        ORDER BY m.id ASC
    ");

    $stmt->execute([
        ':me_chat_origin' => $user_id,
        ':me_chat_sender' => $user_id,
        ':me_case' => $user_id,
        ':me_r1'     => $user_id,
        ':them_r1'   => $target['id'],
        ':them_r2'   => $target['id'],
        ':me_r2'     => $user_id,
        ':me1'     => $user_id,
        ':them1'   => $target['id'],
        ':them2'   => $target['id'],
        ':me2'     => $user_id,
        ':me_ai1'   => $user_id,
        ':them_ai1' => $target['id'],
        ':them_ai2' => $target['id'],
        ':me_ai2'   => $user_id,
        ':me_vis'  => $user_id,
        ':last_id' => $last_id
    ]);
}

$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($messages, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[fetch_messages] ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
}
