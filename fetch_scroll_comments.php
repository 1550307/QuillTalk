<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/scrolls.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$scrollId = max(0, (int)($_GET['scroll_id'] ?? $_POST['scroll_id'] ?? 0));

if ($token === '' || $scrollId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

if (!qt_scrolls_table_exists($pdo, 'scroll_comments')) {
    qt_scrolls_respond([
        'success' => true,
        'comments' => [],
        'comment_count' => 0,
    ]);
}

if (!qt_scrolls_scroll_exists($pdo, $scrollId)) {
    qt_scrolls_respond(['success' => false, 'error' => 'Scroll not found'], 404);
}

try {
    $comments = qt_scrolls_fetch_comments($pdo, $scrollId, $userId);
    qt_scrolls_respond([
        'success' => true,
        'comments' => $comments,
        'comment_count' => qt_scrolls_fetch_comment_count($pdo, $scrollId),
    ]);
} catch (Throwable $e) {
    error_log('[fetch_scroll_comments] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not load comments right now.'], 500);
}
