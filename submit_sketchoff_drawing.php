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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
$imageData = trim((string)($input['image_data'] ?? ''));

if ($gameId <= 0 || $imageData === '') {
    respond(['success' => false, 'error' => 'Missing drawing payload'], 400);
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

    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    if ($gameType !== QT_GAME_TYPE_SKETCHOFF) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This game is not a Sketchoff round'], 409);
    }

    if ((string)($game['status'] ?? '') !== QT_GAME_STATUS_ACTIVE) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This round is not active'], 409);
    }

    $viewerColor = qt_game_player_color($game, $userId);
    if ($viewerColor === null) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Only players can submit a sketch'], 403);
    }

    $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_sketchoff_initial_state();
    $state = qt_sketchoff_prepare_state_for_active_round($state);

    if (trim((string)($state['resultCode'] ?? '')) !== '') {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'This Sketchoff round is already finished'], 409);
    }

    if (qt_sketchoff_time_remaining_ms($state) <= 0) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Time is up for this Sketchoff round'], 409);
    }

    $prompt = (string)($state['prompt'] ?? 'sketchoff');
    $submission = qt_sketchoff_store_canvas_data_url($imageData, $prompt . '-' . ($viewerColor === 'b' ? 'black' : 'white'));
    $state = qt_sketchoff_set_submission($state, $viewerColor, $submission);

    $statePayload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($statePayload) || $statePayload === '') {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Could not save the submitted sketch'], 500);
    }

    $moveCountStmt = $pdo->prepare("SELECT COALESCE(MAX(ply_number), 0) FROM chat_game_moves WHERE game_id = ?");
    $moveCountStmt->execute([$gameId]);
    $plyNumber = ((int)$moveCountStmt->fetchColumn()) + 1;
    $insertMoveStmt = $pdo->prepare("
        INSERT INTO chat_game_moves (game_id, ply_number, user_id, move_uci, move_notation, state_after, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $insertMoveStmt->execute([
        $gameId,
        $plyNumber,
        $userId,
        $viewerColor === 'b' ? 'submitb' : 'submitw',
        $viewerColor === 'b' ? 'Black submitted' : 'White submitted',
        $statePayload,
    ]);

    $updateStmt = $pdo->prepare("
        UPDATE chat_games
        SET state_payload = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$statePayload, $gameId]);

    if (qt_sketchoff_get_submission($state, 'w') && qt_sketchoff_get_submission($state, 'b')) {
        $game = qt_game_fetch($pdo, $gameId) ?: $game;
        $game = qt_sketchoff_finalize_game($pdo, $game, $state);
    } else {
        $game = qt_game_fetch($pdo, $gameId) ?: $game;
    }

    $pdo->commit();

    respond([
        'success' => true,
        'data' => qt_game_build_payload($pdo, $game, $userId),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Submit Sketchoff drawing error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Failed to submit the sketch'], 500);
}
