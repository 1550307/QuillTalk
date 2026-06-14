<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/scrolls.php';

const QT_SCROLL_COMMENT_MAX_CHARS = 600;

$input = json_decode((string)(file_get_contents('php://input') ?: '[]'), true);
if (!is_array($input)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid input'], 400);
}

$token = trim((string)($input['token'] ?? ''));
$scrollId = max(0, (int)($input['scroll_id'] ?? 0));
$replyToCommentId = max(0, (int)($input['reply_to_comment_id'] ?? 0));
$commentText = str_replace(["\r\n", "\r"], "\n", trim((string)($input['comment'] ?? '')));

if ($commentText !== '') {
    if (function_exists('mb_substr')) {
        $commentText = trim((string)mb_substr($commentText, 0, QT_SCROLL_COMMENT_MAX_CHARS));
    } else {
        $commentText = trim(substr($commentText, 0, QT_SCROLL_COMMENT_MAX_CHARS));
    }
}

if ($token === '' || $scrollId <= 0 || $commentText === '') {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if (!qt_scrolls_table_exists($pdo, 'scroll_comments')) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scroll comments are not ready right now.'], 503);
}

if (!qt_scrolls_scroll_exists($pdo, $scrollId)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scroll not found'], 404);
}

try {
    $normalizedReplyToCommentId = 0;
    if ($replyToCommentId > 0) {
        $replyTarget = qt_scrolls_fetch_comment_thread_target($pdo, $replyToCommentId);
        if (!$replyTarget || (int)($replyTarget['scroll_id'] ?? 0) !== $scrollId) {
            qt_scrolls_respond(['success' => false, 'error' => 'Reply target not found'], 404);
        }

        $normalizedReplyToCommentId = max(0, (int)($replyTarget['id'] ?? 0));
    }

    $stmt = $pdo->prepare('
        INSERT INTO scroll_comments (scroll_id, user_id, reply_to_comment_id, comment_text, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $scrollId,
        $userId,
        $normalizedReplyToCommentId > 0 ? $normalizedReplyToCommentId : null,
        $commentText
    ]);

    $commentId = (int)$pdo->lastInsertId();
    $comment = qt_scrolls_fetch_comment_by_id($pdo, $commentId, $userId);

    qt_scrolls_respond([
        'success' => true,
        'comment' => $comment,
        'comment_count' => qt_scrolls_fetch_comment_count($pdo, $scrollId),
    ]);
} catch (Throwable $e) {
    error_log('[create_scroll_comment] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not post that comment right now.'], 500);
}
