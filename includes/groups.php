<?php
declare(strict_types=1);

const QT_GROUP_CHAT_PREFIX = 'group:';
const QT_DEFAULT_GROUP_CHAT_ICON = 'images/default-group.svg';
const QT_GROUP_ROLE_OWNER = 'owner';
const QT_GROUP_ROLE_ADMIN = 'admin';
const QT_GROUP_ROLE_MEMBER = 'member';
const QT_GROUP_SEND_PERMISSION_ALL = 'all';
const QT_GROUP_SEND_PERMISSION_ADMINS = 'admins';
const QT_GROUP_ADD_PERMISSION_ALL = 'all';
const QT_GROUP_ADD_PERMISSION_ADMINS = 'admins';
const QT_CHAT_NOTIFY_ALL = 'all';
const QT_CHAT_NOTIFY_MENTION = 'mention';
const QT_GROUP_CALL_STATUS_RINGING = 'ringing';
const QT_GROUP_CALL_STATUS_ACTIVE = 'active';
const QT_GROUP_CALL_STATUS_ENDED = 'ended';
const QT_GROUP_CALL_MEMBER_PENDING = 'pending';
const QT_GROUP_CALL_MEMBER_ACCEPTED = 'accepted';
const QT_GROUP_CALL_MEMBER_REJECTED = 'rejected';
const QT_GROUP_CALL_MEMBER_LEFT = 'left';

function qt_build_group_chat_key(int $groupId): string
{
    return QT_GROUP_CHAT_PREFIX . $groupId;
}

function qt_is_group_chat_key(string $chatKey): bool
{
    return str_starts_with($chatKey, QT_GROUP_CHAT_PREFIX);
}

function qt_parse_chat_target(mixed $rawTarget): array
{
    $raw = trim((string)($rawTarget ?? ''));
    if ($raw === '') {
        return ['type' => 'unknown', 'id' => 0, 'key' => ''];
    }

    // Check for AI chat (ai:123)
    if (str_starts_with($raw, 'ai:')) {
        $aiId = (int)substr($raw, 3);
        return [
            'type' => $aiId > 0 ? 'ai' : 'unknown',
            'id' => $aiId,
            'key' => $aiId > 0 ? 'ai:' . $aiId : '',
        ];
    }

    if (qt_is_group_chat_key($raw)) {
        $groupId = (int)substr($raw, strlen(QT_GROUP_CHAT_PREFIX));
        return [
            'type' => $groupId > 0 ? 'group' : 'unknown',
            'id' => $groupId,
            'key' => $groupId > 0 ? qt_build_group_chat_key($groupId) : '',
        ];
    }

    $directId = (int)$raw;
    return [
        'type' => $directId > 0 ? 'direct' : 'unknown',
        'id' => $directId,
        'key' => $directId > 0 ? (string)$directId : '',
    ];
}

function qt_normalize_group_icon_path(?string $iconPath): string
{
    $trimmed = trim((string)$iconPath);
    return $trimmed !== '' ? $trimmed : QT_DEFAULT_GROUP_CHAT_ICON;
}

function qt_group_icon_storage_path(?string $iconPath): ?string
{
    $trimmed = trim((string)$iconPath);
    if ($trimmed === '' || $trimmed === QT_DEFAULT_GROUP_CHAT_ICON) {
        return null;
    }

    $relativePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($trimmed, "\\/"));
    $expectedPrefix = 'uploads' . DIRECTORY_SEPARATOR . 'groups' . DIRECTORY_SEPARATOR;
    if (!str_starts_with($relativePath, $expectedPrefix)) {
        return null;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
}

function qt_delete_group_icon_file(?string $iconPath): bool
{
    $absolutePath = qt_group_icon_storage_path($iconPath);
    if ($absolutePath === null || !is_file($absolutePath)) {
        return false;
    }

    return @unlink($absolutePath);
}

function qt_build_group_default_name(array $displayNames): string
{
    $normalized = [];
    foreach ($displayNames as $name) {
        $trimmed = trim((string)$name);
        if ($trimmed !== '') {
            $normalized[] = $trimmed;
        }
    }

    $normalized = array_values(array_unique($normalized));
    if (!$normalized) {
        return 'New Group Chat';
    }

    if (count($normalized) <= 3) {
        return implode(', ', $normalized);
    }

    $leading = array_slice($normalized, 0, 3);
    $remaining = count($normalized) - count($leading);
    return implode(', ', $leading) . ' +' . $remaining;
}

function qt_group_lowercase(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string)mb_strtolower($value);
    }

    return strtolower($value);
}

function qt_normalize_chat_notification_mode(?string $mode): string
{
    return qt_group_lowercase(trim((string)$mode)) === QT_CHAT_NOTIFY_MENTION
        ? QT_CHAT_NOTIFY_MENTION
        : QT_CHAT_NOTIFY_ALL;
}

function qt_normalize_chat_type(?string $chatType): string
{
    return qt_group_lowercase(trim((string)$chatType)) === 'group' ? 'group' : 'direct';
}

function qt_normalize_chat_user_nickname(?string $nickname): string
{
    $normalized = trim((string)$nickname);
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    if (function_exists('mb_substr')) {
        return (string)mb_substr($normalized, 0, 50);
    }

    return substr($normalized, 0, 50);
}

function qt_message_has_mention_token(string $message, string $candidate): bool
{
    $normalizedCandidate = trim($candidate);
    if ($normalizedCandidate === '') {
        return false;
    }

    $escapedCandidate = preg_quote($normalizedCandidate, '/');
    return preg_match('/(^|[^\p{L}\p{N}_])@' . $escapedCandidate . '(?=[\s,!?.,;:\'"\)\]\}]|$)/iu', $message) === 1;
}

function qt_message_mentions_user(
    string $message,
    ?string $username,
    ?string $displayName,
    ?string $chatNickname = null,
    bool $includeHere = false
): bool
{
    if (trim($message) === '') {
        return false;
    }

    if (qt_message_has_mention_token($message, 'everyone')) {
        return true;
    }

    if ($includeHere && qt_message_has_mention_token($message, 'here')) {
        return true;
    }

    $candidates = [];
    foreach ([$username, $displayName, $chatNickname] as $candidate) {
        $normalizedCandidate = trim((string)$candidate);
        if ($normalizedCandidate !== '') {
            $candidates[] = $normalizedCandidate;
        }
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (qt_message_has_mention_token($message, $candidate)) {
            return true;
        }
    }

    return false;
}

function qt_get_chat_notification_mode(PDO $pdo, int $userId, string $chatType, int $chatId): string
{
    $normalizedChatType = qt_normalize_chat_type($chatType);
    if ($userId <= 0 || $chatId <= 0) {
        return QT_CHAT_NOTIFY_ALL;
    }

    $stmt = $pdo->prepare("
        SELECT notify_mode
        FROM chat_notification_preferences
        WHERE user_id = ?
          AND chat_type = ?
          AND chat_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $normalizedChatType, $chatId]);
    $mode = $stmt->fetchColumn();

    return qt_normalize_chat_notification_mode(is_string($mode) ? $mode : null);
}

