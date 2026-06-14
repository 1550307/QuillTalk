<?php
declare(strict_types=1);

const QT_GAME_AI_DEFAULT_MODEL = 'meta-llama/llama-4-scout-17b-16e-instruct';
const QT_GAME_AI_FALLBACK_MODELS = [
    'llama-3.3-70b-versatile',
    'llama-3.1-8b-instant',
];

function qt_game_ai_http_request(string $method, string $url, ?array $jsonBody = null, array $headers = [], int $timeout = 30): array
{
    $defaultHeaders = ['Accept: application/json'];
    $payload = null;

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $defaultHeaders[] = 'Content-Type: application/json';
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for QuillTalk AI bot support');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException($error !== '' ? $error : 'Unknown cURL error');
    }

    return [
        'status' => $httpCode,
        'body' => (string)$responseBody,
    ];
}

function qt_game_ai_model_candidates(): array
{
    $candidates = [];
    $configuredPrimary = trim((string)(
        getenv('GROQ_MODEL')
        ?: ($_SERVER['GROQ_MODEL'] ?? '')
    ));
    $configuredFallbacks = trim((string)(
        getenv('GROQ_FALLBACK_MODELS')
        ?: ($_SERVER['GROQ_FALLBACK_MODELS'] ?? '')
    ));

    if ($configuredPrimary !== '') {
        $candidates[] = $configuredPrimary;
    } else {
        $candidates[] = QT_GAME_AI_DEFAULT_MODEL;
    }

    if ($configuredFallbacks !== '') {
        foreach (preg_split('/[\s,]+/', $configuredFallbacks) ?: [] as $modelId) {
            $modelId = trim((string)$modelId);
            if ($modelId !== '') {
                $candidates[] = $modelId;
            }
        }
    } else {
        $candidates = array_merge($candidates, QT_GAME_AI_FALLBACK_MODELS);
    }

    $uniqueCandidates = [];
    $seen = [];
    foreach ($candidates as $modelId) {
        if ($modelId === '' || isset($seen[$modelId])) {
            continue;
        }
        $seen[$modelId] = true;
        $uniqueCandidates[] = $modelId;
    }

    return $uniqueCandidates;
}

function qt_game_ai_should_retry_model(int $status, array $decoded): bool
{
    if ($status === 429) {
        return true;
    }

    $errorMessage = strtolower(trim((string)($decoded['error']['message'] ?? '')));
    if ($errorMessage === '') {
        return false;
    }

    foreach (['rate limit', 'too many requests', 'model', 'permission', 'restricted', 'not found', 'unavailable', 'disabled'] as $indicator) {
        if (str_contains($errorMessage, $indicator)) {
            return true;
        }
    }

    return false;
}

