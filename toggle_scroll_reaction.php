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
$scrollId = max(0, (int)($input['scroll_id'] ?? 0));
$reactionType = trim((string)($input['reaction_type'] ?? ''));

if ($token === '' || $scrollId <= 0 || !in_array($reactionType, ['like', 'dislike'], true)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if (!qt_scrolls_table_exists($pdo, 'scrolls') || !qt_scrolls_table_exists($pdo, 'scroll_reactions')) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scroll reactions are not ready right now.'], 503);
}

$scrollStmt = $pdo->prepare('SELECT id FROM scrolls WHERE id = ? AND is_active = 1 LIMIT 1');
$scrollStmt->execute([$scrollId]);
if (!$scrollStmt->fetch(PDO::FETCH_ASSOC)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scroll not found'], 404);
}

try {
    $currentStmt = $pdo->prepare('SELECT reaction_type FROM scroll_reactions WHERE scroll_id = ? AND user_id = ? LIMIT 1');
    $currentStmt->execute([$scrollId, $userId]);
    $existingReaction = trim((string)($currentStmt->fetchColumn() ?: ''));

    $nextReaction = $reactionType;
    if ($existingReaction === $reactionType) {
        $deleteStmt = $pdo->prepare('DELETE FROM scroll_reactions WHERE scroll_id = ? AND user_id = ?');
        $deleteStmt->execute([$scrollId, $userId]);
        $nextReaction = '';
    } elseif ($existingReaction !== '') {
        $updateStmt = $pdo->prepare('UPDATE scroll_reactions SET reaction_type = ?, created_at = NOW() WHERE scroll_id = ? AND user_id = ?');
        $updateStmt->execute([$reactionType, $scrollId, $userId]);
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO scroll_reactions (scroll_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, NOW())');
        $insertStmt->execute([$scrollId, $userId, $reactionType]);
    }

    $summary = qt_scrolls_fetch_reaction_summary($pdo, $scrollId);
    qt_scrolls_respond([
        'success' => true,
        'like_count' => $summary['like_count'],
        'dislike_count' => $summary['dislike_count'],
        'user_reaction' => $nextReaction,
    ]);
} catch (Throwable $e) {
    error_log('[toggle_scroll_reaction] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not update the reaction.'], 500);
}