function qt_set_chat_notification_mode(PDO $pdo, int $userId, string $chatType, int $chatId, ?string $mode): string
{
    $normalizedChatType = qt_normalize_chat_type($chatType);
    $normalizedMode = qt_normalize_chat_notification_mode($mode);

    if ($userId <= 0 || $chatId <= 0) {
        return QT_CHAT_NOTIFY_ALL;
    }

    if ($normalizedMode === QT_CHAT_NOTIFY_ALL) {
        $stmt = $pdo->prepare("
            DELETE FROM chat_notification_preferences
            WHERE user_id = ?
              AND chat_type = ?
              AND chat_id = ?
        ");
        $stmt->execute([$userId, $normalizedChatType, $chatId]);
        return QT_CHAT_NOTIFY_ALL;
    }

    $stmt = $pdo->prepare("
        INSERT INTO chat_notification_preferences (user_id, chat_type, chat_id, notify_mode, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            notify_mode = VALUES(notify_mode),
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $normalizedChatType, $chatId, $normalizedMode]);

    return $normalizedMode;
}

function qt_get_chat_user_nickname(PDO $pdo, int $userId, string $chatType, int $chatId): string
{
    $normalizedChatType = qt_normalize_chat_type($chatType);
    if ($userId <= 0 || $chatId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT nickname
        FROM chat_user_nicknames
        WHERE user_id = ?
          AND chat_type = ?
          AND chat_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $normalizedChatType, $chatId]);

    return qt_normalize_chat_user_nickname((string)($stmt->fetchColumn() ?: ''));
}

function qt_set_chat_user_nickname(PDO $pdo, int $userId, string $chatType, int $chatId, ?string $nickname): string
{
    $normalizedChatType = qt_normalize_chat_type($chatType);
    $normalizedNickname = qt_normalize_chat_user_nickname($nickname);

    if ($userId <= 0 || $chatId <= 0) {
        return '';
    }

    if ($normalizedNickname === '') {
        $stmt = $pdo->prepare("
            DELETE FROM chat_user_nicknames
            WHERE user_id = ?
              AND chat_type = ?
              AND chat_id = ?
        ");
        $stmt->execute([$userId, $normalizedChatType, $chatId]);
        return '';
    }

    $stmt = $pdo->prepare("
        INSERT INTO chat_user_nicknames (user_id, chat_type, chat_id, nickname, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            nickname = VALUES(nickname),
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $normalizedChatType, $chatId, $normalizedNickname]);

    return $normalizedNickname;
}

function qt_user_online_sql(string $tableAlias = 'u'): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $tableAlias);
    if ($alias === null || $alias === '') {
        $alias = 'u';
    }

    return "CASE
        WHEN COALESCE({$alias}.online, 0) = 1
         AND {$alias}.last_seen_at IS NOT NULL
         AND {$alias}.last_seen_at >= (NOW() - INTERVAL 90 SECOND)
        THEN 1
        ELSE 0
    END";
}

function qt_group_normalize_role(?string $role): string
{
    $normalized = qt_group_lowercase(trim((string)$role));
    if ($normalized === QT_GROUP_ROLE_OWNER || $normalized === QT_GROUP_ROLE_ADMIN) {
        return $normalized;
    }

    return QT_GROUP_ROLE_MEMBER;
}

function qt_group_normalize_send_permission(?string $permission): string
{
    return qt_group_lowercase(trim((string)$permission)) === QT_GROUP_SEND_PERMISSION_ADMINS
        ? QT_GROUP_SEND_PERMISSION_ADMINS
        : QT_GROUP_SEND_PERMISSION_ALL;
}

function qt_group_normalize_add_permission(?string $permission): string
{
    return qt_group_lowercase(trim((string)$permission)) === QT_GROUP_ADD_PERMISSION_ALL
        ? QT_GROUP_ADD_PERMISSION_ALL
        : QT_GROUP_ADD_PERMISSION_ADMINS;
}

function qt_group_role_rank(?string $role): int
{
    return match (qt_group_normalize_role($role)) {
        QT_GROUP_ROLE_OWNER => 3,
        QT_GROUP_ROLE_ADMIN => 2,
        default => 1,
    };
}

function qt_group_role_can_manage(?string $role): bool
{
    return qt_group_role_rank($role) >= qt_group_role_rank(QT_GROUP_ROLE_ADMIN);
}

function qt_group_role_is_owner(?string $role): bool
{
    return qt_group_normalize_role($role) === QT_GROUP_ROLE_OWNER;
}

function qt_is_future_datetime(?string $value): bool
{
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return false;
    }

    $timestamp = strtotime($trimmed);
    return $timestamp !== false && $timestamp > time();
}

