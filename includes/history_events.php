<?php
declare(strict_types=1);

const QT_GROUP_NEWS_MESSAGE_PREFIX = '__GROUP_NEWS__:';
const QT_CALL_ONLY_GROUP_DESCRIPTION = '__CALL_ONLY_GROUP__';

function qt_history_event_normalize_value(?string $value, int $maxLength = 255): ?string
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    if ($normalized === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return (string)mb_substr($normalized, 0, $maxLength);
    }

    return substr($normalized, 0, $maxLength);
}

function qt_log_history_event(PDO $pdo, array $event): void
{
    ensure_history_events_schema($pdo);

    $actorUserId = (int)($event['actor_user_id'] ?? 0);
    $subjectUserId = isset($event['subject_user_id']) ? (int)$event['subject_user_id'] : 0;
    $chatType = trim((string)($event['chat_type'] ?? ''));
    $chatId = isset($event['chat_id']) ? (int)$event['chat_id'] : 0;
    $eventType = trim((string)($event['event_type'] ?? ''));
    $eventValue = qt_history_event_normalize_value(
        array_key_exists('event_value', $event) ? (string)$event['event_value'] : null
    );

    if ($actorUserId <= 0 || $eventType === '') {
        return;
    }

    if (!in_array($chatType, ['direct', 'group'], true)) {
        $chatType = null;
    }

    if ($chatId <= 0) {
        $chatId = null;
    }

    if ($subjectUserId <= 0) {
        $subjectUserId = null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_history_events (
            actor_user_id,
            subject_user_id,
            chat_type,
            chat_id,
            event_type,
            event_value,
            created_at
        ) VALUES (
            :actor_user_id,
            :subject_user_id,
            :chat_type,
            :chat_id,
            :event_type,
            :event_value,
            NOW()
        )
    ");
    $stmt->execute([
        ':actor_user_id' => $actorUserId,
        ':subject_user_id' => $subjectUserId,
        ':chat_type' => $chatType,
        ':chat_id' => $chatId,
        ':event_type' => $eventType,
        ':event_value' => $eventValue,
    ]);
}

function qt_history_describe_message_body(?string $rawMessage, int $maxLength = 140): string
{
    $message = trim((string)$rawMessage);
    if ($message === '') {
        return 'Message';
    }

    if (str_starts_with($message, '__ATTACHMENT__:')) {
        $payload = json_decode(substr($message, strlen('__ATTACHMENT__:')), true);
        if (is_array($payload)) {
            $kind = strtolower(trim((string)($payload['kind'] ?? 'attachment')));
            if ($kind === 'audio') {
                return 'Voice message';
            }
            if ($kind === 'video') {
                $caption = trim((string)($payload['caption'] ?? ''));
                if ($caption !== '') {
                    return qt_history_describe_message_body($caption, $maxLength);
                }
                return 'Video';
            }
            if ($kind === 'image') {
                $caption = trim((string)($payload['caption'] ?? ''));
                if ($caption !== '') {
                    return qt_history_describe_message_body($caption, $maxLength);
                }
                return 'Photo';
            }

            $name = trim((string)($payload['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Attachment';
    }

    if (str_starts_with($message, '__POLL__:')) {
        return 'Poll';
    }

    if (str_starts_with($message, '__GAME__:')) {
        $payload = json_decode(substr($message, strlen('__GAME__:')), true);
        if (is_array($payload)) {
            $label = trim((string)($payload['label'] ?? $payload['game_type'] ?? 'Game'));
            return $label !== '' ? $label : 'Game';
        }
        return 'Game';
    }

    if (str_starts_with($message, '__GROUP_CALL__:')) {
        return 'Group call';
    }

    if (str_starts_with($message, '__CALL_EVENT__:')) {
        return 'Call';
    }

    if (str_starts_with($message, QT_GROUP_NEWS_MESSAGE_PREFIX)) {
        return 'Group chat news';
    }

    $plainText = trim(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plainText = trim((string)(preg_replace('/\s+/u', ' ', $plainText) ?? $plainText));
    if ($plainText === '') {
        return 'Message';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($plainText) > $maxLength) {
            return (string)mb_substr($plainText, 0, max(1, $maxLength - 1)) . '…';
        }
        return $plainText;
    }

    if (strlen($plainText) > $maxLength) {
        return substr($plainText, 0, max(1, $maxLength - 1)) . '…';
    }

    return $plainText;
}

function qt_history_fetch_user_display_name(PDO $pdo, int $userId, string $fallback = 'User'): string
{
    if ($userId <= 0) {
        return $fallback;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(display_name, ''), username) AS display_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $name = trim((string)($stmt->fetchColumn() ?: ''));

    return $name !== '' ? $name : $fallback;
}

function qt_is_call_only_group_description(?string $description): bool
{
    return trim((string)$description) === QT_CALL_ONLY_GROUP_DESCRIPTION;
}

function qt_format_history_duration_minutes(int $minutes): string
{
    $normalizedMinutes = max(1, $minutes);
    $days = intdiv($normalizedMinutes, 1440);
    $remainingAfterDays = $normalizedMinutes % 1440;
    $hours = intdiv($remainingAfterDays, 60);
    $remainingMinutes = $remainingAfterDays % 60;
    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
    }
    if ($hours > 0) {
        $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    if ($remainingMinutes > 0 || !$parts) {
        $parts[] = $remainingMinutes . ' minute' . ($remainingMinutes === 1 ? '' : 's');
    }

    return implode(' ', $parts);
}

function qt_history_group_role_label(?string $role): string
{
    $normalizedRole = strtolower(trim((string)$role));
    if ($normalizedRole === 'owner') {
        return 'Owner';
    }
    if ($normalizedRole === 'admin') {
        return 'Admin';
    }
    return 'Member';
}

function qt_build_group_news_message(array $payload): string
{
    return QT_GROUP_NEWS_MESSAGE_PREFIX . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function qt_insert_group_news_message(PDO $pdo, int $groupId, int $senderUserId, array $payload): void
{
    if ($groupId <= 0 || $senderUserId <= 0 || empty($payload['type'])) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO group_messages (group_id, sender_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([
        $groupId,
        $senderUserId,
        qt_build_group_news_message($payload),
    ]);
}
