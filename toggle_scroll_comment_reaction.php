<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/scrolls.php';

$input = json_decode((string)(file_get_contents('php://input') ?: '[]'), true);
if (!is_array($input)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid input'], 400);
}

$token = trim((string)($input['token'] ?? ''));
$commentId = max(0, (int)($input['comment_id'] ?? 0));
$reactionType = trim((string)($input['reaction_type'] ?? ''));

if ($token === '' || $commentId <= 0 || !in_array($reactionType, ['like', 'dislike'], true)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if (!qt_scrolls_table_exists($pdo, 'scroll_comments') || !qt_scrolls_table_exists($pdo, 'scroll_comment_reactions')) {
    qt_scrolls_respond(['success' => false, 'error' => 'Comment reactions are not ready right now.'], 503);
}

$comment = qt_scrolls_fetch_comment_thread_target($pdo, $commentId);
if (!$comment || (int)($comment['id'] ?? 0) <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Comment not found'], 404);
}

try {
    $currentStmt = $pdo->prepare('SELECT reaction_type FROM scroll_comment_reactions WHERE comment_id = ? AND user_id = ? LIMIT 1');
    $currentStmt->execute([$commentId, $userId]);
    $existingReaction = trim((string)($currentStmt->fetchColumn() ?: ''));

    $nextReaction = $reactionType;
    if ($existingReaction === $reactionType) {
        $deleteStmt = $pdo->prepare('DELETE FROM scroll_comment_reactions WHERE comment_id = ? AND user_id = ?');
        $deleteStmt->execute([$commentId, $userId]);
        $nextReaction = '';
    } elseif ($existingReaction !== '') {
        $updateStmt = $pdo->prepare('UPDATE scroll_comment_reactions SET reaction_type = ?, created_at = NOW() WHERE comment_id = ? AND user_id = ?');
        $updateStmt->execute([$reactionType, $commentId, $userId]);
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO scroll_comment_reactions (comment_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, NOW())');
        $insertStmt->execute([$commentId, $userId, $reactionType]);
    }

    $summary = qt_scrolls_fetch_comment_reaction_summary($pdo, $commentId);
    qt_scrolls_respond([
        'success' => true,
        'comment_id' => $commentId,
        'scroll_id' => (int)($comment['scroll_id'] ?? 0),
        'like_count' => $summary['like_count'],
        'dislike_count' => $summary['dislike_count'],
        'user_reaction' => $nextReaction,
    ]);
} catch (Throwable $e) {
    error_log('[toggle_scroll_comment_reaction] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not update the comment reaction.'], 500);
}