function qt_hydrate_group_contact_row(array $row): array
{
    $row['notification_mode'] = qt_normalize_chat_notification_mode((string)($row['notification_mode'] ?? QT_CHAT_NOTIFY_ALL));
    $row['chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['chat_nickname'] ?? ''));
    $row['viewer_chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['viewer_chat_nickname'] ?? ''));
    $row['profile_pic'] = qt_normalize_group_icon_path($row['profile_pic'] ?? null);
    $row['viewer_role'] = qt_group_normalize_role((string)($row['viewer_role'] ?? ''));
    $row['viewer_muted_until'] = trim((string)($row['viewer_muted_until'] ?? ''));
    $row['send_permission'] = qt_group_normalize_send_permission((string)($row['send_permission'] ?? ''));
    $row['add_members_permission'] = qt_group_normalize_add_permission((string)($row['add_members_permission'] ?? ''));
    $row['member_count'] = (int)($row['member_count'] ?? 0);
    $row['online'] = !empty($row['online']) ? 1 : 0;
    $row['viewer_is_muted'] = qt_is_future_datetime($row['viewer_muted_until']) ? 1 : 0;
    $row['viewer_can_manage_group'] = qt_group_role_can_manage($row['viewer_role']) ? 1 : 0;
    $row['viewer_can_change_roles'] = qt_group_role_is_owner($row['viewer_role']) ? 1 : 0;
    $row['viewer_can_delete_group'] = qt_group_role_is_owner($row['viewer_role']) ? 1 : 0;
    $row['viewer_can_send'] = (
        !$row['viewer_is_muted']
        && (
            $row['send_permission'] === QT_GROUP_SEND_PERMISSION_ALL
            || qt_group_role_can_manage($row['viewer_role'])
        )
    ) ? 1 : 0;

    return $row;
}

function qt_fetch_direct_contact_rows(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            CAST(u.id AS CHAR) AS id,
            'direct' AS chat_type,
            u.id AS entity_id,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
            u.username,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS profile_pic,
            " . qt_user_online_sql('u') . " AS online,
            COALESCE(u.bio, '') AS bio,
            u.created_at,
            lm.last_message_at,
            CASE WHEN viewer_block.blocker_id IS NULL THEN 0 ELSE 1 END AS viewer_has_blocked,
            CASE WHEN reverse_block.blocker_id IS NULL THEN 0 ELSE 1 END AS blocked_viewer,
            0 AS member_count,
            '' AS viewer_role,
            '' AS viewer_muted_until,
            '' AS send_permission,
            '' AS add_members_permission,
            0 AS viewer_can_manage_group,
            0 AS viewer_can_change_roles,
            0 AS viewer_can_delete_group,
            1 AS viewer_can_send,
            0 AS viewer_is_muted,
            COALESCE(NULLIF(pref.notify_mode, ''), '" . QT_CHAT_NOTIFY_ALL . "') AS notification_mode,
            COALESCE(NULLIF(contact_chat_nick.nickname, ''), '') AS chat_nickname,
            COALESCE(NULLIF(viewer_chat_nick.nickname, ''), '') AS viewer_chat_nickname
        FROM friends f
        JOIN users u ON f.friend_id = u.id
        LEFT JOIN chat_notification_preferences pref
            ON pref.user_id = ?
           AND pref.chat_type = 'direct'
           AND pref.chat_id = u.id
        LEFT JOIN chat_user_nicknames viewer_chat_nick
            ON viewer_chat_nick.user_id = ?
           AND viewer_chat_nick.chat_type = 'direct'
           AND viewer_chat_nick.chat_id = u.id
        LEFT JOIN chat_user_nicknames contact_chat_nick
            ON contact_chat_nick.user_id = u.id
           AND contact_chat_nick.chat_type = 'direct'
           AND contact_chat_nick.chat_id = ?
        LEFT JOIN user_blocks viewer_block
            ON viewer_block.blocker_id = ?
           AND viewer_block.blocked_id = u.id
        LEFT JOIN user_blocks reverse_block
            ON reverse_block.blocker_id = u.id
           AND reverse_block.blocked_id = ?
        LEFT JOIN (
            SELECT
                CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS friend_id,
                MAX(created_at) AS last_message_at
            FROM messages
            WHERE sender_id = ? OR recipient_id = ?
            GROUP BY CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END
        ) lm ON lm.friend_id = u.id
        WHERE f.user_id = ?
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['notification_mode'] = qt_normalize_chat_notification_mode((string)($row['notification_mode'] ?? QT_CHAT_NOTIFY_ALL));
        $row['chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['chat_nickname'] ?? ''));
        $row['viewer_chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['viewer_chat_nickname'] ?? ''));
    }
    unset($row);
    return $rows;
}

function qt_fetch_group_contact_rows(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('" . QT_GROUP_CHAT_PREFIX . "', g.id) AS id,
            'group' AS chat_type,
            g.id AS entity_id,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS display_name,
            '' AS username,
            COALESCE(NULLIF(g.icon_path, ''), '" . QT_DEFAULT_GROUP_CHAT_ICON . "') AS profile_pic,
            CASE
                WHEN MAX(CASE WHEN member_rows.user_id = ? THEN (" . qt_user_online_sql('member_users') . ") ELSE 0 END) > 0
                 AND MAX(CASE WHEN member_rows.user_id <> ? THEN (" . qt_user_online_sql('member_users') . ") ELSE 0 END) > 0
                THEN 1
                ELSE 0
            END AS online,
            COALESCE(g.description, '') AS bio,
            g.created_at,
            last_group.last_message_at,
            0 AS viewer_has_blocked,
            0 AS blocked_viewer,
            COUNT(DISTINCT member_rows.user_id) AS member_count,
            COALESCE(NULLIF(viewer_member.role, ''), '" . QT_GROUP_ROLE_MEMBER . "') AS viewer_role,
            viewer_member.muted_until AS viewer_muted_until,
            COALESCE(NULLIF(g.send_permission, ''), '" . QT_GROUP_SEND_PERMISSION_ALL . "') AS send_permission,
            COALESCE(NULLIF(g.add_members_permission, ''), '" . QT_GROUP_ADD_PERMISSION_ADMINS . "') AS add_members_permission,
            0 AS viewer_can_manage_group,
            0 AS viewer_can_change_roles,
            0 AS viewer_can_delete_group,
            1 AS viewer_can_send,
            0 AS viewer_is_muted,
            COALESCE(NULLIF(pref.notify_mode, ''), '" . QT_CHAT_NOTIFY_ALL . "') AS notification_mode,
            '' AS chat_nickname,
            COALESCE(NULLIF(viewer_chat_nick.nickname, ''), '') AS viewer_chat_nickname
        FROM chat_groups g
        JOIN chat_group_members viewer_member
            ON viewer_member.group_id = g.id
           AND viewer_member.user_id = ?
        LEFT JOIN chat_notification_preferences pref
            ON pref.user_id = ?
           AND pref.chat_type = 'group'
           AND pref.chat_id = g.id
        LEFT JOIN chat_user_nicknames viewer_chat_nick
            ON viewer_chat_nick.user_id = ?
           AND viewer_chat_nick.chat_type = 'group'
           AND viewer_chat_nick.chat_id = g.id
        JOIN chat_group_members member_rows
            ON member_rows.group_id = g.id
        LEFT JOIN users member_users
            ON member_users.id = member_rows.user_id
        LEFT JOIN (
            SELECT group_id, MAX(created_at) AS last_message_at
            FROM group_messages
            GROUP BY group_id
        ) last_group ON last_group.group_id = g.id
        GROUP BY
            g.id,
            g.name,
            g.description,
            g.icon_path,
            g.created_at,
            last_group.last_message_at,
            viewer_member.role,
            viewer_member.muted_until,
            g.send_permission,
            g.add_members_permission,
            viewer_chat_nick.nickname
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = qt_hydrate_group_contact_row($row);
        $row['viewer_chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['viewer_chat_nickname'] ?? ''));
    }
    unset($row);

    return $rows;
}

function qt_sort_contact_rows(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $aValue = strtotime((string)($a['last_message_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $bValue = strtotime((string)($b['last_message_at'] ?? $b['created_at'] ?? '')) ?: 0;
        if ($aValue !== $bValue) {
            return $bValue <=> $aValue;
        }

        $aName = qt_group_lowercase((string)($a['display_name'] ?? $a['username'] ?? ''));
        $bName = qt_group_lowercase((string)($b['display_name'] ?? $b['username'] ?? ''));
        return $aName <=> $bName;
    });

    return $rows;
}

function qt_fetch_all_contact_rows(PDO $pdo, int $userId): array
{
    return qt_sort_contact_rows(array_merge(
        qt_fetch_direct_contact_rows($pdo, $userId),
        qt_fetch_group_contact_rows($pdo, $userId)
    ));
}

function qt_get_group_member_record(PDO $pdo, int $groupId, int $userId): ?array
{
    if ($groupId <= 0 || $userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            group_id,
            user_id,
            COALESCE(NULLIF(role, ''), '" . QT_GROUP_ROLE_MEMBER . "') AS role,
            joined_at,
            last_seen_message_id,
            muted_until
        FROM chat_group_members
        WHERE group_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$groupId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $row['role'] = qt_group_normalize_role((string)($row['role'] ?? ''));
        $row['muted_until'] = trim((string)($row['muted_until'] ?? ''));
        $row['is_muted'] = qt_is_future_datetime($row['muted_until']);
    }

    return $row;
}

function qt_user_can_access_group(PDO $pdo, int $userId, int $groupId): bool
{
    return qt_get_group_member_record($pdo, $groupId, $userId) !== null;
}

function qt_user_can_access_group_call(PDO $pdo, int $userId, int $callId): bool
{
    if ($userId <= 0 || $callId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT group_id
        FROM group_calls
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$callId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return false;
    }

    $memberStmt = $pdo->prepare("
        SELECT 1
        FROM group_call_members
        WHERE call_id = ? AND user_id = ?
        LIMIT 1
    ");
    $memberStmt->execute([$callId, $userId]);
    if ((bool)$memberStmt->fetchColumn()) {
        return true;
    }

    $groupId = (int)($row['group_id'] ?? 0);
    if ($groupId > 0) {
        return qt_user_can_access_group($pdo, $userId, $groupId);
    }

    return false;
}

function qt_fetch_group_contact_row(PDO $pdo, int $userId, int $groupId): ?array
{
    if ($userId <= 0 || $groupId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            CONCAT('" . QT_GROUP_CHAT_PREFIX . "', g.id) AS id,
            'group' AS chat_type,
            g.id AS entity_id,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS display_name,
            '' AS username,
            COALESCE(NULLIF(g.icon_path, ''), '" . QT_DEFAULT_GROUP_CHAT_ICON . "') AS profile_pic,
            CASE
                WHEN MAX(CASE WHEN member_rows.user_id = ? THEN (" . qt_user_online_sql('member_users') . ") ELSE 0 END) > 0
                 AND MAX(CASE WHEN member_rows.user_id <> ? THEN (" . qt_user_online_sql('member_users') . ") ELSE 0 END) > 0
                THEN 1
                ELSE 0
            END AS online,
            COALESCE(g.description, '') AS bio,
            g.created_at,
            last_group.last_message_at,
            0 AS viewer_has_blocked,
            0 AS blocked_viewer,
            COUNT(DISTINCT member_rows.user_id) AS member_count,
            COALESCE(NULLIF(viewer_member.role, ''), '" . QT_GROUP_ROLE_MEMBER . "') AS viewer_role,
            viewer_member.muted_until AS viewer_muted_until,
            COALESCE(NULLIF(g.send_permission, ''), '" . QT_GROUP_SEND_PERMISSION_ALL . "') AS send_permission,
            COALESCE(NULLIF(g.add_members_permission, ''), '" . QT_GROUP_ADD_PERMISSION_ADMINS . "') AS add_members_permission,
            0 AS viewer_can_manage_group,
            0 AS viewer_can_change_roles,
            0 AS viewer_can_delete_group,
            1 AS viewer_can_send,
            0 AS viewer_is_muted,
            COALESCE(NULLIF(pref.notify_mode, ''), '" . QT_CHAT_NOTIFY_ALL . "') AS notification_mode,
            '' AS chat_nickname,
            COALESCE(NULLIF(viewer_chat_nick.nickname, ''), '') AS viewer_chat_nickname
        FROM chat_groups g
        JOIN chat_group_members viewer_member
            ON viewer_member.group_id = g.id
           AND viewer_member.user_id = ?
        LEFT JOIN chat_notification_preferences pref
            ON pref.user_id = ?
           AND pref.chat_type = 'group'
           AND pref.chat_id = g.id
        LEFT JOIN chat_user_nicknames viewer_chat_nick
            ON viewer_chat_nick.user_id = ?
           AND viewer_chat_nick.chat_type = 'group'
           AND viewer_chat_nick.chat_id = g.id
        JOIN chat_group_members member_rows
            ON member_rows.group_id = g.id
        LEFT JOIN users member_users
            ON member_users.id = member_rows.user_id
        LEFT JOIN (
            SELECT group_id, MAX(created_at) AS last_message_at
            FROM group_messages
            GROUP BY group_id
        ) last_group ON last_group.group_id = g.id
        WHERE g.id = ?
        GROUP BY
            g.id,
            g.name,
            g.description,
            g.icon_path,
            g.created_at,
            last_group.last_message_at,
            viewer_member.role,
            viewer_member.muted_until,
            g.send_permission,
            g.add_members_permission,
            viewer_chat_nick.nickname
        LIMIT 1
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $groupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row) {
        $row = qt_hydrate_group_contact_row($row);
        $row['viewer_chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['viewer_chat_nickname'] ?? ''));
    }

    return $row ?: null;
}

function qt_fetch_group_call_group_stub(PDO $pdo, int $groupId): ?array
{
    if ($groupId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            g.id,
            COALESCE(NULLIF(g.name, ''), CONCAT('Group ', g.id)) AS display_name,
            COALESCE(NULLIF(g.icon_path, ''), '" . QT_DEFAULT_GROUP_CHAT_ICON . "') AS profile_pic,
            COALESCE(g.description, '') AS bio,
            g.created_at,
            COALESCE(NULLIF(g.send_permission, ''), '" . QT_GROUP_SEND_PERMISSION_ALL . "') AS send_permission,
            COALESCE(NULLIF(g.add_members_permission, ''), '" . QT_GROUP_ADD_PERMISSION_ADMINS . "') AS add_members_permission,
            COUNT(DISTINCT member_rows.user_id) AS member_count
        FROM chat_groups g
        LEFT JOIN chat_group_members member_rows
            ON member_rows.group_id = g.id
        WHERE g.id = ?
        GROUP BY
            g.id,
            g.name,
            g.icon_path,
            g.description,
            g.created_at,
            g.send_permission,
            g.add_members_permission
        LIMIT 1
    ");
    $stmt->execute([$groupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }

    return [
        'id' => qt_build_group_chat_key((int)$row['id']),
        'chat_type' => 'group',
        'entity_id' => (int)$row['id'],
        'display_name' => (string)($row['display_name'] ?? ('Group ' . $groupId)),
        'username' => '',
        'profile_pic' => (string)($row['profile_pic'] ?? QT_DEFAULT_GROUP_CHAT_ICON),
        'online' => 0,
        'bio' => (string)($row['bio'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'last_message_at' => (string)($row['created_at'] ?? ''),
        'viewer_has_blocked' => 0,
        'blocked_viewer' => 0,
        'member_count' => (int)($row['member_count'] ?? 0),
        'viewer_role' => '',
        'viewer_muted_until' => '',
        'send_permission' => (string)($row['send_permission'] ?? QT_GROUP_SEND_PERMISSION_ALL),
        'add_members_permission' => (string)($row['add_members_permission'] ?? QT_GROUP_ADD_PERMISSION_ADMINS),
        'viewer_can_manage_group' => 0,
        'viewer_can_change_roles' => 0,
        'viewer_can_delete_group' => 0,
        'viewer_can_send' => 0,
        'viewer_is_muted' => 0,
        'notification_mode' => QT_CHAT_NOTIFY_ALL,
        'chat_nickname' => '',
        'viewer_chat_nickname' => '',
    ];
}

function qt_group_can_manage_member(?string $viewerRole, ?string $targetRole, int $viewerId, int $targetUserId): bool
{
    $normalizedViewerRole = qt_group_normalize_role($viewerRole);
    $normalizedTargetRole = qt_group_normalize_role($targetRole);

    if ($viewerId <= 0 || $targetUserId <= 0 || $viewerId === $targetUserId) {
        return false;
    }

    if ($normalizedViewerRole === QT_GROUP_ROLE_OWNER) {
        return $normalizedTargetRole !== QT_GROUP_ROLE_OWNER;
    }

    if ($normalizedViewerRole === QT_GROUP_ROLE_ADMIN) {
        return $normalizedTargetRole === QT_GROUP_ROLE_MEMBER;
    }

    return false;
}

function qt_group_can_change_member_role(?string $viewerRole, ?string $targetRole, int $viewerId, int $targetUserId): bool
{
    return qt_group_role_is_owner($viewerRole)
        && $viewerId > 0
        && $targetUserId > 0
        && $viewerId !== $targetUserId
        && qt_group_normalize_role($targetRole) !== QT_GROUP_ROLE_OWNER;
}

function qt_fetch_group_members(PDO $pdo, int $viewerId, int $groupId): array
{
    if ($viewerId <= 0 || $groupId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            member_rows.user_id,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
            u.username,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS profile_pic,
            " . qt_user_online_sql('u') . " AS online,
            COALESCE(NULLIF(chat_nick.nickname, ''), '') AS chat_nickname,
            COALESCE(NULLIF(member_rows.role, ''), '" . QT_GROUP_ROLE_MEMBER . "') AS role,
            member_rows.joined_at,
            member_rows.muted_until,
            COALESCE(NULLIF(viewer_member.role, ''), '" . QT_GROUP_ROLE_MEMBER . "') AS viewer_role
        FROM chat_group_members viewer_member
        JOIN chat_group_members member_rows
            ON member_rows.group_id = viewer_member.group_id
        JOIN users u
            ON u.id = member_rows.user_id
        LEFT JOIN chat_user_nicknames chat_nick
            ON chat_nick.user_id = member_rows.user_id
           AND chat_nick.chat_type = 'group'
           AND chat_nick.chat_id = member_rows.group_id
        WHERE viewer_member.user_id = ?
          AND viewer_member.group_id = ?
        ORDER BY
            CASE COALESCE(NULLIF(member_rows.role, ''), '" . QT_GROUP_ROLE_MEMBER . "')
                WHEN '" . QT_GROUP_ROLE_OWNER . "' THEN 0
                WHEN '" . QT_GROUP_ROLE_ADMIN . "' THEN 1
                ELSE 2
            END,
            COALESCE(NULLIF(u.display_name, ''), u.username) ASC
    ");
    $stmt->execute([$viewerId, $groupId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['profile_pic'] = $row['profile_pic'] ?: 'images/default-profile.png';
        $row['online'] = !empty($row['online']) ? 1 : 0;
        $row['chat_nickname'] = qt_normalize_chat_user_nickname((string)($row['chat_nickname'] ?? ''));
        $row['role'] = qt_group_normalize_role((string)($row['role'] ?? ''));
        $viewerRole = qt_group_normalize_role((string)($row['viewer_role'] ?? ''));
        unset($row['viewer_role']);

        $row['muted_until'] = trim((string)($row['muted_until'] ?? ''));
        $row['is_muted'] = qt_is_future_datetime($row['muted_until']) ? 1 : 0;
        $row['is_owner'] = $row['role'] === QT_GROUP_ROLE_OWNER ? 1 : 0;
        $row['is_admin'] = qt_group_role_can_manage($row['role']) ? 1 : 0;
        $row['is_viewer'] = (int)$row['user_id'] === $viewerId ? 1 : 0;
        $row['viewer_can_moderate'] = qt_group_can_manage_member($viewerRole, $row['role'], $viewerId, (int)$row['user_id']) ? 1 : 0;
        $row['viewer_can_change_role'] = qt_group_can_change_member_role($viewerRole, $row['role'], $viewerId, (int)$row['user_id']) ? 1 : 0;
    }
    unset($row);

    return $rows;
}

function qt_fetch_group_details(PDO $pdo, int $viewerId, int $groupId): ?array
{
    $group = qt_fetch_group_contact_row($pdo, $viewerId, $groupId);
    if (!$group) {
        return null;
    }

    return [
        'group' => $group,
        'members' => qt_fetch_group_members($pdo, $viewerId, $groupId),
    ];
}

function qt_get_group_send_state(PDO $pdo, int $viewerId, int $groupId): array
{
    $group = qt_fetch_group_contact_row($pdo, $viewerId, $groupId);
    if (!$group) {
        return [
            'allowed' => false,
            'error' => 'Group chat not found',
        ];
    }

    if (!empty($group['viewer_is_muted'])) {
        return [
            'allowed' => false,
            'error' => 'You are temporarily timed out in this group chat.',
            'muted_until' => (string)($group['viewer_muted_until'] ?? ''),
            'group' => $group,
        ];
    }

    if (($group['send_permission'] ?? QT_GROUP_SEND_PERMISSION_ALL) === QT_GROUP_SEND_PERMISSION_ADMINS
        && !qt_group_role_can_manage((string)($group['viewer_role'] ?? ''))
    ) {
        return [
            'allowed' => false,
            'error' => 'Only group admins can send messages right now.',
            'group' => $group,
        ];
    }

    return [
        'allowed' => true,
        'group' => $group,
    ];
}

function qt_group_call_normalize_session_status(?string $status): string
{
    return match (qt_group_lowercase(trim((string)$status))) {
        QT_GROUP_CALL_STATUS_ACTIVE => QT_GROUP_CALL_STATUS_ACTIVE,
        QT_GROUP_CALL_STATUS_ENDED => QT_GROUP_CALL_STATUS_ENDED,
        default => QT_GROUP_CALL_STATUS_RINGING,
    };
}

function qt_group_call_normalize_member_status(?string $status): string
{
    return match (qt_group_lowercase(trim((string)$status))) {
        QT_GROUP_CALL_MEMBER_ACCEPTED => QT_GROUP_CALL_MEMBER_ACCEPTED,
        QT_GROUP_CALL_MEMBER_REJECTED => QT_GROUP_CALL_MEMBER_REJECTED,
        QT_GROUP_CALL_MEMBER_LEFT => QT_GROUP_CALL_MEMBER_LEFT,
        default => QT_GROUP_CALL_MEMBER_PENDING,
    };
}

function qt_build_adhoc_group_call_label(array $participants, int $viewerId): string
{
    $otherNames = [];
    foreach ($participants as $participant) {
        $participantId = (int)($participant['user_id'] ?? 0);
        if ($participantId <= 0 || $participantId === $viewerId) {
            continue;
        }

        $name = trim((string)($participant['display_name'] ?? $participant['username'] ?? ''));
        if ($name !== '') {
            $otherNames[] = $name;
        }
    }

    $otherNames = array_values(array_unique($otherNames));
    if (!$otherNames) {
        return 'Group call';
    }

    if (count($otherNames) === 1) {
        return $otherNames[0];
    }

    $leading = array_slice($otherNames, 0, 2);
    $remaining = count($otherNames) - count($leading);
    return implode(', ', $leading) . ($remaining > 0 ? ' +' . $remaining : '');
}

function qt_build_adhoc_group_call_contact(array $call, array $participants, int $viewerId): array
{
    $anyOnline = false;
    foreach ($participants as $participant) {
        if (!empty($participant['online'])) {
            $anyOnline = true;
            break;
        }
    }

    return [
        'id' => 'call:' . (int)($call['id'] ?? 0),
        'chat_type' => 'group',
        'entity_id' => (int)($call['id'] ?? 0),
        'display_name' => qt_build_adhoc_group_call_label($participants, $viewerId),
        'username' => '',
        'profile_pic' => QT_DEFAULT_GROUP_CHAT_ICON,
        'online' => $anyOnline ? 1 : 0,
        'bio' => '',
        'created_at' => (string)($call['created_at'] ?? ''),
        'last_message_at' => (string)($call['created_at'] ?? ''),
        'viewer_has_blocked' => 0,
        'blocked_viewer' => 0,
        'member_count' => count($participants),
        'viewer_role' => '',
        'viewer_muted_until' => '',
        'send_permission' => QT_GROUP_SEND_PERMISSION_ALL,
        'add_members_permission' => QT_GROUP_ADD_PERMISSION_ALL,
        'viewer_can_manage_group' => 0,
        'viewer_can_change_roles' => 0,
        'viewer_can_delete_group' => 0,
        'viewer_can_send' => 0,
        'notification_mode' => QT_CHAT_NOTIFY_ALL,
    ];
}

function qt_find_active_group_call_for_group(PDO $pdo, int $groupId): ?array
{
    if ($groupId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, group_id, initiator_id, status, created_at, ended_at
        FROM group_calls
        WHERE group_id = ?
          AND status <> ?
          AND created_at >= (NOW() - INTERVAL 6 HOUR)
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$groupId, QT_GROUP_CALL_STATUS_ENDED]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $row['status'] = qt_group_call_normalize_session_status((string)($row['status'] ?? ''));
    }

    return $row;
}

function qt_is_private_adhoc_group_call(PDO $pdo, int $callId): bool
{
    if ($callId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT
            gc.group_id,
            COUNT(DISTINCT gcm.user_id) AS participant_count
        FROM group_calls gc
        LEFT JOIN group_call_members gcm
            ON gcm.call_id = gc.id
        WHERE gc.id = ?
        GROUP BY gc.id, gc.group_id
        LIMIT 1
    ");
    $stmt->execute([$callId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return false;
    }

    $groupId = (int)($row['group_id'] ?? 0);
    $participantCount = (int)($row['participant_count'] ?? 0);

    return $groupId <= 0 && $participantCount > 0 && $participantCount <= 2;
}

function qt_force_end_group_call_session(PDO $pdo, int $callId): void
{
    if ($callId <= 0) {
        return;
    }

    $memberUpdate = $pdo->prepare("
        UPDATE group_call_members
        SET invite_status = CASE
                WHEN invite_status = ? THEN invite_status
                ELSE ?
            END,
            left_at = CASE
                WHEN invite_status = ? THEN left_at
                ELSE COALESCE(left_at, NOW())
            END
        WHERE call_id = ?
    ");
    $memberUpdate->execute([
        QT_GROUP_CALL_MEMBER_REJECTED,
        QT_GROUP_CALL_MEMBER_LEFT,
        QT_GROUP_CALL_MEMBER_REJECTED,
        $callId
    ]);

    $callUpdate = $pdo->prepare("
        UPDATE group_calls
        SET status = ?, ended_at = COALESCE(ended_at, NOW())
        WHERE id = ?
    ");
    $callUpdate->execute([QT_GROUP_CALL_STATUS_ENDED, $callId]);
}

function qt_refresh_group_call_session_status(PDO $pdo, int $callId): ?array
{
    if ($callId <= 0) {
        return null;
    }

    $sessionStmt = $pdo->prepare("
        SELECT id, group_id, initiator_id, status, created_at, ended_at
        FROM group_calls
        WHERE id = ?
        LIMIT 1
    ");
    $sessionStmt->execute([$callId]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$session) {
        return null;
    }

    $countStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN invite_status = ? THEN 1 ELSE 0 END) AS accepted_count,
            SUM(CASE WHEN invite_status = ? THEN 1 ELSE 0 END) AS pending_count
        FROM group_call_members
        WHERE call_id = ?
    ");
    $countStmt->execute([
        QT_GROUP_CALL_MEMBER_ACCEPTED,
        QT_GROUP_CALL_MEMBER_PENDING,
        $callId
    ]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $acceptedCount = (int)($counts['accepted_count'] ?? 0);
    $pendingCount = (int)($counts['pending_count'] ?? 0);

    $nextStatus = $acceptedCount > 0
        ? QT_GROUP_CALL_STATUS_ACTIVE
        : QT_GROUP_CALL_STATUS_ENDED;

    $currentStatus = qt_group_call_normalize_session_status((string)($session['status'] ?? ''));
    if ($currentStatus !== $nextStatus) {
        if ($nextStatus === QT_GROUP_CALL_STATUS_ENDED) {
            $update = $pdo->prepare("
                UPDATE group_calls
                SET status = ?, ended_at = COALESCE(ended_at, NOW())
                WHERE id = ?
            ");
            $update->execute([$nextStatus, $callId]);
        } else {
            $update = $pdo->prepare("
                UPDATE group_calls
                SET status = ?, ended_at = NULL
                WHERE id = ?
            ");
            $update->execute([$nextStatus, $callId]);
        }
    }

    $session['status'] = $nextStatus;
    if ($nextStatus === QT_GROUP_CALL_STATUS_ENDED && empty($session['ended_at'])) {
        $session['ended_at'] = date('Y-m-d H:i:s');
    }

    return $session;
}

function qt_seed_group_call_members(PDO $pdo, int $callId, int $groupId, int $initiatorId): void
{
    if ($callId <= 0 || $groupId <= 0 || $initiatorId <= 0) {
        return;
    }

    $memberStmt = $pdo->prepare("
        SELECT user_id
        FROM chat_group_members
        WHERE group_id = ?
    ");
    $memberStmt->execute([$groupId]);
    $memberIds = $memberStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $insertStmt = $pdo->prepare("
        INSERT INTO group_call_members (call_id, user_id, invite_status, joined_at, left_at)
        VALUES (?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE
            invite_status = VALUES(invite_status),
            joined_at = VALUES(joined_at),
            left_at = NULL
    ");

    foreach ($memberIds as $memberIdRaw) {
        $memberId = (int)$memberIdRaw;
        if ($memberId <= 0) {
            continue;
        }

        $isInitiator = $memberId === $initiatorId;
        $insertStmt->execute([
            $callId,
            $memberId,
            $isInitiator ? QT_GROUP_CALL_MEMBER_ACCEPTED : QT_GROUP_CALL_MEMBER_PENDING,
            $isInitiator ? date('Y-m-d H:i:s') : null
        ]);
    }
}

function qt_create_group_call_session(PDO $pdo, int $groupId, int $initiatorId): int
{
    $insert = $pdo->prepare("
        INSERT INTO group_calls (group_id, initiator_id, status, created_at, ended_at)
        VALUES (?, ?, ?, NOW(), NULL)
    ");
    $insert->execute([$groupId, $initiatorId, QT_GROUP_CALL_STATUS_RINGING]);
    $callId = (int)$pdo->lastInsertId();
    if ($callId <= 0) {
        throw new RuntimeException('Unable to create the group call session.');
    }

    qt_seed_group_call_members($pdo, $callId, $groupId, $initiatorId);
    qt_refresh_group_call_session_status($pdo, $callId);

    return $callId;
}

function qt_seed_adhoc_group_call_members(PDO $pdo, int $callId, int $initiatorId, array $participantIds): void
{
    if ($callId <= 0 || $initiatorId <= 0) {
        return;
    }

    $normalizedParticipantIds = [];
    foreach ($participantIds as $participantIdRaw) {
        $participantId = (int)$participantIdRaw;
        if ($participantId > 0 && $participantId !== $initiatorId) {
            $normalizedParticipantIds[] = $participantId;
        }
    }

    $normalizedParticipantIds = array_values(array_unique($normalizedParticipantIds));
    qt_ensure_group_call_member($pdo, $callId, 0, $initiatorId, QT_GROUP_CALL_MEMBER_ACCEPTED);

    foreach ($normalizedParticipantIds as $participantId) {
        qt_ensure_group_call_member($pdo, $callId, 0, $participantId, QT_GROUP_CALL_MEMBER_PENDING);
    }
}

function qt_create_adhoc_group_call_session(PDO $pdo, int $initiatorId, array $participantIds): int
{
    if ($initiatorId <= 0) {
        throw new RuntimeException('Unable to create the group call session.');
    }

    $insert = $pdo->prepare("
        INSERT INTO group_calls (group_id, initiator_id, status, created_at, ended_at)
        VALUES (0, ?, ?, NOW(), NULL)
    ");
    $insert->execute([$initiatorId, QT_GROUP_CALL_STATUS_RINGING]);
    $callId = (int)$pdo->lastInsertId();
    if ($callId <= 0) {
        throw new RuntimeException('Unable to create the group call session.');
    }

    qt_seed_adhoc_group_call_members($pdo, $callId, $initiatorId, $participantIds);
    qt_refresh_group_call_session_status($pdo, $callId);

    return $callId;
}

function qt_ensure_group_call_member(PDO $pdo, int $callId, int $groupId, int $userId, string $status = QT_GROUP_CALL_MEMBER_PENDING): void
{
    if ($callId <= 0 || $userId <= 0) {
        return;
    }

    $status = qt_group_call_normalize_member_status($status);
    $isAccepted = $status === QT_GROUP_CALL_MEMBER_ACCEPTED;
    $stmt = $pdo->prepare("
        INSERT INTO group_call_members (call_id, user_id, invite_status, joined_at, left_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            invite_status = VALUES(invite_status),
            joined_at = VALUES(joined_at),
            left_at = VALUES(left_at)
    ");
    $stmt->execute([
        $callId,
        $userId,
        $status,
        $isAccepted ? date('Y-m-d H:i:s') : null,
        in_array($status, [QT_GROUP_CALL_MEMBER_REJECTED, QT_GROUP_CALL_MEMBER_LEFT], true) ? date('Y-m-d H:i:s') : null
    ]);
}

function qt_get_group_call_member_record(PDO $pdo, int $callId, int $userId): ?array
{
    if ($callId <= 0 || $userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT call_id, user_id, invite_status, joined_at, left_at
        FROM group_call_members
        WHERE call_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$callId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $row['invite_status'] = qt_group_call_normalize_member_status((string)($row['invite_status'] ?? ''));
    }

    return $row;
}

function qt_set_group_call_member_status(PDO $pdo, int $callId, int $userId, string $status): void
{
    $status = qt_group_call_normalize_member_status($status);
    $isAccepted = $status === QT_GROUP_CALL_MEMBER_ACCEPTED;
    $leftAt = in_array($status, [QT_GROUP_CALL_MEMBER_REJECTED, QT_GROUP_CALL_MEMBER_LEFT], true)
        ? date('Y-m-d H:i:s')
        : null;
    $joinedAt = $isAccepted ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("
        UPDATE group_call_members
        SET invite_status = ?, joined_at = ?, left_at = ?
        WHERE call_id = ? AND user_id = ?
    ");
    $stmt->execute([$status, $joinedAt, $leftAt, $callId, $userId]);
}

function qt_fetch_group_call_participants(PDO $pdo, int $viewerId, int $callId): array
{
    if ($viewerId <= 0 || $callId <= 0) {
        return [];
    }

    $sessionStmt = $pdo->prepare("
        SELECT group_id
        FROM group_calls
        WHERE id = ?
        LIMIT 1
    ");
    $sessionStmt->execute([$callId]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$session) {
        return [];
    }

    $groupId = (int)($session['group_id'] ?? 0);
    if ($groupId <= 0) {
        $stmt = $pdo->prepare("
            SELECT
                gcm.user_id,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
                u.username,
                COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS profile_pic,
                " . qt_user_online_sql('u') . " AS online,
                gcm.invite_status,
                gcm.joined_at,
                gcm.left_at,
                '" . QT_GROUP_ROLE_MEMBER . "' AS role
            FROM group_call_members gcm
            JOIN group_call_members viewer_call_member
                ON viewer_call_member.call_id = gcm.call_id
               AND viewer_call_member.user_id = ?
            JOIN users u
                ON u.id = gcm.user_id
            WHERE gcm.call_id = ?
            ORDER BY
                CASE gcm.invite_status
                    WHEN '" . QT_GROUP_CALL_MEMBER_ACCEPTED . "' THEN 0
                    WHEN '" . QT_GROUP_CALL_MEMBER_PENDING . "' THEN 1
                    ELSE 2
                END,
                COALESCE(NULLIF(u.display_name, ''), u.username) ASC
        ");
        $stmt->execute([$viewerId, $callId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['user_id'] = (int)($row['user_id'] ?? 0);
            $row['online'] = !empty($row['online']) ? 1 : 0;
            $row['invite_status'] = qt_group_call_normalize_member_status((string)($row['invite_status'] ?? ''));
            $row['role'] = QT_GROUP_ROLE_MEMBER;
            $row['is_viewer'] = $row['user_id'] === $viewerId ? 1 : 0;
        }
        unset($row);

        return $rows;
    }

    $stmt = $pdo->prepare("
        SELECT
            gcm.user_id,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
            u.username,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS profile_pic,
            " . qt_user_online_sql('u') . " AS online,
            gcm.invite_status,
            gcm.joined_at,
            gcm.left_at,
            COALESCE(NULLIF(cgm.role, ''), '" . QT_GROUP_ROLE_MEMBER . "') AS role
        FROM group_call_members gcm
        JOIN group_calls gc
            ON gc.id = gcm.call_id
        JOIN group_call_members viewer_call_member
            ON viewer_call_member.call_id = gc.id
           AND viewer_call_member.user_id = ?
        LEFT JOIN chat_group_members cgm
            ON cgm.group_id = gc.group_id
           AND cgm.user_id = gcm.user_id
        JOIN users u
            ON u.id = gcm.user_id
        WHERE gcm.call_id = ?
        ORDER BY
            CASE gcm.invite_status
                WHEN '" . QT_GROUP_CALL_MEMBER_ACCEPTED . "' THEN 0
                WHEN '" . QT_GROUP_CALL_MEMBER_PENDING . "' THEN 1
                ELSE 2
            END,
            CASE COALESCE(NULLIF(cgm.role, ''), '" . QT_GROUP_ROLE_MEMBER . "')
                WHEN '" . QT_GROUP_ROLE_OWNER . "' THEN 0
                WHEN '" . QT_GROUP_ROLE_ADMIN . "' THEN 1
                ELSE 2
            END,
            COALESCE(NULLIF(u.display_name, ''), u.username) ASC
    ");
    $stmt->execute([$viewerId, $callId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['user_id'] = (int)($row['user_id'] ?? 0);
        $row['online'] = !empty($row['online']) ? 1 : 0;
        $row['invite_status'] = qt_group_call_normalize_member_status((string)($row['invite_status'] ?? ''));
        $row['role'] = qt_group_normalize_role((string)($row['role'] ?? ''));
        $row['is_viewer'] = $row['user_id'] === $viewerId ? 1 : 0;
    }
    unset($row);

    return $rows;
}

function qt_fetch_group_call_details(PDO $pdo, int $viewerId, int $callId): ?array
{
    if ($viewerId <= 0 || $callId <= 0) {
        return null;
    }

    $session = qt_refresh_group_call_session_status($pdo, $callId);
    if (!$session) {
        return null;
    }

    $groupId = (int)($session['group_id'] ?? 0);
    if ($groupId > 0) {
        $stmt = $pdo->prepare("
            SELECT
                gc.id,
                gc.group_id,
                gc.initiator_id,
                gc.status,
                gc.created_at,
                gc.ended_at,
                COALESCE(NULLIF(gcm.invite_status, ''), '" . QT_GROUP_CALL_MEMBER_PENDING . "') AS viewer_status,
                gcm.joined_at AS viewer_joined_at,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS initiator_display_name,
                COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS initiator_profile_pic
            FROM group_calls gc
            JOIN group_call_members viewer_call_member
                ON viewer_call_member.call_id = gc.id
               AND viewer_call_member.user_id = ?
            LEFT JOIN group_call_members gcm
                ON gcm.call_id = gc.id
               AND gcm.user_id = ?
            JOIN users u
                ON u.id = gc.initiator_id
            WHERE gc.id = ?
            LIMIT 1
        ");
        $stmt->execute([$viewerId, $viewerId, $callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stmt = $pdo->prepare("
            SELECT
                gc.id,
                gc.group_id,
                gc.initiator_id,
                gc.status,
                gc.created_at,
                gc.ended_at,
                COALESCE(NULLIF(gcm.invite_status, ''), '" . QT_GROUP_CALL_MEMBER_PENDING . "') AS viewer_status,
                gcm.joined_at AS viewer_joined_at,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS initiator_display_name,
                COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS initiator_profile_pic
            FROM group_calls gc
            JOIN group_call_members viewer_call_member
                ON viewer_call_member.call_id = gc.id
               AND viewer_call_member.user_id = ?
            LEFT JOIN group_call_members gcm
                ON gcm.call_id = gc.id
               AND gcm.user_id = ?
            JOIN users u
                ON u.id = gc.initiator_id
            WHERE gc.id = ?
            LIMIT 1
        ");
        $stmt->execute([$viewerId, $viewerId, $callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$call) {
        return null;
    }

    $call['id'] = (int)($call['id'] ?? 0);
    $call['group_id'] = (int)($call['group_id'] ?? 0);
    $call['initiator_id'] = (int)($call['initiator_id'] ?? 0);
    $call['status'] = qt_group_call_normalize_session_status((string)($call['status'] ?? ''));
    $call['viewer_status'] = qt_group_call_normalize_member_status((string)($call['viewer_status'] ?? ''));
    // Add Unix timestamp so JS can compute elapsed time without timezone ambiguity
    $call['created_at_unix'] = $call['created_at'] ? (int)strtotime((string)$call['created_at']) : time();

    $participants = qt_fetch_group_call_participants($pdo, $viewerId, $callId);
    if (!$participants) {
        return null;
    }

    $group = $groupId > 0
        ? (qt_fetch_group_contact_row($pdo, $viewerId, (int)$call['group_id'])
            ?: qt_fetch_group_call_group_stub($pdo, (int)$call['group_id']))
        : qt_build_adhoc_group_call_contact($call, $participants, $viewerId);
    if (!$group) {
        return null;
    }

    return [
        'call' => $call,
        'group' => $group,
        'participants' => $participants,
    ];
}
