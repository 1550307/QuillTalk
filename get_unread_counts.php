<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';

ensure_message_metadata_schema($pdo);

$token = $_GET['token'] ?? '';

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token=?");
$stmt->execute([$token]);
$user = $stmt->fetchColumn();

if (!$user) exit;

// Fetch identity and online status for mention detection
$userStmt = $pdo->prepare("
    SELECT
        username,
        COALESCE(NULLIF(display_name,''), username) AS display_name,
        CASE
            WHEN COALESCE(online, 0) = 1
             AND last_seen_at IS NOT NULL
             AND last_seen_at >= (NOW() - INTERVAL 90 SECOND)
            THEN 1
            ELSE 0
        END AS is_online
    FROM users
    WHERE id=?
");
$userStmt->execute([$user]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$username    = $userRow['username'] ?? '';
$displayName = $userRow['display_name'] ?? $username;
$isOnline = !empty($userRow['is_online']);
$mentionPatternUser    = '%@' . $username . '%';
$mentionPatternDisplay = '%@' . $displayName . '%';
$mentionPatternEveryone = '%@everyone%';
$mentionPatternHere = '%@here%';

$directStmt = $pdo->prepare("
    SELECT
        CAST(
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END AS CHAR
        ) AS chat_key,
        COUNT(*) AS cnt,
        SUM(
            CASE
                WHEN m.message LIKE ? OR m.message LIKE ?
                  OR (
                      self_direct_nick.nickname IS NOT NULL
                      AND self_direct_nick.nickname <> ''
                      AND m.message LIKE CONCAT('%@', self_direct_nick.nickname, '%')
                  )
                THEN 1
                ELSE 0
            END
        ) AS mention_cnt
    FROM messages m
    JOIN friends f
      ON f.user_id = ? AND f.friend_id = (
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END
      )
    LEFT JOIN chat_user_nicknames self_direct_nick
      ON self_direct_nick.user_id = ?
     AND self_direct_nick.chat_type = 'direct'
     AND self_direct_nick.chat_id = (
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END
     )
    WHERE m.recipient_id = ?
      AND m.id > f.last_seen_message_id
      AND (
            m.message NOT LIKE '__CALL_EVENT__:%'
            OR m.message LIKE '__CALL_EVENT__:missed|%'
      )
    GROUP BY chat_key
");
$directStmt->execute([$mentionPatternUser, $mentionPatternDisplay, $user, $user, $user]);
$directCounts = $directStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groupStmt = $pdo->prepare("
    SELECT
        CONCAT('" . QT_GROUP_CHAT_PREFIX . "', gm.group_id) AS chat_key,
        COUNT(*) AS cnt,
        SUM(
            CASE
                WHEN gm.message LIKE ? OR gm.message LIKE ? OR gm.message LIKE ?
                  OR (
                      self_group_nick.nickname IS NOT NULL
                      AND self_group_nick.nickname <> ''
                      AND gm.message LIKE CONCAT('%@', self_group_nick.nickname, '%')
                  )
                  OR (? = 1 AND gm.message LIKE ?)
                THEN 1
                ELSE 0
            END
        ) AS mention_cnt
    FROM group_messages gm
    JOIN chat_group_members cgm
      ON cgm.group_id = gm.group_id
     AND cgm.user_id = ?
    LEFT JOIN chat_user_nicknames self_group_nick
      ON self_group_nick.user_id = ?
     AND self_group_nick.chat_type = 'group'
     AND self_group_nick.chat_id = gm.group_id
    WHERE gm.id > cgm.last_seen_message_id
      AND gm.sender_id <> ?
    GROUP BY gm.group_id
");
$groupStmt->execute([
    $mentionPatternUser,
    $mentionPatternDisplay,
    $mentionPatternEveryone,
    $isOnline ? 1 : 0,
    $mentionPatternHere,
    $user,
    $user,
    $user
]);
$groupCounts = $groupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$directSenderStmt = $pdo->prepare("
    SELECT
        CAST(
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END AS CHAR
        ) AS chat_key,
        CASE
            WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
            ELSE m.sender_id
        END AS sender_id,
        COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
        COALESCE(u.profile_pic, '') AS profile_pic,
        CASE
            WHEN COALESCE(u.online, 0) = 1
             AND u.last_seen_at IS NOT NULL
             AND u.last_seen_at >= (NOW() - INTERVAL 90 SECOND)
            THEN 1
            ELSE 0
        END AS is_online,
        COUNT(*) AS cnt,
        SUM(
            CASE
                WHEN m.message LIKE ? OR m.message LIKE ?
                  OR (
                      self_direct_nick.nickname IS NOT NULL
                      AND self_direct_nick.nickname <> ''
                      AND m.message LIKE CONCAT('%@', self_direct_nick.nickname, '%')
                  )
                THEN 1
                ELSE 0
            END
        ) AS mention_cnt
    FROM messages m
    JOIN friends f
      ON f.user_id = ? AND f.friend_id = (
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END
      )
    JOIN users u
      ON u.id = (
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END
      )
    LEFT JOIN chat_user_nicknames self_direct_nick
      ON self_direct_nick.user_id = ?
     AND self_direct_nick.chat_type = 'direct'
     AND self_direct_nick.chat_id = (
            CASE
                WHEN COALESCE(m.is_ai_response, 0) = 1 AND m.ai_origin_user_id IS NOT NULL THEN m.ai_origin_user_id
                ELSE m.sender_id
            END
     )
    WHERE m.recipient_id = ?
      AND m.id > f.last_seen_message_id
      AND (
            m.message NOT LIKE '__CALL_EVENT__:%'
            OR m.message LIKE '__CALL_EVENT__:missed|%'
      )
    GROUP BY chat_key, sender_id, u.display_name, u.username, u.profile_pic, u.online, u.last_seen_at
");
$directSenderStmt->execute([$mentionPatternUser, $mentionPatternDisplay, $user, $user, $user]);
$directSenders = $directSenderStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groupSenderStmt = $pdo->prepare("
    SELECT
        CONCAT('" . QT_GROUP_CHAT_PREFIX . "', gm.group_id) AS chat_key,
        gm.sender_id AS sender_id,
        COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
        COALESCE(u.profile_pic, '') AS profile_pic,
        CASE
            WHEN COALESCE(u.online, 0) = 1
             AND u.last_seen_at IS NOT NULL
             AND u.last_seen_at >= (NOW() - INTERVAL 90 SECOND)
            THEN 1
            ELSE 0
        END AS is_online,
        COUNT(*) AS cnt,
        SUM(
            CASE
                WHEN gm.message LIKE ? OR gm.message LIKE ? OR gm.message LIKE ?
                  OR (
                      self_group_nick.nickname IS NOT NULL
                      AND self_group_nick.nickname <> ''
                      AND gm.message LIKE CONCAT('%@', self_group_nick.nickname, '%')
                  )
                  OR (? = 1 AND gm.message LIKE ?)
                THEN 1
                ELSE 0
            END
        ) AS mention_cnt
    FROM group_messages gm
    JOIN chat_group_members cgm
      ON cgm.group_id = gm.group_id
     AND cgm.user_id = ?
    JOIN users u
      ON u.id = gm.sender_id
    LEFT JOIN chat_user_nicknames self_group_nick
      ON self_group_nick.user_id = ?
     AND self_group_nick.chat_type = 'group'
     AND self_group_nick.chat_id = gm.group_id
    WHERE gm.id > cgm.last_seen_message_id
      AND gm.sender_id <> ?
    GROUP BY gm.group_id, gm.sender_id, u.display_name, u.username, u.profile_pic, u.online, u.last_seen_at
");
$groupSenderStmt->execute([
    $mentionPatternUser,
    $mentionPatternDisplay,
    $mentionPatternEveryone,
    $isOnline ? 1 : 0,
    $mentionPatternHere,
    $user,
    $user,
    $user
]);
$groupSenders = $groupSenderStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'success' => true,
    'rows' => array_merge($directCounts, $groupCounts),
    'senders' => array_merge($directSenders, $groupSenders),
], JSON_UNESCAPED_UNICODE);
