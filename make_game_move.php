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

function qt_game_extract_path_squares(string $value): string
{
    $normalized = strtolower((string)preg_replace('/[^a-h1-8]/i', '', $value));
    return preg_match('/^(?:[a-h][1-8]){2,8}$/', $normalized) ? $normalized : '';
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
$move = is_array($input['move'] ?? null) ? $input['move'] : [];
$nextStateInput = is_array($input['state'] ?? null) ? $input['state'] : null;
$actorRole = strtolower(trim((string)($input['actor_role'] ?? 'user')));
$isBotActor = $actorRole === 'bot';
$stateLastMove = is_array($nextStateInput['lastMove'] ?? null) ? $nextStateInput['lastMove'] : [];

if ($gameId <= 0 || $nextStateInput === null) {
    respond(['success' => false, 'error' => 'Missing move payload'], 400);
}

$from = strtolower(trim((string)($move['from'] ?? '')));
$to = strtolower(trim((string)($move['to'] ?? '')));
$promotion = strtolower(trim((string)($move['promotion'] ?? '')));
$notation = trim((string)($move['notation'] ?? ''));
$uci = strtolower(trim((string)($move['uci'] ?? '')));

if (!preg_match('/^[a-h][1-8]$/', $from)) {
    $from = strtolower(trim((string)($stateLastMove['from'] ?? '')));
}
if (!preg_match('/^[a-h][1-8]$/', $to)) {
    $to = strtolower(trim((string)($stateLastMove['to'] ?? '')));
}

if (!preg_match('/^[a-h][1-8]$/', $from) || !preg_match('/^[a-h][1-8]$/', $to)) {
    $pathSquares = qt_game_extract_path_squares($uci !== '' ? $uci : $notation);
    if ($pathSquares !== '') {
        $from = substr($pathSquares, 0, 2);
        $to = substr($pathSquares, -2);
    }
}

if (!preg_match('/^[a-h][1-8]$/', $from) || !preg_match('/^[a-h][1-8]$/', $to)) {
    respond(['success' => false, 'error' => 'Invalid move coordinates'], 400);
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
    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    $nextState = qt_game_sanitize_state_for_type($gameType, $nextStateInput);
    if ($nextState === null) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Missing move payload'], 400);
    }

    if ($gameType === QT_GAME_TYPE_CHECKERS) {
        $checkersPath = '';
        foreach ([$uci, (string)($nextState['lastMove']['uci'] ?? ''), $notation] as $candidate) {
            $checkersPath = qt_game_extract_path_squares((string)$candidate);
            if ($checkersPath !== '') {
                break;
            }
        }
        if (!in_array($promotion, ['', 'k'], true)) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid promotion'], 400);
        }
        if ($checkersPath === '') {
            $checkersPath = $from . $to;
        }
        if (!preg_match('/^(?:[a-h][1-8]){2,8}$/', $checkersPath)) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid move notation'], 400);
        }
        $uci = $checkersPath;
        $from = substr($uci, 0, 2);
        $to = substr($uci, -2);
    } elseif ($gameType === QT_GAME_TYPE_CONNECT_FOUR) {
        if ($promotion !== '') {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid promotion'], 400);
        }
        if (!preg_match('/^[a-g][1-6]$/', $from)) {
            $from = $to;
        }
        if (!preg_match('/^[a-g][1-6]$/', $to)) {
            $to = $from;
        }
        if ($uci === '') {
            $uci = $to;
        }
        if (!preg_match('/^[a-g][1-6]$/', $from) || !preg_match('/^[a-g][1-6]$/', $to) || !preg_match('/^[a-g][1-6]$/', $uci)) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid move notation'], 400);
        }
    } else {
        if (!in_array($promotion, ['', 'q', 'r', 'b', 'n'], true)) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid promotion'], 400);
        }
        if ($uci === '') {
            $uci = $from . $to . ($promotion !== '' ? $promotion : '');
        }
        if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'Invalid move notation'], 400);
        }
    }

    if ((string)($game['status'] ?? '') !== QT_GAME_STATUS_ACTIVE) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This game is not active'], 409);
    }

    $viewerColor = qt_game_player_color($game, $userId);
    if ($viewerColor === null) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => $isBotActor ? 'Only players can advance bot turns' : 'Spectators cannot move pieces'], 403);
    }

    $actingColor = $viewerColor;
    if ($isBotActor) {
        if (!qt_game_supports_bot($gameType)) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'This game has no active bot turn'], 409);
        }
        $botColor = qt_game_bot_color($game);
        if (!qt_game_is_bot_game($game) || $botColor === null) {
            $pdo->rollBack();
            respond(['success' => false, 'error' => 'This game has no active bot turn'], 409);
        }
        $actingColor = $botColor;
    }

    $currentState = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_game_initial_chess_state();
    $currentTurn = (string)($currentState['turn'] ?? 'w');
    $clockSnapshot = qt_game_clock_snapshot($game, $currentState);
    $remainingTurnMs = $currentTurn === 'b'
        ? (int)($clockSnapshot['black_ms'] ?? 0)
        : (int)($clockSnapshot['white_ms'] ?? 0);

    if ($actingColor !== $currentTurn) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'It is not your turn'], 409);
    }

    if ($remainingTurnMs <= 0) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This turn has already run out of time'], 409);
    }

    if ((string)($nextState['turn'] ?? '') === $currentTurn) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Move state did not advance the turn'], 409);
    }

    $moveCountStmt = $pdo->prepare("SELECT COALESCE(MAX(ply_number), 0) FROM chat_game_moves WHERE game_id = ?");
    $moveCountStmt->execute([$gameId]);
    $plyNumber = ((int)$moveCountStmt->fetchColumn()) + 1;

    $winnerUserId = null;
    $winnerColor = (string)($nextState['winnerColor'] ?? '');
    if ($winnerColor === 'w') {
        $winnerUserId = (int)($game['white_user_id'] ?? 0) ?: null;
    } elseif ($winnerColor === 'b') {
        $winnerUserId = (int)($game['black_user_id'] ?? 0) ?: null;
    }

    $resultCode = trim((string)($nextState['resultCode'] ?? '')) ?: null;
    $resultLabel = trim((string)($nextState['resultLabel'] ?? '')) ?: null;
    $status = $resultCode !== null ? QT_GAME_STATUS_COMPLETED : QT_GAME_STATUS_ACTIVE;
    $completedAt = $status === QT_GAME_STATUS_COMPLETED ? date('Y-m-d H:i:s') : null;
    $whiteTimeMs = (int)($clockSnapshot['white_ms'] ?? 0);
    $blackTimeMs = (int)($clockSnapshot['black_ms'] ?? 0);
    $turnStartedAt = $status === QT_GAME_STATUS_ACTIVE ? date('Y-m-d H:i:s') : null;

    $statePayload = json_encode($nextState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($statePayload) || $statePayload === '') {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Failed to encode the next game state'], 500);
    }

    $insertMoveStmt = $pdo->prepare("
        INSERT INTO chat_game_moves (
            game_id,
            ply_number,
            user_id,
            move_uci,
            move_notation,
            state_after,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $insertMoveStmt->execute([
        $gameId,
        $plyNumber,
        $isBotActor ? 0 : $userId,
        $uci,
        substr($notation, 0, 32),
        $statePayload,
    ]);

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
            turn_started_at = ?,
            completed_at = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([
        $statePayload,
        $status,
        $winnerUserId,
        $resultCode,
        $resultLabel,
        $whiteTimeMs,
        $blackTimeMs,
        $turnStartedAt,
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
    error_log('Make game move error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to record move'], 500);
}
