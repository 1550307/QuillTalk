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

    if (!qt_game_can_join($pdo, $game, $userId)) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This game is not joinable right now'], 403);
    }

    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_game_initial_chess_state();
    if ($gameType === QT_GAME_TYPE_SKETCHOFF) {
        $state = qt_sketchoff_prepare_state_for_active_round($state);
    }
    [$whiteUserId, $blackUserId] = qt_game_assign_waiting_seats($game, $userId);
    $timeControlSeconds = qt_game_normalize_time_control_seconds($game['time_control_seconds'] ?? QT_GAME_DEFAULT_TIME_CONTROL_SECONDS);
    $initialClockMs = $timeControlSeconds * 1000;
    $whiteTimeMs = max(0, (int)($game['white_time_ms'] ?? $initialClockMs)) ?: $initialClockMs;
    $blackTimeMs = max(0, (int)($game['black_time_ms'] ?? $initialClockMs)) ?: $initialClockMs;
    $startedAt = trim((string)($game['started_at'] ?? '')) !== ''
        ? (string)$game['started_at']
        : date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        UPDATE chat_games
        SET
            white_user_id = ?,
            black_user_id = ?,
            status = ?,
            started_at = ?,
            turn_started_at = NOW(),
            white_time_ms = ?,
            black_time_ms = ?,
            updated_at = NOW(),
            state_payload = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $whiteUserId,
        $blackUserId,
        QT_GAME_STATUS_ACTIVE,
        $startedAt,
        $whiteTimeMs,
        $blackTimeMs,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
    error_log('Join game error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to join game'], 500);
}
