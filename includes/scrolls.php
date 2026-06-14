<?php
declare(strict_types=1);

function qt_scrolls_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qt_scrolls_resolve_user_id(PDO $pdo, string $token): int
{
    $normalizedToken = trim($token);
    if ($normalizedToken === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
    $stmt->execute([$normalizedToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($session['user_id'] ?? 0);
}

function qt_scrolls_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
        return (bool)($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function qt_scrolls_parse_ini_size_bytes($value): int
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return 0;
    }

    if (is_numeric($normalized)) {
        return max(0, (int)$normalized);
    }

    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmg])$/i', $normalized, $matches)) {
        return 0;
    }

    $number = (float)$matches[1];
    $unit = strtolower((string)$matches[2]);
    $multiplier = 1;
    switch ($unit) {
        case 'g':
            $multiplier = 1024 * 1024 * 1024;
            break;
        case 'm':
            $multiplier = 1024 * 1024;
            break;
        case 'k':
            $multiplier = 1024;
            break;
    }

    return max(0, (int)floor($number * $multiplier));
}

function qt_scrolls_format_bytes(int $bytes): string
{
    $normalizedBytes = max(0, $bytes);
    if ($normalizedBytes < 1024) {
        return $normalizedBytes . ' B';
    }

    $units = ['KB', 'MB', 'GB'];
    $value = $normalizedBytes / 1024;
    $unitIndex = 0;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex += 1;
    }

    return ($value >= 10 ? number_format($value, 0) : number_format($value, 1)) . ' ' . $units[$unitIndex];
}

function qt_scrolls_max_upload_bytes(int $preferredMaxBytes = 33554432): int
{
    $limit = $preferredMaxBytes > 0 ? $preferredMaxBytes : PHP_INT_MAX;

    $uploadMaxBytes = qt_scrolls_parse_ini_size_bytes(ini_get('upload_max_filesize'));
    if ($uploadMaxBytes > 0) {
        $limit = min($limit, $uploadMaxBytes);
    }

    $postMaxBytes = qt_scrolls_parse_ini_size_bytes(ini_get('post_max_size'));
    if ($postMaxBytes > 0) {
        $postSafeBytes = $postMaxBytes > 1048576 ? $postMaxBytes - 1048576 : $postMaxBytes;
        $limit = min($limit, $postSafeBytes);
    }

    if ($limit === PHP_INT_MAX) {
        $limit = $preferredMaxBytes > 0 ? $preferredMaxBytes : 33554432;
    }

    return max(1, $limit);
}

function qt_scrolls_normalize_profile_pic(?string $value, string $fallback = 'images/default-profile.png'): string
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return $fallback;
    }

    return str_replace('\\', '/', $normalized);
}