function qt_game_ai_extract_json_object(string $content): ?array
{
    $trimmed = trim($content);
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $trimmed, $matches)) {
        $decoded = json_decode(trim((string)($matches[1] ?? '')), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if (preg_match('/\{[\s\S]*\}/', $trimmed, $matches)) {
        $decoded = json_decode((string)$matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function qt_game_ai_request_json(string $systemPrompt, string $userPrompt, float $temperature = 0.35, int $maxTokens = 220): ?array
{
    $apiKey = trim((string)(
        getenv('GROQ_API_KEY')
        ?: ($_SERVER['GROQ_API_KEY'] ?? '')
    ));
    if ($apiKey === '') {
        return null;
    }

    foreach (qt_game_ai_model_candidates() as $modelId) {
        try {
            $response = qt_game_ai_http_request(
                'POST',
                'https://api.groq.com/openai/v1/chat/completions',
                [
                    'model' => $modelId,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                ],
                ['Authorization: Bearer ' . $apiKey],
                30
            );
        } catch (Throwable $error) {
            error_log('[GAME AI] Request failed for model ' . $modelId . ': ' . $error->getMessage());
            continue;
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            continue;
        }

        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            if (qt_game_ai_should_retry_model($status, $decoded)) {
                continue;
            }
            return null;
        }

        $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $payload = qt_game_ai_extract_json_object($content);
        if (is_array($payload)) {
            return $payload;
        }
    }

    return null;
}

function qt_game_ai_board_to_text(array $state, string $gameType = QT_GAME_TYPE_CHESS): string
{
    $rows = [];
    $board = is_array($state['board'] ?? null) ? $state['board'] : [];
    $normalizedGameType = strtolower(trim($gameType));
    $pieceMap = $normalizedGameType === QT_GAME_TYPE_CHECKERS
        ? [
            'wm' => 'w', 'wk' => 'W',
            'bm' => 'b', 'bk' => 'B',
        ]
        : ($normalizedGameType === QT_GAME_TYPE_CONNECT_FOUR
            ? [
                'wc' => 'W',
                'bc' => 'B',
            ]
            : [
                'wp' => 'P', 'wn' => 'N', 'wb' => 'B', 'wr' => 'R', 'wq' => 'Q', 'wk' => 'K',
                'bp' => 'p', 'bn' => 'n', 'bb' => 'b', 'br' => 'r', 'bq' => 'q', 'bk' => 'k',
            ]);
    $rowCount = $normalizedGameType === QT_GAME_TYPE_CONNECT_FOUR ? 6 : 8;
    $colCount = $normalizedGameType === QT_GAME_TYPE_CONNECT_FOUR ? 7 : 8;
    $fileLabels = $normalizedGameType === QT_GAME_TYPE_CONNECT_FOUR ? 'a b c d e f g' : 'a b c d e f g h';

    for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
        $cells = [];
        $row = is_array($board[$rowIndex] ?? null) ? $board[$rowIndex] : [];
        for ($colIndex = 0; $colIndex < $colCount; $colIndex++) {
            $piece = strtolower(trim((string)($row[$colIndex] ?? '')));
            $cells[] = $pieceMap[$piece] ?? '.';
        }
        $rows[] = (string)($rowCount - $rowIndex) . ' ' . implode(' ', $cells);
    }

    $rows[] = '  ' . $fileLabels;
    return implode("\n", $rows);
}

function qt_game_ai_connect_four_square_to_coords(string $square): ?array
{
    $normalized = strtolower(trim($square));
    if (!preg_match('/^[a-g][1-6]$/', $normalized)) {
        return null;
    }

    return [
        'row' => 6 - (int)$normalized[1],
        'col' => ord($normalized[0]) - ord('a'),
    ];
}

function qt_game_ai_connect_four_has_line(array $board, string $color): bool
{
    $piece = $color === 'b' ? 'bc' : 'wc';
    $directions = [
        [0, 1],
        [1, 0],
        [1, 1],
        [1, -1],
    ];

    for ($row = 0; $row < 6; $row++) {
        for ($col = 0; $col < 7; $col++) {
            if (($board[$row][$col] ?? null) !== $piece) {
                continue;
            }
            foreach ($directions as [$rowDelta, $colDelta]) {
                $streak = 1;
                while ($streak < 4) {
                    $nextRow = $row + ($rowDelta * $streak);
                    $nextCol = $col + ($colDelta * $streak);
                    if ($nextRow < 0 || $nextRow >= 6 || $nextCol < 0 || $nextCol >= 7) {
                        break;
                    }
                    if (($board[$nextRow][$nextCol] ?? null) !== $piece) {
                        break;
                    }
                    $streak++;
                }
                if ($streak >= 4) {
                    return true;
                }
            }
        }
    }

    return false;
}

function qt_game_ai_connect_four_move_weight(array $move): int
{
    $coords = qt_game_ai_connect_four_square_to_coords((string)($move['to'] ?? ''));
    if (!$coords) {
        return 0;
    }

    return max(0, 6 - (abs(3 - (int)$coords['col']) * 2));
}

function qt_game_ai_choose_connect_four_move(array $state, array $legalMoves): array
{
    $turnColor = ($state['turn'] ?? 'w') === 'b' ? 'b' : 'w';
    $opponentColor = $turnColor === 'b' ? 'w' : 'b';
    $board = is_array($state['board'] ?? null) ? $state['board'] : [];

    foreach ($legalMoves as $move) {
        $coords = qt_game_ai_connect_four_square_to_coords((string)($move['to'] ?? ''));
        if (!$coords) {
            continue;
        }
        $trialBoard = $board;
        $trialBoard[$coords['row']][$coords['col']] = $turnColor === 'b' ? 'bc' : 'wc';
        if (qt_game_ai_connect_four_has_line($trialBoard, $turnColor)) {
            return $move;
        }
    }

    foreach ($legalMoves as $move) {
        $coords = qt_game_ai_connect_four_square_to_coords((string)($move['to'] ?? ''));
        if (!$coords) {
            continue;
        }
        $trialBoard = $board;
        $trialBoard[$coords['row']][$coords['col']] = $opponentColor === 'b' ? 'bc' : 'wc';
        if (qt_game_ai_connect_four_has_line($trialBoard, $opponentColor)) {
            return $move;
        }
    }

    $bestWeight = null;
    $bestMoves = [];
    foreach ($legalMoves as $move) {
        $weight = qt_game_ai_connect_four_move_weight($move);
        if ($bestWeight === null || $weight > $bestWeight) {
            $bestWeight = $weight;
            $bestMoves = [$move];
        } elseif ($weight === $bestWeight) {
            $bestMoves[] = $move;
        }
    }

    return $bestMoves[array_rand($bestMoves)];
}

function qt_game_ai_choose_fallback_move(array $legalMoves): array
{
    if (count($legalMoves) <= 1) {
        return $legalMoves[0];
    }

    $bestWeight = null;
    $bestMoves = [];
    foreach ($legalMoves as $move) {
        $notation = trim((string)($move['notation'] ?? ''));
        $weight = 0;
        if (!empty($move['capture'])) {
            $weight += 4;
        }
        if (!empty($move['promotion'])) {
            $weight += 3;
        }
        if (str_contains($notation, '#')) {
            $weight += 100;
        } elseif (str_contains($notation, '+')) {
            $weight += 8;
        }
        if ($bestWeight === null || $weight > $bestWeight) {
            $bestWeight = $weight;
            $bestMoves = [$move];
        } elseif ($weight === $bestWeight) {
            $bestMoves[] = $move;
        }
    }

    return $bestMoves[array_rand($bestMoves)];
}

function qt_game_ai_choose_move(array $game, array $state, array $legalMoves, array $historyMoves = []): array
{
    $gameType = strtolower(trim((string)($game['game_type'] ?? QT_GAME_TYPE_CHESS)));
    $normalizedMoves = [];
    foreach ($legalMoves as $move) {
        if (!is_array($move)) {
            continue;
        }
        $from = strtolower(trim((string)($move['from'] ?? '')));
        $to = strtolower(trim((string)($move['to'] ?? '')));
        $promotion = strtolower(trim((string)($move['promotion'] ?? '')));
        if ($gameType === QT_GAME_TYPE_CHECKERS) {
            if (!in_array($promotion, ['', 'k'], true)) {
                $promotion = '';
            }
            $uci = strtolower(trim((string)($move['uci'] ?? ($from . $to))));
            if (!preg_match('/^[a-h][1-8][a-h][1-8]$/', $uci)) {
                continue;
            }
        } elseif ($gameType === QT_GAME_TYPE_CONNECT_FOUR) {
            $promotion = '';
            $uci = strtolower(trim((string)($move['uci'] ?? $to)));
            if (!preg_match('/^[a-g][1-6]$/', $from) || !preg_match('/^[a-g][1-6]$/', $to) || !preg_match('/^[a-g][1-6]$/', $uci)) {
                continue;
            }
        } else {
            if (!in_array($promotion, ['', 'q', 'r', 'b', 'n'], true)) {
                $promotion = '';
            }
            $uci = strtolower(trim((string)($move['uci'] ?? ($from . $to . $promotion))));
            if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
                continue;
            }
        }
        $move['uci'] = $uci;
        $move['promotion'] = $promotion !== '' ? $promotion : null;
        $normalizedMoves[] = $move;
    }

    if ($normalizedMoves === []) {
        throw new RuntimeException('No legal moves available for QuillTalk AI.');
    }

    if (count($normalizedMoves) === 1) {
        return $normalizedMoves[0];
    }

    if ($gameType === QT_GAME_TYPE_CONNECT_FOUR) {
        return qt_game_ai_choose_connect_four_move($state, $normalizedMoves);
    }

    $legalMoveLines = [];
    foreach ($normalizedMoves as $move) {
        $legalMoveLines[] = '- ' . $move['uci'] . ' | ' . trim((string)($move['notation'] ?? $move['uci']));
    }

    $recentMoves = [];
    foreach (array_slice($historyMoves, -12) as $move) {
        $recentMoves[] = trim((string)($move['notation'] ?? $move['uci'] ?? ''));
    }

    $gameLabel = $gameType === QT_GAME_TYPE_CHECKERS
        ? 'checkers'
        : ($gameType === QT_GAME_TYPE_CONNECT_FOUR ? 'connect four' : 'chess');
    $systemPrompt = "You are QuillTalk AI playing {$gameLabel} inside QuillTalk. "
        . "Choose one move from the provided legal move list only. "
        . "Return strict JSON only in the form {\"uci\":\"e2e4\"}.";
    $userPrompt = "Board:\n" . qt_game_ai_board_to_text($state, $gameType)
        . "\n\nSide to move: " . (($state['turn'] ?? 'w') === 'b' ? 'Black' : 'White')
        . "\nTime control seconds per side: " . (int)($game['time_control_seconds'] ?? QT_GAME_DEFAULT_TIME_CONTROL_SECONDS)
        . "\nRecent moves: " . ($recentMoves !== [] ? implode(', ', $recentMoves) : 'None yet')
        . "\nLegal moves:\n" . implode("\n", $legalMoveLines)
        . "\n\nReply with JSON only.";

    $payload = qt_game_ai_request_json($systemPrompt, $userPrompt, 0.2, 80);
    $chosenUci = strtolower(trim((string)($payload['uci'] ?? '')));
    if ($chosenUci !== '') {
        foreach ($normalizedMoves as $move) {
            if ($move['uci'] === $chosenUci) {
                return $move;
            }
        }
    }

    return qt_game_ai_choose_fallback_move($normalizedMoves);
}

function qt_game_ai_build_chat_reply(string $gameType, array $state, array $chatMessages, string $userMessage, string $opponentName = QT_GAME_BOT_NAME): string
{
    $trimmedMessage = trim($userMessage);
    if ($trimmedMessage === '') {
        return 'I am here.';
    }

    $recentChatLines = [];
    foreach (array_slice($chatMessages, -6) as $message) {
        $speaker = trim((string)($message['display_name'] ?? 'Player'));
        $content = trim((string)($message['message'] ?? ''));
        if ($content !== '') {
            $recentChatLines[] = $speaker . ': ' . $content;
        }
    }

    $normalizedGameType = strtolower(trim($gameType));
    $gameLabel = $normalizedGameType === QT_GAME_TYPE_CHECKERS
        ? 'checkers'
        : ($normalizedGameType === QT_GAME_TYPE_CONNECT_FOUR ? 'connect four' : 'chess');
    $systemPrompt = "You are " . $opponentName . ", a friendly {$gameLabel} opponent inside QuillTalk. "
        . "Reply briefly, naturally, and in one or two short sentences. "
        . "Do not include move analysis walls of text. "
        . "Return strict JSON only in the form {\"message\":\"...\"}.";
    $userPrompt = "Board:\n" . qt_game_ai_board_to_text($state, $normalizedGameType)
        . "\n\nRecent game chat:\n" . ($recentChatLines !== [] ? implode("\n", $recentChatLines) : 'No prior chat.')
        . "\n\nLatest player message:\n" . $trimmedMessage
        . "\n\nReply with JSON only.";

    $payload = qt_game_ai_request_json($systemPrompt, $userPrompt, 0.55, 120);
    $message = trim((string)($payload['message'] ?? ''));
    if ($message !== '') {
        return substr($message, 0, 500);
    }

    $fallbacks = [
        'Good luck. Let us see how this position develops.',
        'Interesting choice. Your move definitely changes the shape of the board.',
        'I am focused. Let us keep the pressure on.',
        'That was a sharp idea. I will answer carefully.',
    ];
    return $fallbacks[array_rand($fallbacks)];
}
