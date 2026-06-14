<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/scrolls.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '') {
    qt_scrolls_respond(['success' => false, 'error' => 'Missing token'], 400);
}

$userId = qt_scrolls_resolve_user_id($pdo, $token);
if ($userId <= 0) {
    qt_scrolls_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

try {
    $items = qt_scrolls_fetch_feed_items($pdo, $userId);
    qt_scrolls_respond([
        'success' => true,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    error_log('[fetch_scrolls] ' . $e->getMessage());
    qt_scrolls_respond(['success' => false, 'error' => 'Could not load Scrolls right now.'], 500);
}
