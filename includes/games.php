<?php
declare(strict_types=1);

const QT_GAME_MESSAGE_PREFIX = '__GAME__:';
const QT_GAME_TYPE_CHESS = 'chess';
const QT_GAME_TYPE_CHECKERS = 'checkers';
const QT_GAME_TYPE_CONNECT_FOUR = 'connect_four';
const QT_GAME_TYPE_SKETCHOFF = 'sketchoff';
const QT_GAME_STATUS_WAITING = 'waiting';
const QT_GAME_STATUS_ACTIVE = 'active';
const QT_GAME_STATUS_COMPLETED = 'completed';
const QT_GAME_OPPONENT_HUMAN = 'human';
const QT_GAME_OPPONENT_BOT = 'bot';
const QT_GAME_BOT_NAME = 'QuillTalk AI';
const QT_GAME_BOT_PROFILE_PIC = 'images/default-ai.png';
const QT_GAME_DEFAULT_TIME_CONTROL_SECONDS = 300;

require_once __DIR__ . '/sketchoff.php';

function ensure_game_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.chat_games_ready_v1';

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_games (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                game_type VARCHAR(32) NOT NULL,
                creator_user_id INT UNSIGNED NOT NULL,
                white_user_id INT UNSIGNED NOT NULL,
                black_user_id INT UNSIGNED NULL,
                group_id INT UNSIGNED NULL,
                recipient_id INT UNSIGNED NULL,
                bot_enabled TINYINT(1) NOT NULL DEFAULT 0,
                bot_color CHAR(1) NULL,
                time_control_seconds INT UNSIGNED NOT NULL DEFAULT 300,
                white_time_ms INT UNSIGNED NULL,
                black_time_ms INT UNSIGNED NULL,
                turn_started_at DATETIME NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'waiting',
                winner_user_id INT UNSIGNED NULL,
                result_code VARCHAR(48) NULL,
                result_label VARCHAR(120) NULL,
                state_payload MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                KEY chat_games_status_idx (status, updated_at),
                KEY chat_games_creator_idx (creator_user_id, created_at),
                KEY chat_games_direct_idx (recipient_id, created_at),
                KEY chat_games_group_idx (group_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_game_moves (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                ply_number INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                move_uci VARCHAR(16) NOT NULL,
                move_notation VARCHAR(32) NOT NULL DEFAULT '',
                state_after MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY chat_game_moves_game_ply_unique (game_id, ply_number),
                KEY chat_game_moves_game_idx (game_id, created_at),
                KEY chat_game_moves_user_idx (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_game_messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                sender_role VARCHAR(16) NOT NULL DEFAULT 'user',
                message TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY chat_game_messages_game_idx (game_id, created_at),
                KEY chat_game_messages_user_idx (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $gameColumns = [];
        $gameColumnStmt = $pdo->query("SHOW COLUMNS FROM chat_games");
        while ($row = $gameColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $gameColumns[(string)($row['Field'] ?? '')] = true;
        }

        $gameColumnMigrations = [
            'game_type' => "ALTER TABLE chat_games ADD COLUMN game_type VARCHAR(32) NOT NULL DEFAULT 'chess' AFTER id",
            'creator_user_id' => "ALTER TABLE chat_games ADD COLUMN creator_user_id INT UNSIGNED NULL AFTER game_type",
            'white_user_id' => "ALTER TABLE chat_games ADD COLUMN white_user_id INT UNSIGNED NULL AFTER creator_user_id",
            'black_user_id' => "ALTER TABLE chat_games ADD COLUMN black_user_id INT UNSIGNED NULL AFTER white_user_id",
            'group_id' => "ALTER TABLE chat_games ADD COLUMN group_id INT UNSIGNED NULL AFTER black_user_id",
            'recipient_id' => "ALTER TABLE chat_games ADD COLUMN recipient_id INT UNSIGNED NULL AFTER group_id",
            'bot_enabled' => "ALTER TABLE chat_games ADD COLUMN bot_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER recipient_id",
            'bot_color' => "ALTER TABLE chat_games ADD COLUMN bot_color CHAR(1) NULL AFTER bot_enabled",
            'time_control_seconds' => "ALTER TABLE chat_games ADD COLUMN time_control_seconds INT UNSIGNED NOT NULL DEFAULT 300 AFTER bot_color",
            'white_time_ms' => "ALTER TABLE chat_games ADD COLUMN white_time_ms INT UNSIGNED NULL AFTER time_control_seconds",
            'black_time_ms' => "ALTER TABLE chat_games ADD COLUMN black_time_ms INT UNSIGNED NULL AFTER white_time_ms",
            'turn_started_at' => "ALTER TABLE chat_games ADD COLUMN turn_started_at DATETIME NULL AFTER black_time_ms",
            'status' => "ALTER TABLE chat_games ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'waiting' AFTER turn_started_at",
            'winner_user_id' => "ALTER TABLE chat_games ADD COLUMN winner_user_id INT UNSIGNED NULL AFTER status",
            'result_code' => "ALTER TABLE chat_games ADD COLUMN result_code VARCHAR(48) NULL AFTER winner_user_id",
            'result_label' => "ALTER TABLE chat_games ADD COLUMN result_label VARCHAR(120) NULL AFTER result_code",
            'state_payload' => "ALTER TABLE chat_games ADD COLUMN state_payload MEDIUMTEXT NULL AFTER result_label",
            'created_at' => "ALTER TABLE chat_games ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER state_payload",
            'updated_at' => "ALTER TABLE chat_games ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
            'started_at' => "ALTER TABLE chat_games ADD COLUMN started_at DATETIME NULL AFTER updated_at",
            'completed_at' => "ALTER TABLE chat_games ADD COLUMN completed_at DATETIME NULL AFTER started_at",
        ];
        foreach ($gameColumnMigrations as $columnName => $sql) {
            if (!isset($gameColumns[$columnName])) {
                $pdo->exec($sql);
            }
        }

        $defaultChessStatePayload = json_encode(qt_game_initial_chess_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($defaultChessStatePayload) && $defaultChessStatePayload !== '') {
            $pdo->exec("
                UPDATE chat_games
                SET state_payload = " . $pdo->quote($defaultChessStatePayload) . "
                WHERE state_payload IS NULL OR state_payload = ''
            ");
        }
        $pdo->exec("
            UPDATE chat_games
            SET status = 'waiting'
            WHERE status IS NULL OR status = ''
        ");
        $pdo->exec("
            UPDATE chat_games
            SET time_control_seconds = 300
            WHERE time_control_seconds IS NULL OR time_control_seconds <= 0
        ");
        $pdo->exec("
            UPDATE chat_games
            SET white_time_ms = time_control_seconds * 1000
            WHERE white_time_ms IS NULL OR white_time_ms < 0
        ");
        $pdo->exec("
            UPDATE chat_games
            SET black_time_ms = time_control_seconds * 1000
            WHERE black_time_ms IS NULL OR black_time_ms < 0
        ");
        $pdo->exec("
            UPDATE chat_games
            SET bot_enabled = 0
            WHERE bot_enabled IS NULL
        ");

        $gameIndexes = [];
        $gameIndexStmt = $pdo->query("SHOW INDEX FROM chat_games");
        while ($row = $gameIndexStmt->fetch(PDO::FETCH_ASSOC)) {
            $gameIndexes[(string)($row['Key_name'] ?? '')] = true;
        }
        $gameIndexMigrations = [
            'chat_games_status_idx' => "ALTER TABLE chat_games ADD KEY chat_games_status_idx (status, updated_at)",
            'chat_games_creator_idx' => "ALTER TABLE chat_games ADD KEY chat_games_creator_idx (creator_user_id, created_at)",
            'chat_games_direct_idx' => "ALTER TABLE chat_games ADD KEY chat_games_direct_idx (recipient_id, created_at)",
            'chat_games_group_idx' => "ALTER TABLE chat_games ADD KEY chat_games_group_idx (group_id, created_at)",
        ];
        foreach ($gameIndexMigrations as $indexName => $sql) {
            if (!isset($gameIndexes[$indexName])) {
                $pdo->exec($sql);
            }
        }

        $moveColumns = [];
        $moveColumnStmt = $pdo->query("SHOW COLUMNS FROM chat_game_moves");
        while ($row = $moveColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $moveColumns[(string)($row['Field'] ?? '')] = true;
        }

        $moveColumnMigrations = [
            'game_id' => "ALTER TABLE chat_game_moves ADD COLUMN game_id INT UNSIGNED NULL AFTER id",
            'ply_number' => "ALTER TABLE chat_game_moves ADD COLUMN ply_number INT UNSIGNED NULL AFTER game_id",
            'user_id' => "ALTER TABLE chat_game_moves ADD COLUMN user_id INT UNSIGNED NULL AFTER ply_number",
            'move_uci' => "ALTER TABLE chat_game_moves ADD COLUMN move_uci VARCHAR(16) NOT NULL DEFAULT '' AFTER user_id",
            'move_notation' => "ALTER TABLE chat_game_moves ADD COLUMN move_notation VARCHAR(32) NOT NULL DEFAULT '' AFTER move_uci",
            'state_after' => "ALTER TABLE chat_game_moves ADD COLUMN state_after MEDIUMTEXT NULL AFTER move_notation",
            'created_at' => "ALTER TABLE chat_game_moves ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER state_after",
        ];
        foreach ($moveColumnMigrations as $columnName => $sql) {
            if (!isset($moveColumns[$columnName])) {
                $pdo->exec($sql);
            }
        }

        $pdo->exec("
            UPDATE chat_game_moves
            SET move_notation = ''
            WHERE move_notation IS NULL
        ");
        $pdo->exec("
            UPDATE chat_game_moves
            SET move_uci = ''
            WHERE move_uci IS NULL
        ");

        $moveIndexes = [];
        $moveIndexStmt = $pdo->query("SHOW INDEX FROM chat_game_moves");
        while ($row = $moveIndexStmt->fetch(PDO::FETCH_ASSOC)) {
            $moveIndexes[(string)($row['Key_name'] ?? '')] = true;
        }
        $moveIndexMigrations = [
            'chat_game_moves_game_ply_unique' => "ALTER TABLE chat_game_moves ADD UNIQUE KEY chat_game_moves_game_ply_unique (game_id, ply_number)",
            'chat_game_moves_game_idx' => "ALTER TABLE chat_game_moves ADD KEY chat_game_moves_game_idx (game_id, created_at)",
            'chat_game_moves_user_idx' => "ALTER TABLE chat_game_moves ADD KEY chat_game_moves_user_idx (user_id, created_at)",
        ];
        foreach ($moveIndexMigrations as $indexName => $sql) {
            if (!isset($moveIndexes[$indexName])) {
                $pdo->exec($sql);
            }
        }

        $messageColumns = [];
        $messageColumnStmt = $pdo->query("SHOW COLUMNS FROM chat_game_messages");
        while ($row = $messageColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $messageColumns[(string)($row['Field'] ?? '')] = true;
        }

        $messageColumnMigrations = [
            'game_id' => "ALTER TABLE chat_game_messages ADD COLUMN game_id INT UNSIGNED NULL AFTER id",
            'user_id' => "ALTER TABLE chat_game_messages ADD COLUMN user_id INT UNSIGNED NULL AFTER game_id",
            'sender_role' => "ALTER TABLE chat_game_messages ADD COLUMN sender_role VARCHAR(16) NOT NULL DEFAULT 'user' AFTER user_id",
            'message' => "ALTER TABLE chat_game_messages ADD COLUMN message TEXT NOT NULL AFTER sender_role",
            'created_at' => "ALTER TABLE chat_game_messages ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER message",
        ];
        foreach ($messageColumnMigrations as $columnName => $sql) {
            if (!isset($messageColumns[$columnName])) {
                $pdo->exec($sql);
            }
        }

        $messageIndexes = [];
        $messageIndexStmt = $pdo->query("SHOW INDEX FROM chat_game_messages");
        while ($row = $messageIndexStmt->fetch(PDO::FETCH_ASSOC)) {
            $messageIndexes[(string)($row['Key_name'] ?? '')] = true;
        }
        $messageIndexMigrations = [
            'chat_game_messages_game_idx' => "ALTER TABLE chat_game_messages ADD KEY chat_game_messages_game_idx (game_id, created_at)",
            'chat_game_messages_user_idx' => "ALTER TABLE chat_game_messages ADD KEY chat_game_messages_user_idx (user_id, created_at)",
        ];
        foreach ($messageIndexMigrations as $indexName => $sql) {
            if (!isset($messageIndexes[$indexName])) {
                $pdo->exec($sql);
            }
        }

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "chat games ready\n");
    } catch (Throwable $e) {
        error_log('[CHAT GAMES SCHEMA] ' . $e->getMessage());
    }
}

function qt_game_label(string $gameType): string
{
    $normalized = strtolower(trim($gameType));
    if ($normalized === QT_GAME_TYPE_CHESS) {
        return 'Chess';
    }
    if ($normalized === QT_GAME_TYPE_CHECKERS) {
        return 'Checkers';
    }
    if ($normalized === QT_GAME_TYPE_CONNECT_FOUR) {
        return 'Connect Four';
    }
    if ($normalized === QT_GAME_TYPE_SKETCHOFF) {
        return 'Sketchoff';
    }

    return ucfirst($normalized !== '' ? $normalized : 'Game');
}

function qt_game_is_supported_type(string $gameType): bool
{
    return in_array(strtolower(trim($gameType)), [
        QT_GAME_TYPE_CHESS,
        QT_GAME_TYPE_CHECKERS,
        QT_GAME_TYPE_CONNECT_FOUR,
        QT_GAME_TYPE_SKETCHOFF,
    ], true);
}

function qt_game_supports_bot(string $gameType): bool
{
    return in_array(strtolower(trim($gameType)), [
        QT_GAME_TYPE_CHESS,
        QT_GAME_TYPE_CHECKERS,
        QT_GAME_TYPE_CONNECT_FOUR,
    ], true);
}

function qt_game_allowed_time_controls(): array
{
    return [60, 180, 300, 600, 900, 1800];
}

function qt_game_normalize_time_control_seconds(mixed $value): int
{
    $seconds = (int)$value;
    return in_array($seconds, qt_game_allowed_time_controls(), true)
        ? $seconds
        : QT_GAME_DEFAULT_TIME_CONTROL_SECONDS;
}

function qt_game_normalize_opponent_mode(mixed $value): string
{
    return strtolower(trim((string)$value)) === QT_GAME_OPPONENT_BOT
        ? QT_GAME_OPPONENT_BOT
        : QT_GAME_OPPONENT_HUMAN;
}

function qt_game_normalize_color_preference(mixed $value): string
{
    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, ['white', 'black', 'random'], true)) {
        return $normalized;
    }
    return 'random';
}

function qt_game_resolve_creator_color(string $preference): string
{
    if ($preference === 'white') {
        return 'w';
    }
    if ($preference === 'black') {
        return 'b';
    }
    return random_int(0, 1) === 0 ? 'w' : 'b';
}

function qt_game_opponent_color(string $color): string
{
    return $color === 'b' ? 'w' : 'b';
}

function qt_game_is_bot_game(array $game): bool
{
    return (int)($game['bot_enabled'] ?? 0) === 1;
}

function qt_game_bot_color(array $game): ?string
{
    $botColor = strtolower(trim((string)($game['bot_color'] ?? '')));
    return in_array($botColor, ['w', 'b'], true) ? $botColor : null;
}

function qt_game_build_bot_card(string $seatColor): array
{
    return [
        'user_id' => 0,
        'display_name' => QT_GAME_BOT_NAME,
        'profile_pic' => QT_GAME_BOT_PROFILE_PIC,
        'is_bot' => true,
        'seat_color' => $seatColor,
    ];
}

function qt_build_game_message(int $gameId, string $gameType): string
{
    return QT_GAME_MESSAGE_PREFIX . json_encode([
        'game_id' => $gameId,
        'game_type' => strtolower(trim($gameType)),
        'label' => qt_game_label($gameType),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function qt_parse_game_message(?string $message): ?array
{
    $raw = trim((string)$message);
    if ($raw === '' || !str_starts_with($raw, QT_GAME_MESSAGE_PREFIX)) {
        return null;
    }

    $payload = json_decode(substr($raw, strlen(QT_GAME_MESSAGE_PREFIX)), true);
    if (!is_array($payload)) {
        return null;
    }

    $gameId = (int)($payload['game_id'] ?? 0);
    $gameType = strtolower(trim((string)($payload['game_type'] ?? '')));
    if ($gameId <= 0 || $gameType === '') {
        return null;
    }

    return [
        'game_id' => $gameId,
        'game_type' => $gameType,
        'label' => qt_game_label($gameType),
    ];
}

function qt_game_initial_chess_state(): array
{
    $state = [
        'version' => 1,
        'board' => [
            ['br', 'bn', 'bb', 'bq', 'bk', 'bb', 'bn', 'br'],
            ['bp', 'bp', 'bp', 'bp', 'bp', 'bp', 'bp', 'bp'],
            [null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            ['wp', 'wp', 'wp', 'wp', 'wp', 'wp', 'wp', 'wp'],
            ['wr', 'wn', 'wb', 'wq', 'wk', 'wb', 'wn', 'wr'],
        ],
        'turn' => 'w',
        'castling' => [
            'w' => ['k' => true, 'q' => true],
            'b' => ['k' => true, 'q' => true],
        ],
        'enPassant' => null,
        'halfmoveClock' => 0,
        'fullmoveNumber' => 1,
        'checkColor' => null,
        'winnerColor' => null,
        'resultCode' => null,
        'resultLabel' => null,
        'lastMove' => null,
        'repetition' => [],
    ];

    $positionKey = qt_game_chess_position_key($state);
    $state['repetition'] = [$positionKey => 1];

    return $state;
}

function qt_game_initial_checkers_state(): array
{
    $board = array_fill(0, 8, array_fill(0, 8, null));
    for ($rowIndex = 0; $rowIndex < 3; $rowIndex++) {
        for ($colIndex = 0; $colIndex < 8; $colIndex++) {
            if ((($rowIndex + $colIndex) % 2) === 1) {
                $board[$rowIndex][$colIndex] = 'bm';
            }
        }
    }
    for ($rowIndex = 5; $rowIndex < 8; $rowIndex++) {
        for ($colIndex = 0; $colIndex < 8; $colIndex++) {
            if ((($rowIndex + $colIndex) % 2) === 1) {
                $board[$rowIndex][$colIndex] = 'wm';
            }
        }
    }

    return [
        'version' => 1,
        'board' => $board,
        'turn' => 'w',
        'winnerColor' => null,
        'resultCode' => null,
        'resultLabel' => null,
        'lastMove' => null,
        'moveCount' => 0,
    ];
}

function qt_game_initial_connect_four_state(): array
{
    return [
        'version' => 1,
        'board' => array_fill(0, 6, array_fill(0, 7, null)),
        'turn' => 'w',
        'winnerColor' => null,
        'resultCode' => null,
        'resultLabel' => null,
        'lastMove' => null,
        'moveCount' => 0,
    ];
}

function qt_game_initial_sketchoff_state(): array
{
    return qt_sketchoff_initial_state();
}

function qt_game_initial_state_for_type(string $gameType): ?array
{
    $normalized = strtolower(trim($gameType));
    if ($normalized === QT_GAME_TYPE_CHESS) {
        return qt_game_initial_chess_state();
    }
    if ($normalized === QT_GAME_TYPE_CHECKERS) {
        return qt_game_initial_checkers_state();
    }
    if ($normalized === QT_GAME_TYPE_CONNECT_FOUR) {
        return qt_game_initial_connect_four_state();
    }
    if ($normalized === QT_GAME_TYPE_SKETCHOFF) {
        return qt_game_initial_sketchoff_state();
    }

    return null;
}

function qt_game_chess_position_key(array $state): string
{
    $boardRows = [];
    $board = is_array($state['board'] ?? null) ? $state['board'] : [];
    for ($rowIndex = 0; $rowIndex < 8; $rowIndex++) {
        $row = is_array($board[$rowIndex] ?? null) ? $board[$rowIndex] : [];
        $buffer = '';
        $emptyCount = 0;
        for ($colIndex = 0; $colIndex < 8; $colIndex++) {
            $piece = qt_game_sanitize_chess_piece($row[$colIndex] ?? null);
            if ($piece === null) {
                $emptyCount++;
                continue;
            }
            if ($emptyCount > 0) {
                $buffer .= (string)$emptyCount;
                $emptyCount = 0;
            }
            $buffer .= $piece;
        }
        if ($emptyCount > 0) {
            $buffer .= (string)$emptyCount;
        }
        $boardRows[] = $buffer !== '' ? $buffer : '8';
    }

    $castling = '';
    foreach (['w', 'b'] as $color) {
        $castlingData = is_array($state['castling'][$color] ?? null) ? $state['castling'][$color] : [];
        if (!empty($castlingData['k'])) {
            $castling .= $color === 'w' ? 'K' : 'k';
        }
        if (!empty($castlingData['q'])) {
            $castling .= $color === 'w' ? 'Q' : 'q';
        }
    }
    if ($castling === '') {
        $castling = '-';
    }

    $turn = ($state['turn'] ?? 'w') === 'b' ? 'b' : 'w';
    $enPassant = trim((string)($state['enPassant'] ?? ''));
    if (!preg_match('/^[a-h][36]$/', $enPassant)) {
        $enPassant = '-';
    }

    return implode('|', [
        implode('/', $boardRows),
        $turn,
        $castling,
        $enPassant,
    ]);
}

function qt_game_sanitize_chess_piece(mixed $value): ?string
{
    $piece = strtolower(trim((string)$value));
    if ($piece === '') {
        return null;
    }

    static $allowedPieces = [
        'wp' => true,
        'wn' => true,
        'wb' => true,
        'wr' => true,
        'wq' => true,
        'wk' => true,
        'bp' => true,
        'bn' => true,
        'bb' => true,
        'br' => true,
        'bq' => true,
        'bk' => true,
    ];

    return isset($allowedPieces[$piece]) ? $piece : null;
}

function qt_game_sanitize_checkers_piece(mixed $value): ?string
{
    $piece = strtolower(trim((string)$value));
    if ($piece === '') {
        return null;
    }

    static $allowedPieces = [
        'wm' => true,
        'wk' => true,
        'bm' => true,
        'bk' => true,
    ];

    return isset($allowedPieces[$piece]) ? $piece : null;
}

function qt_game_sanitize_connect_four_piece(mixed $value): ?string
{
    $piece = strtolower(trim((string)$value));
    if ($piece === '') {
        return null;
    }

    static $allowedPieces = [
        'wc' => true,
        'bc' => true,
    ];

    return isset($allowedPieces[$piece]) ? $piece : null;
}

function qt_game_sanitize_chess_state(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $board = [];
    $whiteKingCount = 0;
    $blackKingCount = 0;

    for ($rowIndex = 0; $rowIndex < 8; $rowIndex++) {
        $sourceRow = is_array($value['board'][$rowIndex] ?? null) ? $value['board'][$rowIndex] : [];
        $normalizedRow = [];
        for ($colIndex = 0; $colIndex < 8; $colIndex++) {
            $piece = qt_game_sanitize_chess_piece($sourceRow[$colIndex] ?? null);
            if ($piece === 'wk') {
                $whiteKingCount++;
            } elseif ($piece === 'bk') {
                $blackKingCount++;
            }
            $normalizedRow[] = $piece;
        }
        $board[] = $normalizedRow;
    }

    if ($whiteKingCount !== 1 || $blackKingCount !== 1) {
        return null;
    }

    foreach ([0, 7] as $rowIndex) {
        foreach ($board[$rowIndex] as $piece) {
            if ($piece === 'wp' || $piece === 'bp') {
                return null;
            }
        }
    }

    $turn = ($value['turn'] ?? 'w') === 'b' ? 'b' : 'w';
    $castlingSource = is_array($value['castling'] ?? null) ? $value['castling'] : [];
    $castling = [
        'w' => [
            'k' => !empty($castlingSource['w']['k']),
            'q' => !empty($castlingSource['w']['q']),
        ],
        'b' => [
            'k' => !empty($castlingSource['b']['k']),
            'q' => !empty($castlingSource['b']['q']),
        ],
    ];

    $enPassant = trim((string)($value['enPassant'] ?? ''));
    if (!preg_match('/^[a-h][36]$/', $enPassant)) {
        $enPassant = null;
    }

    $halfmoveClock = max(0, min(1000, (int)($value['halfmoveClock'] ?? 0)));
    $fullmoveNumber = max(1, min(2000, (int)($value['fullmoveNumber'] ?? 1)));

    $checkColor = trim((string)($value['checkColor'] ?? ''));
    if ($checkColor !== 'w' && $checkColor !== 'b') {
        $checkColor = null;
    }

    $winnerColor = trim((string)($value['winnerColor'] ?? ''));
    if ($winnerColor !== 'w' && $winnerColor !== 'b') {
        $winnerColor = null;
    }

    $resultCode = trim((string)($value['resultCode'] ?? ''));
    if ($resultCode === '') {
        $resultCode = null;
    }

    $resultLabel = trim((string)($value['resultLabel'] ?? ''));
    if ($resultLabel === '') {
        $resultLabel = null;
    }

    $lastMove = null;
    if (is_array($value['lastMove'] ?? null)) {
        $from = strtolower(trim((string)($value['lastMove']['from'] ?? '')));
        $to = strtolower(trim((string)($value['lastMove']['to'] ?? '')));
        if (preg_match('/^[a-h][1-8]$/', $from) && preg_match('/^[a-h][1-8]$/', $to)) {
            $promotion = strtolower(trim((string)($value['lastMove']['promotion'] ?? '')));
            if (!in_array($promotion, ['q', 'r', 'b', 'n'], true)) {
                $promotion = null;
            }
            $lastMove = [
                'from' => $from,
                'to' => $to,
                'piece' => qt_game_sanitize_chess_piece($value['lastMove']['piece'] ?? null),
                'promotion' => $promotion,
                'notation' => trim((string)($value['lastMove']['notation'] ?? '')),
                'uci' => trim((string)($value['lastMove']['uci'] ?? '')),
                'playerColor' => in_array(($value['lastMove']['playerColor'] ?? ''), ['w', 'b'], true)
                    ? (string)$value['lastMove']['playerColor']
                    : null,
            ];
        }
    }

    $repetition = [];
    if (is_array($value['repetition'] ?? null)) {
        foreach ($value['repetition'] as $key => $count) {
            $normalizedKey = trim((string)$key);
            if ($normalizedKey === '') {
                continue;
            }
            $repetition[$normalizedKey] = max(1, min(10, (int)$count));
        }
    }

    $normalized = [
        'version' => 1,
        'board' => $board,
        'turn' => $turn,
        'castling' => $castling,
        'enPassant' => $enPassant,
        'halfmoveClock' => $halfmoveClock,
        'fullmoveNumber' => $fullmoveNumber,
        'checkColor' => $checkColor,
        'winnerColor' => $winnerColor,
        'resultCode' => $resultCode,
        'resultLabel' => $resultLabel,
        'lastMove' => $lastMove,
        'repetition' => $repetition,
    ];

    $positionKey = qt_game_chess_position_key($normalized);
    if (!isset($normalized['repetition'][$positionKey])) {
        $normalized['repetition'][$positionKey] = 1;
    }

    return $normalized;
}

function qt_game_sanitize_checkers_state(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $board = [];
    for ($rowIndex = 0; $rowIndex < 8; $rowIndex++) {
        $sourceRow = is_array($value['board'][$rowIndex] ?? null) ? $value['board'][$rowIndex] : [];
        $normalizedRow = [];
        for ($colIndex = 0; $colIndex < 8; $colIndex++) {
            $piece = qt_game_sanitize_checkers_piece($sourceRow[$colIndex] ?? null);
            if ($piece !== null && (($rowIndex + $colIndex) % 2) === 0) {
                return null;
            }
            $normalizedRow[] = $piece;
        }
        $board[] = $normalizedRow;
    }

    $turn = ($value['turn'] ?? 'w') === 'b' ? 'b' : 'w';

    $winnerColor = trim((string)($value['winnerColor'] ?? ''));
    if ($winnerColor !== 'w' && $winnerColor !== 'b') {
        $winnerColor = null;
    }

    $resultCode = trim((string)($value['resultCode'] ?? ''));
    if ($resultCode === '') {
        $resultCode = null;
    }

    $resultLabel = trim((string)($value['resultLabel'] ?? ''));
    if ($resultLabel === '') {
        $resultLabel = null;
    }

    $lastMove = null;
    if (is_array($value['lastMove'] ?? null)) {
        $from = strtolower(trim((string)($value['lastMove']['from'] ?? '')));
        $to = strtolower(trim((string)($value['lastMove']['to'] ?? '')));
        if (preg_match('/^[a-h][1-8]$/', $from) && preg_match('/^[a-h][1-8]$/', $to)) {
            $promotion = strtolower(trim((string)($value['lastMove']['promotion'] ?? '')));
            if (!in_array($promotion, ['k'], true)) {
                $promotion = null;
            }
            $lastMove = [
                'from' => $from,
                'to' => $to,
                'piece' => qt_game_sanitize_checkers_piece($value['lastMove']['piece'] ?? null),
                'promotion' => $promotion,
                'notation' => trim((string)($value['lastMove']['notation'] ?? '')),
                'uci' => trim((string)($value['lastMove']['uci'] ?? '')),
                'playerColor' => in_array(($value['lastMove']['playerColor'] ?? ''), ['w', 'b'], true)
                    ? (string)$value['lastMove']['playerColor']
                    : null,
            ];
        }
    }

    return [
        'version' => 1,
        'board' => $board,
        'turn' => $turn,
        'winnerColor' => $winnerColor,
        'resultCode' => $resultCode,
        'resultLabel' => $resultLabel,
        'lastMove' => $lastMove,
        'moveCount' => max(0, min(2000, (int)($value['moveCount'] ?? 0))),
    ];
}

function qt_game_sanitize_connect_four_state(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $board = [];
    for ($rowIndex = 0; $rowIndex < 6; $rowIndex++) {
        $sourceRow = is_array($value['board'][$rowIndex] ?? null) ? $value['board'][$rowIndex] : [];
        $normalizedRow = [];
        for ($colIndex = 0; $colIndex < 7; $colIndex++) {
            $normalizedRow[] = qt_game_sanitize_connect_four_piece($sourceRow[$colIndex] ?? null);
        }
        $board[] = $normalizedRow;
    }

    $turn = ($value['turn'] ?? 'w') === 'b' ? 'b' : 'w';

    $winnerColor = trim((string)($value['winnerColor'] ?? ''));
    if ($winnerColor !== 'w' && $winnerColor !== 'b') {
        $winnerColor = null;
    }

    $resultCode = trim((string)($value['resultCode'] ?? ''));
    if ($resultCode === '') {
        $resultCode = null;
    }

    $resultLabel = trim((string)($value['resultLabel'] ?? ''));
    if ($resultLabel === '') {
        $resultLabel = null;
    }

    $lastMove = null;
    if (is_array($value['lastMove'] ?? null)) {
        $from = strtolower(trim((string)($value['lastMove']['from'] ?? '')));
        $to = strtolower(trim((string)($value['lastMove']['to'] ?? '')));
        if (preg_match('/^[a-g][1-6]$/', $from) && preg_match('/^[a-g][1-6]$/', $to)) {
            $lastMove = [
                'from' => $from,
                'to' => $to,
                'piece' => qt_game_sanitize_connect_four_piece($value['lastMove']['piece'] ?? null),
                'promotion' => null,
                'notation' => trim((string)($value['lastMove']['notation'] ?? '')),
                'uci' => trim((string)($value['lastMove']['uci'] ?? '')),
                'playerColor' => in_array(($value['lastMove']['playerColor'] ?? ''), ['w', 'b'], true)
                    ? (string)$value['lastMove']['playerColor']
                    : null,
            ];
        }
    }

    return [
        'version' => 1,
        'board' => $board,
        'turn' => $turn,
        'winnerColor' => $winnerColor,
        'resultCode' => $resultCode,
        'resultLabel' => $resultLabel,
        'lastMove' => $lastMove,
        'moveCount' => max(0, min(2000, (int)($value['moveCount'] ?? 0))),
    ];
}

function qt_game_sanitize_sketchoff_state(mixed $value): ?array
{
    return qt_sketchoff_sanitize_state($value);
}

function qt_game_sanitize_state_for_type(string $gameType, mixed $value): ?array
{
    $normalizedType = strtolower(trim($gameType));
    if ($normalizedType === QT_GAME_TYPE_CHECKERS) {
        return qt_game_sanitize_checkers_state($value);
    }
    if ($normalizedType === QT_GAME_TYPE_CONNECT_FOUR) {
        return qt_game_sanitize_connect_four_state($value);
    }
    if ($normalizedType === QT_GAME_TYPE_SKETCHOFF) {
        return qt_game_sanitize_sketchoff_state($value);
    }

    return qt_game_sanitize_chess_state($value);
}

function qt_game_fetch_user_card(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            COALESCE(NULLIF(display_name, ''), username) AS display_name,
            COALESCE(NULLIF(profile_pic, ''), 'images/default-profile.png') AS profile_pic
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'user_id' => (int)($row['id'] ?? 0),
        'display_name' => trim((string)($row['display_name'] ?? 'User')),
        'profile_pic' => trim((string)($row['profile_pic'] ?? 'images/default-profile.png')),
    ];
}

function qt_game_fetch(PDO $pdo, int $gameId): ?array
{
    if ($gameId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM chat_games WHERE id = ? LIMIT 1");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    return $game ?: null;
}

function qt_game_player_color(array $game, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    if ((int)($game['white_user_id'] ?? 0) === $userId) {
        return 'w';
    }
    if ((int)($game['black_user_id'] ?? 0) === $userId) {
        return 'b';
    }

    return null;
}

function qt_game_assign_waiting_seats(array $game, int $userId): array
{
    $whiteUserId = (int)($game['white_user_id'] ?? 0);
    $blackUserId = (int)($game['black_user_id'] ?? 0);

    if ($whiteUserId === $userId || $blackUserId === $userId) {
        return [$whiteUserId, $blackUserId];
    }

    if ($whiteUserId <= 0) {
        $whiteUserId = $userId;
    } elseif ($blackUserId <= 0) {
        $blackUserId = $userId;
    }

    return [$whiteUserId, $blackUserId];
}

function qt_game_clock_snapshot(array $game, array $state): array
{
    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    if ($gameType === QT_GAME_TYPE_SKETCHOFF) {
        $normalizedState = qt_sketchoff_prepare_state_for_active_round($state);
        $remainingMs = qt_sketchoff_time_remaining_ms($normalizedState);
        $initialMs = max(0, (int)($normalizedState['roundSeconds'] ?? QT_SKETCHOFF_ROUND_SECONDS)) * 1000;
        return [
            'initial_ms' => $initialMs,
            'white_base_ms' => $initialMs,
            'black_base_ms' => $initialMs,
            'white_ms' => $remainingMs,
            'black_ms' => $remainingMs,
            'turn_started_at' => $normalizedState['promptStartedAt'] ?? null,
            'elapsed_ms' => max(0, $initialMs - $remainingMs),
        ];
    }

    $initialMs = max(0, (int)($game['time_control_seconds'] ?? QT_GAME_DEFAULT_TIME_CONTROL_SECONDS)) * 1000;
    $whiteBaseMs = max(0, (int)($game['white_time_ms'] ?? $initialMs));
    $blackBaseMs = max(0, (int)($game['black_time_ms'] ?? $initialMs));
    $turnColor = ($state['turn'] ?? 'w') === 'b' ? 'b' : 'w';
    $turnStartedAt = trim((string)($game['turn_started_at'] ?? ''));
    $elapsedMs = 0;

    if ((string)($game['status'] ?? '') === QT_GAME_STATUS_ACTIVE && $turnStartedAt !== '') {
        $turnTimestamp = strtotime($turnStartedAt);
        if ($turnTimestamp !== false) {
            $elapsedMs = max(0, (time() - $turnTimestamp) * 1000);
        }
    }

    $whiteMs = $whiteBaseMs;
    $blackMs = $blackBaseMs;
    if ((string)($game['status'] ?? '') === QT_GAME_STATUS_ACTIVE) {
        if ($turnColor === 'w') {
            $whiteMs = max(0, $whiteBaseMs - $elapsedMs);
        } else {
            $blackMs = max(0, $blackBaseMs - $elapsedMs);
        }
    }

    return [
        'initial_ms' => $initialMs,
        'white_base_ms' => $whiteBaseMs,
        'black_base_ms' => $blackBaseMs,
        'white_ms' => $whiteMs,
        'black_ms' => $blackMs,
        'turn_started_at' => $turnStartedAt !== '' ? $turnStartedAt : null,
        'elapsed_ms' => $elapsedMs,
    ];
}

function qt_game_winner_user_id_from_color(array $game, ?string $winnerColor): ?int
{
    if ($winnerColor === 'w') {
        $winnerUserId = (int)($game['white_user_id'] ?? 0);
        return $winnerUserId > 0 ? $winnerUserId : null;
    }
    if ($winnerColor === 'b') {
        $winnerUserId = (int)($game['black_user_id'] ?? 0);
        return $winnerUserId > 0 ? $winnerUserId : null;
    }
    return null;
}

function qt_game_result_reason_label(?string $resultCode, ?string $fallbackLabel = null): ?string
{
    $normalizedCode = strtolower(trim((string)($resultCode ?? '')));
    if ($normalizedCode === '') {
        $normalizedFallback = trim((string)($fallbackLabel ?? ''));
        return $normalizedFallback !== '' ? $normalizedFallback : null;
    }

    $labels = [
        'checkmate' => 'Checkmate',
        'stalemate' => 'Stalemate',
        'draw_insufficient_material' => 'Insufficient material',
        'draw_fifty_move' => 'Fifty-move rule',
        'draw_threefold' => 'Threefold repetition',
        'timeout' => 'On time',
        'resignation' => 'Resignation',
        'capture_all' => 'Captured all pieces',
        'no_moves' => 'No legal moves',
        'connect_four' => 'Connected four',
        'board_full' => 'Board filled up',
    ];

    return $labels[$normalizedCode] ?? (trim((string)($fallbackLabel ?? '')) ?: ucfirst(str_replace('_', ' ', $normalizedCode)));
}

function qt_game_result_summary_label(array $state, ?array $whiteCard, ?array $blackCard): ?string
{
    $resultCode = trim((string)($state['resultCode'] ?? ''));
    if ($resultCode === '') {
        return null;
    }

    $winnerColor = ($state['winnerColor'] ?? '') === 'b' ? 'b' : (($state['winnerColor'] ?? '') === 'w' ? 'w' : null);
    if ($winnerColor === null) {
        return 'Draw';
    }

    $winnerCard = $winnerColor === 'w' ? $whiteCard : $blackCard;
    $winnerName = trim((string)($winnerCard['display_name'] ?? ''));
    $colorLabel = $winnerColor === 'w' ? 'White' : 'Black';
    return $winnerName !== ''
        ? $colorLabel . ' wins (' . $winnerName . ')'
        : $colorLabel . ' wins';
}

function qt_game_sync_chess_runtime(PDO $pdo, array $game): array
{
    if ((string)($game['status'] ?? '') !== QT_GAME_STATUS_ACTIVE) {
        return $game;
    }

    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    if ($gameType === QT_GAME_TYPE_SKETCHOFF) {
        $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
            ?: qt_game_initial_state_for_type($gameType)
            ?: qt_sketchoff_initial_state();
        $state = qt_sketchoff_prepare_state_for_active_round($state);

        $statePayload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($statePayload) && $statePayload !== '' && $statePayload !== (string)($game['state_payload'] ?? '')) {
            $stmt = $pdo->prepare("
                UPDATE chat_games
                SET state_payload = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$statePayload, (int)($game['id'] ?? 0)]);
            $game = qt_game_fetch($pdo, (int)($game['id'] ?? 0)) ?: $game;
        }

        if (
            trim((string)($state['resultCode'] ?? '')) === ''
            && (
                (qt_sketchoff_get_submission($state, 'w') && qt_sketchoff_get_submission($state, 'b'))
                || qt_sketchoff_time_remaining_ms($state) <= 0
            )
        ) {
            return qt_sketchoff_finalize_game($pdo, $game, $state);
        }

        return $game;
    }

    $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_game_initial_chess_state();
    if (trim((string)($state['resultCode'] ?? '')) !== '') {
        return $game;
    }

    $clockSnapshot = qt_game_clock_snapshot($game, $state);
    $turnColor = ($state['turn'] ?? 'w') === 'b' ? 'b' : 'w';
    $remainingMs = $turnColor === 'w'
        ? (int)($clockSnapshot['white_ms'] ?? 0)
        : (int)($clockSnapshot['black_ms'] ?? 0);

    if ($remainingMs > 0) {
        return $game;
    }

    $winnerColor = qt_game_opponent_color($turnColor);
    $state['winnerColor'] = $winnerColor;
    $state['resultCode'] = 'timeout';
    $state['resultLabel'] = 'On time';

    $statePayload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($statePayload) || $statePayload === '') {
        return $game;
    }

    $whiteTimeMs = (int)($clockSnapshot['white_ms'] ?? 0);
    $blackTimeMs = (int)($clockSnapshot['black_ms'] ?? 0);
    $winnerUserId = qt_game_winner_user_id_from_color($game, $winnerColor);
    $completedAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
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
    $stmt->execute([
        $statePayload,
        QT_GAME_STATUS_COMPLETED,
        $winnerUserId,
        'timeout',
        'On time',
        $whiteTimeMs,
        $blackTimeMs,
        $completedAt,
        (int)($game['id'] ?? 0),
    ]);

    return qt_game_fetch($pdo, (int)($game['id'] ?? 0)) ?: $game;
}

function qt_game_user_can_access(PDO $pdo, array $game, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $groupId = (int)($game['group_id'] ?? 0);
    if ($groupId > 0) {
        return function_exists('qt_user_can_access_group')
            ? qt_user_can_access_group($pdo, $userId, $groupId)
            : false;
    }

    return $userId === (int)($game['creator_user_id'] ?? 0)
        || $userId === (int)($game['recipient_id'] ?? 0);
}

function qt_game_can_join(PDO $pdo, array $game, int $userId): bool
{
    if (!qt_game_user_can_access($pdo, $game, $userId)) {
        return false;
    }

    if ((string)($game['status'] ?? '') !== QT_GAME_STATUS_WAITING) {
        return false;
    }

    if (qt_game_is_bot_game($game)) {
        return false;
    }

    $creatorUserId = (int)($game['creator_user_id'] ?? 0);
    if ($userId === $creatorUserId) {
        return false;
    }

    $groupId = (int)($game['group_id'] ?? 0);
    if ($groupId > 0) {
        $whiteUserId = (int)($game['white_user_id'] ?? 0);
        $blackUserId = (int)($game['black_user_id'] ?? 0);
        return $whiteUserId <= 0 || $blackUserId <= 0;
    }

    $recipientId = (int)($game['recipient_id'] ?? 0);
    return $recipientId > 0 && $userId === $recipientId;
}

function qt_game_fetch_moves(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare("
        SELECT id, ply_number, user_id, move_uci, move_notation, created_at
        FROM chat_game_moves
        WHERE game_id = ?
        ORDER BY ply_number ASC
    ");
    $stmt->execute([$gameId]);

    $moves = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $moves[] = [
            'id' => (int)($row['id'] ?? 0),
            'ply_number' => (int)($row['ply_number'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'uci' => trim((string)($row['move_uci'] ?? '')),
            'notation' => trim((string)($row['move_notation'] ?? '')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
        ];
    }

    return $moves;
}

function qt_game_fetch_chat_messages(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare("
        SELECT
            gm.id,
            gm.game_id,
            gm.user_id,
            gm.sender_role,
            gm.message,
            gm.created_at,
            u.username,
            COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
            COALESCE(NULLIF(u.profile_pic, ''), 'images/default-profile.png') AS profile_pic
        FROM chat_game_messages gm
        LEFT JOIN users u ON u.id = gm.user_id
        WHERE gm.game_id = ?
        ORDER BY gm.created_at ASC, gm.id ASC
    ");
    $stmt->execute([$gameId]);

    $messages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $senderRole = strtolower(trim((string)($row['sender_role'] ?? 'user')));
        $isBot = $senderRole === 'bot';
        $messages[] = [
            'id' => (int)($row['id'] ?? 0),
            'game_id' => (int)($row['game_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'sender_role' => $isBot ? 'bot' : 'user',
            'display_name' => $isBot
                ? QT_GAME_BOT_NAME
                : trim((string)($row['display_name'] ?? $row['username'] ?? 'Player')),
            'profile_pic' => $isBot
                ? QT_GAME_BOT_PROFILE_PIC
                : trim((string)($row['profile_pic'] ?? 'images/default-profile.png')),
            'message' => trim((string)($row['message'] ?? '')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'is_bot' => $isBot,
        ];
    }

    return $messages;
}

function qt_game_build_payload(PDO $pdo, array $game, int $viewerUserId): array
{
    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    $state = qt_game_sanitize_state_for_type($gameType, json_decode((string)($game['state_payload'] ?? ''), true))
        ?: qt_game_initial_state_for_type($gameType)
        ?: qt_game_initial_chess_state();
    $botEnabled = qt_game_is_bot_game($game);
    $botColor = qt_game_bot_color($game);

    $whiteCard = qt_game_fetch_user_card($pdo, (int)($game['white_user_id'] ?? 0));
    $blackUserId = (int)($game['black_user_id'] ?? 0);
    $blackCard = $blackUserId > 0
        ? qt_game_fetch_user_card($pdo, $blackUserId)
        : null;

    if ($blackCard === null && (int)($game['group_id'] ?? 0) === 0) {
        $recipientId = (int)($game['recipient_id'] ?? 0);
        if ($recipientId > 0 && $recipientId !== (int)($game['white_user_id'] ?? 0)) {
            $blackCard = qt_game_fetch_user_card($pdo, $recipientId);
        }
    }

    if ($botEnabled && $botColor === 'w') {
        $whiteCard = qt_game_build_bot_card('w');
    } elseif ($botEnabled && $botColor === 'b') {
        $blackCard = qt_game_build_bot_card('b');
    }

    $viewerColor = qt_game_player_color($game, $viewerUserId);
    $status = trim((string)($game['status'] ?? QT_GAME_STATUS_WAITING));
    if (!in_array($status, [QT_GAME_STATUS_WAITING, QT_GAME_STATUS_ACTIVE, QT_GAME_STATUS_COMPLETED], true)) {
        $status = QT_GAME_STATUS_WAITING;
    }

    $resultCode = trim((string)($state['resultCode'] ?? $game['result_code'] ?? '')) ?: null;
    $resultReasonLabel = qt_game_result_reason_label(
        $resultCode,
        trim((string)($state['resultLabel'] ?? $game['result_label'] ?? '')) ?: null
    );
    $resultSummaryLabel = qt_game_result_summary_label($state, $whiteCard, $blackCard);
    $clockSnapshot = qt_game_clock_snapshot($game, $state);
    $timeControlSeconds = qt_game_normalize_time_control_seconds($game['time_control_seconds'] ?? QT_GAME_DEFAULT_TIME_CONTROL_SECONDS);
    $botTurn = $botEnabled && $botColor !== null && ($state['turn'] ?? 'w') === $botColor;
    $viewerCanResign = $status === QT_GAME_STATUS_ACTIVE && $viewerColor !== null;
    $viewerCanChat = $viewerColor !== null;
    $viewerCanMove = $gameType === QT_GAME_TYPE_SKETCHOFF
        ? false
        : ($status === QT_GAME_STATUS_ACTIVE && $viewerColor !== null && $viewerColor === ($state['turn'] ?? 'w'));
    $viewerCanSubmitDrawing = $gameType === QT_GAME_TYPE_SKETCHOFF
        && $status === QT_GAME_STATUS_ACTIVE
        && $viewerColor !== null
        && trim((string)($state['resultCode'] ?? '')) === ''
        && qt_sketchoff_get_submission($state, $viewerColor) === null
        && qt_sketchoff_time_remaining_ms($state) > 0;
    $viewerHasSubmittedDrawing = $gameType === QT_GAME_TYPE_SKETCHOFF
        && $viewerColor !== null
        && qt_sketchoff_get_submission($state, $viewerColor) !== null;
    $promptRemainingMs = $gameType === QT_GAME_TYPE_SKETCHOFF
        ? qt_sketchoff_time_remaining_ms($state)
        : null;

    return [
        'game' => [
            'id' => (int)($game['id'] ?? 0),
            'type' => $gameType,
            'label' => qt_game_label($gameType),
            'family_label' => 'Sidequest',
            'status' => $status,
            'result_code' => $resultCode,
            'result_label' => $resultReasonLabel,
            'result_reason_label' => $resultReasonLabel,
            'result_summary_label' => $resultSummaryLabel,
            'creator_user_id' => (int)($game['creator_user_id'] ?? 0),
            'winner_user_id' => (int)($game['winner_user_id'] ?? 0) ?: null,
            'group_id' => (int)($game['group_id'] ?? 0) ?: null,
            'recipient_id' => (int)($game['recipient_id'] ?? 0) ?: null,
            'bot_enabled' => $botEnabled,
            'bot_color' => $botColor,
            'time_control_seconds' => $timeControlSeconds,
            'round_seconds' => $gameType === QT_GAME_TYPE_SKETCHOFF ? max(15, (int)($state['roundSeconds'] ?? QT_SKETCHOFF_ROUND_SECONDS)) : null,
            'created_at' => trim((string)($game['created_at'] ?? '')),
            'updated_at' => trim((string)($game['updated_at'] ?? '')),
            'started_at' => trim((string)($game['started_at'] ?? '')) ?: null,
            'completed_at' => trim((string)($game['completed_at'] ?? '')) ?: null,
        ],
        'players' => [
            'white' => $whiteCard,
            'black' => $blackCard,
        ],
        'viewer' => [
            'user_id' => $viewerUserId,
            'color' => $viewerColor,
            'can_join' => qt_game_can_join($pdo, $game, $viewerUserId),
            'can_move' => $viewerCanMove,
            'can_chat' => $viewerCanChat,
            'can_resign' => $viewerCanResign,
            'is_bot_turn' => $botTurn,
            'can_submit_drawing' => $viewerCanSubmitDrawing,
            'has_submitted_drawing' => $viewerHasSubmittedDrawing,
        ],
        'clocks' => [
            'initial_ms' => (int)($clockSnapshot['initial_ms'] ?? 0),
            'white_ms' => (int)($clockSnapshot['white_ms'] ?? 0),
            'black_ms' => (int)($clockSnapshot['black_ms'] ?? 0),
            'turn_started_at' => $clockSnapshot['turn_started_at'] ?? null,
            'prompt_remaining_ms' => $promptRemainingMs,
        ],
        'state' => $state,
        'moves' => qt_game_fetch_moves($pdo, (int)($game['id'] ?? 0)),
        'chat_messages' => qt_game_fetch_chat_messages($pdo, (int)($game['id'] ?? 0)),
    ];
}
