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
require_once __DIR__ . '/includes/game_ai.php';

header('Content-Type: application/json');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method'], 405);
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$input = qt_poll_json_input() ?? [];
$gameId = (int)($input['game_id'] ?? 0);
$message = trim((string)($input['message'] ?? ''));
$message = substr($message, 0, 500);

if ($gameId <= 0) {
    respond(['success' => false, 'error' => 'Missing game_id'], 400);
}
if ($message === '') {
    respond(['success' => false, 'error' => 'Message cannot be empty'], 400);
}

try {
    $game = qt_game_fetch($pdo, $gameId);
    if (!$game) {
        respond(['success' => false, 'error' => 'Game not found'], 404);
    }

    if (!qt_game_user_can_access($pdo, $game, $userId)) {
        respond(['success' => false, 'error' => 'Game not available in this chat'], 403);
    }

    if (qt_game_player_color($game, $userId) === null) {
        respond(['success' => false, 'error' => 'Only players can use the game chat'], 403);
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO chat_game_messages (game_id, user_id, sender_role, message, created_at)
        VALUES (?, ?, 'user', ?, NOW())
    ");
    $insertStmt->execute([$gameId, $userId, $message]);

    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));

    if (qt_game_is_bot_game($game) && qt_game_supports_bot($gameType)) {
        $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
            ?: qt_game_initial_state_for_type($gameType)
            ?: qt_game_initial_chess_state();
        $chatMessages = qt_game_fetch_chat_messages($pdo, $gameId);
        $botReply = trim(qt_game_ai_build_chat_reply($gameType, $state, $chatMessages, $message, QT_GAME_BOT_NAME));
        if ($botReply !== '') {
            $botInsertStmt = $pdo->prepare("
                INSERT INTO chat_game_messages (game_id, user_id, sender_role, message, created_at)
                VALUES (?, NULL, 'bot', ?, NOW())
            ");
            $botInsertStmt->execute([$gameId, substr($botReply, 0, 500)]);
        }
    }

    $updatedGame = qt_game_fetch($pdo, $gameId);
    if (!$updatedGame) {
        respond(['success' => false, 'error' => 'Game not found after sending chat'], 404);
    }

    $updatedGame = qt_game_sync_chess_runtime($pdo, $updatedGame);

    respond([
        'success' => true,
        'data' => qt_game_build_payload($pdo, $updatedGame, $userId),
    ]);
} catch (Throwable $e) {
    error_log('Send game chat error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to send the game chat message'], 500);
}
