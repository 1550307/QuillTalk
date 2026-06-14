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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method'], 405);
}

$userId = qt_poll_require_user_id($pdo);
if (!$userId) {
    respond(['success' => false, 'error' => qt_poll_auth_error_message($pdo)], 401);
}

$input = qt_poll_json_input() ?? [];
$gameId = (int)($input['game_id'] ?? 0);
if ($gameId <= 0) {
    respond(['success' => false, 'error' => 'Missing game_id'], 400);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM chat_games WHERE id = ? FOR UPDATE");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Game not found'], 404);
    }

    if (!qt_game_user_can_access($pdo, $game, $userId)) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Game not available in this chat'], 403);
    }

    $game = qt_game_sync_chess_runtime($pdo, $game);
    if ((string)($game['status'] ?? '') !== QT_GAME_STATUS_ACTIVE) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This game is not active'], 409);
    }

    $viewerColor = qt_game_player_color($game, $userId);
    if ($viewerColor === null) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Only players can resign from a game'], 403);
    }

    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_game_initial_chess_state();
    $winnerColor = qt_game_opponent_color($viewerColor);
    $state['winnerColor'] = $winnerColor;
    $state['resultCode'] = 'resignation';
    $state['resultLabel'] = 'Resignation';

    $clockSnapshot = qt_game_clock_snapshot($game, $state);
    $whiteTimeMs = (int)($clockSnapshot['white_ms'] ?? 0);
    $blackTimeMs = (int)($clockSnapshot['black_ms'] ?? 0);
    $winnerUserId = qt_game_winner_user_id_from_color($game, $winnerColor);
    $completedAt = date('Y-m-d H:i:s');

    $statePayload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($statePayload) || $statePayload === '') {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Failed to encode the resignation state'], 500);
    }

    $updateStmt = $pdo->prepare("
        UPDATE chat_games
        SET
            state_payload = ?,
            status = ?,
            winner_user_id = ?,
            result_code = ?,
            result_label = ?,
            white_time_ms = ?,
            black_time_ms = ?,
            turn_started_at = NULL,
            completed_at = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([
        $statePayload,
        QT_GAME_STATUS_COMPLETED,
        $winnerUserId,
        'resignation',
        'Resignation',
        $whiteTimeMs,
        $blackTimeMs,
        $completedAt,
        $gameId,
    ]);

    $pdo->commit();

    $updatedGame = qt_game_fetch($pdo, $gameId);
    respond([
        'success' => true,
        'data' => qt_game_build_payload($pdo, $updatedGame ?: $game, $userId),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Resign game error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to resign from the game'], 500);
}
