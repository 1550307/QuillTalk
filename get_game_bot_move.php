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

function normalize_bot_legal_moves(array $items, string $gameType = QT_GAME_TYPE_CHESS): array
{
    $normalizedGameType = strtolower(trim($gameType));
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $from = strtolower(trim((string)($item['from'] ?? '')));
        $to = strtolower(trim((string)($item['to'] ?? '')));
        $promotion = strtolower(trim((string)($item['promotion'] ?? '')));
        $uci = strtolower(trim((string)($item['uci'] ?? '')));
        $notation = trim((string)($item['notation'] ?? ''));
        if (!preg_match('/^[a-h][1-8]$/', $from) || !preg_match('/^[a-h][1-8]$/', $to)) {
            continue;
        }

        if ($normalizedGameType === QT_GAME_TYPE_CHECKERS) {
            if (!in_array($promotion, ['', 'k'], true)) {
                $promotion = '';
            }
            if ($uci === '') {
                $uci = $from . $to;
            }
            if (!preg_match('/^[a-h][1-8][a-h][1-8]$/', $uci)) {
                continue;
            }
        } elseif ($normalizedGameType === QT_GAME_TYPE_CONNECT_FOUR) {
            $promotion = '';
            if (!preg_match('/^[a-g][1-6]$/', $from) || !preg_match('/^[a-g][1-6]$/', $to)) {
                continue;
            }
            if ($uci === '') {
                $uci = $to;
            }
            if (!preg_match('/^[a-g][1-6]$/', $uci)) {
                continue;
            }
        } else {
            if (!in_array($promotion, ['', 'q', 'r', 'b', 'n'], true)) {
                $promotion = '';
            }
            if ($uci === '') {
                $uci = $from . $to . $promotion;
            }
            if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
                continue;
            }
        }

        $normalized[] = [
            'from' => $from,
            'to' => $to,
            'promotion' => $promotion !== '' ? $promotion : null,
            'uci' => $uci,
            'notation' => substr($notation, 0, 32),
            'capture' => !empty($item['capture']),
        ];
    }

    return array_slice($normalized, 0, 256);
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
$legalMovesInput = is_array($input['legal_moves'] ?? null) ? $input['legal_moves'] : [];

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

    if (qt_game_player_color($game, $userId) === null) {
        respond(['success' => false, 'error' => 'Only players can advance bot turns'], 403);
    }

    $game = qt_game_sync_chess_runtime($pdo, $game);
    if ((string)($game['status'] ?? '') !== QT_GAME_STATUS_ACTIVE) {
        respond(['success' => false, 'error' => 'This game is not active'], 409);
    }

    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    $legalMoves = normalize_bot_legal_moves($legalMovesInput, $gameType);
    if ($legalMoves === []) {
        respond(['success' => false, 'error' => 'No legal moves were provided'], 400);
    }
    if (!qt_game_supports_bot($gameType)) {
        respond(['success' => false, 'error' => 'This game does not support bot turns'], 409);
    }

    if (!qt_game_is_bot_game($game)) {
        respond(['success' => false, 'error' => 'This game is not a bot game'], 409);
    }

    $botColor = qt_game_bot_color($game);
    if ($botColor === null) {
        respond(['success' => false, 'error' => 'Bot seat is unavailable'], 409);
    }

    $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_game_initial_chess_state();
    $currentTurn = ($state['turn'] ?? 'w') === 'b' ? 'b' : 'w';
    if ($currentTurn !== $botColor) {
        respond(['success' => false, 'error' => 'It is not QuillTalk AI\'s turn'], 409);
    }

    $historyMoves = qt_game_fetch_moves($pdo, $gameId);
    $move = qt_game_ai_choose_move($game, $state, $legalMoves, $historyMoves);

    respond([
        'success' => true,
        'move' => $move,
    ]);
} catch (Throwable $e) {
    error_log('Get game bot move error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to choose a bot move'], 500);
}
