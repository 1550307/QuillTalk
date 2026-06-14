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
$targetUserId = max(0, (int)($input['target_user_id'] ?? 0));

if ($token === '' || $targetUserId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if (!qt_scrolls_table_exists($pdo, 'scroll_follows')) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scroll follows are not ready right now.'], 503);
}

if ($targetUserId === $userId) {
    qt_scrolls_respond(['success' => false, 'error' => 'You cannot follow yourself.'], 400);
}

$targetStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
$targetStmt->execute([$targetUserId]);
if (!$targetStmt->fetch(PDO::FETCH_ASSOC)) {
    qt_scrolls_respond(['success' => false, 'error' => 'User not found'], 404);
}

try {
    $existsStmt = $pdo->prepare('SELECT 1 FROM scroll_follows WHERE follower_user_id = ? AND target_user_id = ? LIMIT 1');
    $existsStmt->execute([$userId, $targetUserId]);
    $isFollowing = (bool)$existsStmt->fetchColumn();

    if ($isFollowing) {
        $deleteStmt = $pdo->prepare('DELETE FROM scroll_follows WHERE follower_user_id = ? AND target_user_id = ?');
        $deleteStmt->execute([$userId, $targetUserId]);
        $isFollowing = false;
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO scroll_follows (follower_user_id, target_user_id, created_at) VALUES (?, ?, NOW())');
        $insertStmt->execute([$userId, $targetUserId]);
        $isFollowing = true;
    }

    qt_scrolls_respond([
        'success' => true,
        'is_following' => $isFollowing,
        'follower_count' => qt_scrolls_fetch_follower_count($pdo, $targetUserId),
    ]);
} catch (Throwable $e) {
    error_log('[toggle_scroll_follow] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not update follow state.'], 500);
}
