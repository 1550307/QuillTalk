<?php
declare(strict_types=1);

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $_SERVER['HTTP_HOST'], 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/groups.php';
require __DIR__ . '/includes/poll_auth.php';
require_once __DIR__ . '/includes/games.php';

header('Content-Type: application/json');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$gameId = (int)($_GET['game_id'] ?? 0);
if ($gameId <= 0) {
    respond(['success' => false, 'error' => 'Missing game_id'], 400);
}

try {
    $game = qt_game_fetch($pdo, $gameId);
    if (!$game) {
        respond(['success' => false, 'error' => 'Game not found'], 404);
    }

    if (!qt_game_user_can_access($pdo, $game, $userId)) {
        respond(['success' => false, 'error' => 'Game not available in this chat'], 403);
    }

    $game = qt_game_sync_chess_runtime($pdo, $game);

    respond([
        'success' => true,
        'data' => qt_game_build_payload($pdo, $game, $userId),
    ]);
} catch (Throwable $e) {
    error_log('Get game data error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to load game'], 500);
}