function qt_scrolls_fetch_reaction_summary(PDO $pdo, int $scrollId): array
{
    if (!qt_scrolls_table_exists($pdo, 'scroll_reactions')) {
        return [
            'like_count' => 0,
            'dislike_count' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN reaction_type = 'like' THEN 1 ELSE 0 END), 0) AS like_count,
            COALESCE(SUM(CASE WHEN reaction_type = 'dislike' THEN 1 ELSE 0 END), 0) AS dislike_count
        FROM scroll_reactions
        WHERE scroll_id = ?
    ");
    $stmt->execute([$scrollId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'like_count' => max(0, (int)($row['like_count'] ?? 0)),
        'dislike_count' => max(0, (int)($row['dislike_count'] ?? 0)),
    ];
}

function qt_scrolls_fetch_follower_count(PDO $pdo, int $targetUserId): int
{
    if (!qt_scrolls_table_exists($pdo, 'scroll_follows')) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM scroll_follows WHERE target_user_id = ?');
    $stmt->execute([$targetUserId]);
    return max(0, (int)$stmt->fetchColumn());
}

function qt_scrolls_scroll_exists(PDO $pdo, int $scrollId): bool
{
    if ($scrollId <= 0 || !qt_scrolls_table_exists($pdo, 'scrolls')) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT id FROM scrolls WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$scrollId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function qt_scrolls_fetch_comment_count(PDO $pdo, int $scrollId): int
{
    if ($scrollId <= 0 || !qt_scrolls_table_exists($pdo, 'scroll_comments')) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM scroll_comments WHERE scroll_id = ?');
    $stmt->execute([$scrollId]);
    return max(0, (int)$stmt->fetchColumn());
}

function qt_scrolls_fetch_comment_reaction_summary(PDO $pdo, int $commentId): array
{
    if ($commentId <= 0 || !qt_scrolls_table_exists($pdo, 'scroll_comment_reactions')) {
        return [
            'like_count' => 0,
            'dislike_count' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN reaction_type = 'like' THEN 1 ELSE 0 END), 0) AS like_count,
            COALESCE(SUM(CASE WHEN reaction_type = 'dislike' THEN 1 ELSE 0 END), 0) AS dislike_count
        FROM scroll_comment_reactions
        WHERE comment_id = ?
    ");
    $stmt->execute([$commentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'like_count' => max(0, (int)($row['like_count'] ?? 0)),
        'dislike_count' => max(0, (int)($row['dislike_count'] ?? 0)),
    ];
}

function qt_scrolls_normalize_comment_row(array $row): array
{
    $userReaction = trim((string)($row['user_reaction'] ?? ''));
    if (!in_array($userReaction, ['like', 'dislike'], true)) {
        $userReaction = '';
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'scroll_id' => (int)($row['scroll_id'] ?? 0),
        'user_id' => (int)($row['user_id'] ?? 0),
        'reply_to_comment_id' => max(0, (int)($row['reply_to_comment_id'] ?? 0)),
        'thread_root_comment_id' => max(0, (int)($row['thread_root_comment_id'] ?? ($row['reply_to_comment_id'] ?? $row['id'] ?? 0))),
        'comment_text' => trim((string)($row['comment_text'] ?? '')),
        'created_at' => (string)($row['created_at'] ?? ''),
        'commenter_display_name' => trim((string)($row['commenter_display_name'] ?? '')) !== ''
            ? (string)$row['commenter_display_name']
            : 'QuillTalk user',
        'commenter_profile_pic' => qt_scrolls_normalize_profile_pic((string)($row['commenter_profile_pic'] ?? '')),
        'reply_to_display_name' => trim((string)($row['reply_to_display_name'] ?? '')),
        'reply_to_comment_text' => trim((string)($row['reply_to_comment_text'] ?? '')),
        'like_count' => max(0, (int)($row['like_count'] ?? 0)),
        'dislike_count' => max(0, (int)($row['dislike_count'] ?? 0)),
        'user_reaction' => $userReaction,
    ];
}

function qt_scrolls_fetch_comments(PDO $pdo, int $scrollId, int $viewerUserId = 0, int $limit = 200): array
{
    if ($scrollId <= 0 || !qt_scrolls_table_exists($pdo, 'scroll_comments')) {
        return [];
    }

    $normalizedLimit = max(1, min(300, $limit));
    $hasCommentReactionsTable = qt_scrolls_table_exists($pdo, 'scroll_comment_reactions');

    $reactionSelect = $hasCommentReactionsTable
        ? "
            COALESCE(comment_like_counts.like_count, 0) AS like_count,
            COALESCE(comment_dislike_counts.dislike_count, 0) AS dislike_count,
            COALESCE(viewer_comment_reaction.reaction_type, '') AS user_reaction"
        : "
            0 AS like_count,
            0 AS dislike_count,
            '' AS user_reaction";

    $reactionJoins = $hasCommentReactionsTable
        ? "
        LEFT JOIN (
            SELECT comment_id, COUNT(*) AS like_count
            FROM scroll_comment_reactions
            WHERE reaction_type = 'like'
            GROUP BY comment_id
        ) comment_like_counts
            ON comment_like_counts.comment_id = c.id
        LEFT JOIN (
            SELECT comment_id, COUNT(*) AS dislike_count
            FROM scroll_comment_reactions
            WHERE reaction_type = 'dislike'
            GROUP BY comment_id
        ) comment_dislike_counts
            ON comment_dislike_counts.comment_id = c.id
        LEFT JOIN scroll_comment_reactions viewer_comment_reaction
            ON viewer_comment_reaction.comment_id = c.id
           AND viewer_comment_reaction.user_id = :viewer_comment_reaction_user_id"
        : '';

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.scroll_id,
            c.user_id,
            COALESCE(c.reply_to_comment_id, 0) AS reply_to_comment_id,
            CASE
                WHEN c.reply_to_comment_id IS NULL OR c.reply_to_comment_id = 0 THEN c.id
                ELSE COALESCE(NULLIF(parent.reply_to_comment_id, 0), parent.id, c.reply_to_comment_id)
            END AS thread_root_comment_id,
            c.comment_text,
            c.created_at,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS commenter_display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS commenter_profile_pic,
            COALESCE(NULLIF(parent_user.display_name, ''), parent_user.username, '') AS reply_to_display_name,
            COALESCE(parent.comment_text, '') AS reply_to_comment_text,
{$reactionSelect}
        FROM scroll_comments c
        JOIN users u
            ON u.id = c.user_id
        LEFT JOIN scroll_comments parent
            ON parent.id = c.reply_to_comment_id
        LEFT JOIN users parent_user
            ON parent_user.id = parent.user_id
{$reactionJoins}
        WHERE c.scroll_id = :scroll_id
        ORDER BY
            CASE
                WHEN c.reply_to_comment_id IS NULL OR c.reply_to_comment_id = 0 THEN c.created_at
                ELSE parent.created_at
            END DESC,
            CASE
                WHEN c.reply_to_comment_id IS NULL OR c.reply_to_comment_id = 0 THEN c.id
                ELSE parent.id
            END DESC,
            CASE
                WHEN c.reply_to_comment_id IS NULL OR c.reply_to_comment_id = 0 THEN 0
                ELSE 1
            END ASC,
            c.created_at ASC,
            c.id ASC
        LIMIT :comment_limit
    ");
    if ($hasCommentReactionsTable) {
        $stmt->bindValue(':viewer_comment_reaction_user_id', $viewerUserId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':scroll_id', $scrollId, PDO::PARAM_INT);
    $stmt->bindValue(':comment_limit', $normalizedLimit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map('qt_scrolls_normalize_comment_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function qt_scrolls_fetch_comment_by_id(PDO $pdo, int $commentId, int $viewerUserId = 0): ?array
{
    if ($commentId <= 0 || !qt_scrolls_table_exists($pdo, 'scroll_comments')) {
        return null;
    }

    $hasCommentReactionsTable = qt_scrolls_table_exists($pdo, 'scroll_comment_reactions');
    $reactionSelect = $hasCommentReactionsTable
        ? "
            COALESCE(comment_like_counts.like_count, 0) AS like_count,
            COALESCE(comment_dislike_counts.dislike_count, 0) AS dislike_count,
            COALESCE(viewer_comment_reaction.reaction_type, '') AS user_reaction"
        : "
            0 AS like_count,
            0 AS dislike_count,
            '' AS user_reaction";

    $reactionJoins = $hasCommentReactionsTable
        ? "
        LEFT JOIN (
            SELECT comment_id, COUNT(*) AS like_count
            FROM scroll_comment_reactions
            WHERE reaction_type = 'like'
            GROUP BY comment_id
        ) comment_like_counts
            ON comment_like_counts.comment_id = c.id
        LEFT JOIN (
            SELECT comment_id, COUNT(*) AS dislike_count
            FROM scroll_comment_reactions
            WHERE reaction_type = 'dislike'
            GROUP BY comment_id
        ) comment_dislike_counts
            ON comment_dislike_counts.comment_id = c.id
        LEFT JOIN scroll_comment_reactions viewer_comment_reaction
            ON viewer_comment_reaction.comment_id = c.id
           AND viewer_comment_reaction.user_id = :viewer_comment_reaction_user_id"
        : '';

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.scroll_id,
            c.user_id,
            COALESCE(c.reply_to_comment_id, 0) AS reply_to_comment_id,
            CASE
                WHEN c.reply_to_comment_id IS NULL OR c.reply_to_comment_id = 0 THEN c.id
                ELSE COALESCE(NULLIF(parent.reply_to_comment_id, 0), parent.id, c.reply_to_comment_id)
            END AS thread_root_comment_id,
            c.comment_text,
            c.created_at,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS commenter_display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS commenter_profile_pic,
            COALESCE(NULLIF(parent_user.display_name, ''), parent_user.username, '') AS reply_to_display_name,
            COALESCE(parent.comment_text, '') AS reply_to_comment_text,
{$reactionSelect}
        FROM scroll_comments c
        JOIN users u
            ON u.id = c.user_id
        LEFT JOIN scroll_comments parent
            ON parent.id = c.reply_to_comment_id
        LEFT JOIN users parent_user
            ON parent_user.id = parent.user_id
{$reactionJoins}
        WHERE c.id = :comment_id
        LIMIT 1
    ");
    if ($hasCommentReactionsTable) {
        $stmt->bindValue(':viewer_comment_reaction_user_id', $viewerUserId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':comment_id', $commentId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? qt_scrolls_normalize_comment_row($row) : null;
}

function qt_scrolls_fetch_comment_thread_target(PDO $pdo, int $commentId): ?array
{
    if ($commentId <= 0 || !qt_scrolls_table_exists($pdo, 'scroll_comments')) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            scroll_id,
            user_id,
            COALESCE(reply_to_comment_id, 0) AS reply_to_comment_id,
            comment_text
        FROM scroll_comments
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$commentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'scroll_id' => (int)($row['scroll_id'] ?? 0),
        'user_id' => (int)($row['user_id'] ?? 0),
        'reply_to_comment_id' => max(0, (int)($row['reply_to_comment_id'] ?? 0)),
        'comment_text' => trim((string)($row['comment_text'] ?? '')),
    ];
}

function qt_scrolls_fetch_feed_items(PDO $pdo, int $viewerUserId, int $limit = 200): array
{
    if (!qt_scrolls_table_exists($pdo, 'scrolls')) {
        return [];
    }

    $normalizedLimit = max(1, min(200, $limit));
    $hasReactionsTable = qt_scrolls_table_exists($pdo, 'scroll_reactions');
    $hasFollowsTable = qt_scrolls_table_exists($pdo, 'scroll_follows');
    $hasCommentsTable = qt_scrolls_table_exists($pdo, 'scroll_comments');

    $reactionSelect = $hasReactionsTable
        ? "
            COALESCE(like_counts.like_count, 0) AS like_count,
            COALESCE(dislike_counts.dislike_count, 0) AS dislike_count,
            COALESCE(viewer_reaction.reaction_type, '') AS user_reaction,"
        : "
            0 AS like_count,
            0 AS dislike_count,
            '' AS user_reaction,";

    $followSelect = $hasFollowsTable
        ? "
            COALESCE(follow_counts.follower_count, 0) AS follower_count,
            CASE
                WHEN viewer_follow.follower_user_id IS NULL THEN 0
                ELSE 1
            END AS is_following"
        : "
            0 AS follower_count,
            0 AS is_following";

    $commentSelect = $hasCommentsTable
        ? "
            COALESCE(comment_counts.comment_count, 0) AS comment_count"
        : "
            0 AS comment_count";

    $reactionJoins = $hasReactionsTable
        ? "
        LEFT JOIN (
            SELECT scroll_id, COUNT(*) AS like_count
            FROM scroll_reactions
            WHERE reaction_type = 'like'
            GROUP BY scroll_id
        ) like_counts
            ON like_counts.scroll_id = s.id
        LEFT JOIN (
            SELECT scroll_id, COUNT(*) AS dislike_count
            FROM scroll_reactions
            WHERE reaction_type = 'dislike'
            GROUP BY scroll_id
        ) dislike_counts
            ON dislike_counts.scroll_id = s.id
        LEFT JOIN scroll_reactions viewer_reaction
            ON viewer_reaction.scroll_id = s.id
           AND viewer_reaction.user_id = :viewer_reaction_user_id"
        : '';

    $followJoins = $hasFollowsTable
        ? "
        LEFT JOIN (
            SELECT target_user_id, COUNT(*) AS follower_count
            FROM scroll_follows
            GROUP BY target_user_id
        ) follow_counts
            ON follow_counts.target_user_id = s.user_id
        LEFT JOIN scroll_follows viewer_follow
            ON viewer_follow.follower_user_id = :viewer_follow_user_id
           AND viewer_follow.target_user_id = s.user_id"
        : '';

    $commentJoins = $hasCommentsTable
        ? "
        LEFT JOIN (
            SELECT scroll_id, COUNT(*) AS comment_count
            FROM scroll_comments
            GROUP BY scroll_id
        ) comment_counts
            ON comment_counts.scroll_id = s.id"
        : '';

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.user_id AS uploader_id,
            s.video_path,
            s.title,
            s.caption,
            s.created_at,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS uploader_display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS uploader_profile_pic,
{$reactionSelect}
{$followSelect},
{$commentSelect}
        FROM scrolls s
        JOIN users u
            ON u.id = s.user_id
{$reactionJoins}
{$followJoins}
{$commentJoins}
        WHERE s.is_active = 1
        ORDER BY s.created_at DESC, s.id DESC
        LIMIT :feed_limit
    ");
    if ($hasReactionsTable) {
        $stmt->bindValue(':viewer_reaction_user_id', $viewerUserId, PDO::PARAM_INT);
    }
    if ($hasFollowsTable) {
        $stmt->bindValue(':viewer_follow_user_id', $viewerUserId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':feed_limit', $normalizedLimit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map(static function (array $row): array {
        $userReaction = trim((string)($row['user_reaction'] ?? ''));
        if (!in_array($userReaction, ['like', 'dislike'], true)) {
            $userReaction = '';
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'uploader_id' => (int)($row['uploader_id'] ?? 0),
            'uploader_display_name' => trim((string)($row['uploader_display_name'] ?? '')) !== ''
                ? (string)$row['uploader_display_name']
                : 'QuillTalk user',
            'uploader_profile_pic' => qt_scrolls_normalize_profile_pic((string)($row['uploader_profile_pic'] ?? '')),
            'video_path' => str_replace('\\', '/', trim((string)($row['video_path'] ?? ''))),
            'title' => trim((string)($row['title'] ?? '')),
            'caption' => trim((string)($row['caption'] ?? '')),
            'created_at' => (string)($row['created_at'] ?? ''),
            'like_count' => max(0, (int)($row['like_count'] ?? 0)),
            'dislike_count' => max(0, (int)($row['dislike_count'] ?? 0)),
            'follower_count' => max(0, (int)($row['follower_count'] ?? 0)),
            'comment_count' => max(0, (int)($row['comment_count'] ?? 0)),
            'user_reaction' => $userReaction,
            'is_following' => (int)($row['is_following'] ?? 0) === 1,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
