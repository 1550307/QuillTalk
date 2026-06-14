<?php
declare(strict_types=1);
ob_start();
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/local_ai_debug.log');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

function qt_local_ai_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qt_local_ai_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function qt_local_ai_normalize_base_url(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 'http://127.0.0.1:11434';
    }

    if (!preg_match('~^https?://~i', $trimmed)) {
        $trimmed = 'http://' . $trimmed;
    }

    return rtrim($trimmed, '/');
}

function qt_local_ai_get_base_url(): string
{
    $envValue = (string)(
        getenv('QT_LOCAL_AI_OLLAMA_URL')
        ?: getenv('OLLAMA_HOST')
        ?: ($_SERVER['QT_LOCAL_AI_OLLAMA_URL'] ?? '')
        ?: ($_SERVER['OLLAMA_HOST'] ?? '')
    );

    return qt_local_ai_normalize_base_url($envValue);
}

function qt_local_ai_http_request(string $method, string $url, ?array $jsonBody = null, int $timeoutSeconds = 45): array
{
    $headers = ['Accept: application/json'];
    $payload = null;

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
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

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        throw new RuntimeException('Unable to reach AI right now.');
    }

    $httpCode = 0;
    if (!empty($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }

    return [
        'status' => $httpCode,
        'body' => (string)$responseBody,
    ];
}

function qt_local_ai_extract_context(array $rawContext): array
{
    $items = [];
    foreach ($rawContext as $entry) {
        if (!is_scalar($entry)) {
            continue;
        }

        $text = trim((string)$entry);
        if ($text === '') {
            continue;
        }

        $items[] = mb_substr($text, 0, 400);
        if (count($items) >= 10) {
            break;
        }
    }

    return $items;
}

function qt_local_ai_pick_model(string $baseUrl): string
{
    $configured = trim((string)(
        getenv('QT_LOCAL_AI_MODEL')
        ?: getenv('OLLAMA_MODEL')
        ?: ($_SERVER['QT_LOCAL_AI_MODEL'] ?? '')
        ?: ($_SERVER['OLLAMA_MODEL'] ?? '')
    ));

    if ($configured !== '') {
        return $configured;
    }

    $response = qt_local_ai_http_request('GET', $baseUrl . '/api/tags', null, 8);
    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
        throw new RuntimeException('Could not discover installed Ollama models.');
    }

    $decoded = json_decode((string)($response['body'] ?? ''), true);
    $models = [];
    foreach (($decoded['models'] ?? []) as $modelRow) {
        $name = trim((string)($modelRow['name'] ?? ''));
        if ($name !== '') {
            $models[] = $name;
        }
    }

    if (!$models) {
        throw new RuntimeException('No Ollama models are installed on this host yet.');
    }

    $preferred = [
        'llama3.2:3b',
        'qwen2.5:3b-instruct',
        'qwen2.5:1.5b-instruct',
        'phi3:mini',
        'gemma2:2b',
        'llama3.2',
    ];

    foreach ($preferred as $candidate) {
        if (in_array($candidate, $models, true)) {
            return $candidate;
        }
    }

    return (string)$models[0];
}

$input = qt_local_ai_read_json_body();
$token = trim((string)($input['token'] ?? ''));
$prompt = trim((string)($input['prompt'] ?? ''));
$chatKey = trim((string)($input['chat_key'] ?? ''));
$chatLabel = trim((string)($input['chat_label'] ?? ''));
$contextItems = qt_local_ai_extract_context(is_array($input['context'] ?? null) ? $input['context'] : []);

if ($token === '' || $prompt === '') {
    qt_local_ai_respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$sessionStmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    qt_local_ai_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$baseUrl = qt_local_ai_get_base_url();

try {
    $model = qt_local_ai_pick_model($baseUrl);

    $systemPrompt = implode("\n", array_filter([
        'You are QuillTalk AI, a concise and genuinely helpful local assistant inside a messaging app.',
        'Reply directly to the user prompt.',
        'Do not pretend to be the other chat participant.',
        'Keep answers short to medium unless the user explicitly asks for depth.',
        $chatLabel !== '' ? 'Current chat label: ' . $chatLabel : '',
        $chatKey !== '' ? 'Current chat key: ' . $chatKey : '',
        $contextItems ? "Recent chat context:\n- " . implode("\n- ", $contextItems) : '',
    ]));

    $requestBody = [
        'model' => $model,
        'stream' => false,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
        'options' => [
            'temperature' => 0.7,
        ],
    ];

    $response = qt_local_ai_http_request('POST', $baseUrl . '/api/chat', $requestBody, 120);
    $status = (int)($response['status'] ?? 0);
    $decoded = json_decode((string)($response['body'] ?? ''), true);

    if ($status < 200 || $status >= 300) {
        $errorMessage = trim((string)(
            $decoded['error']
            ?? $decoded['message']
            ?? ('Local AI request failed with HTTP ' . $status)
        ));
        throw new RuntimeException($errorMessage);
    }

    $message = trim((string)($decoded['message']['content'] ?? ''));
    if ($message === '') {
        throw new RuntimeException('The local model returned an empty response.');
    }

    qt_local_ai_respond([
        'success' => true,
        'message' => $message,
        'provider' => 'ollama',
        'model' => $model,
        'base_url' => $baseUrl,
    ]);
} catch (Throwable $error) {
    error_log('[LOCAL AI] ' . $error->getMessage());
    qt_local_ai_respond([
        'success' => false,
        'error' => $error->getMessage(),
        'provider' => 'ollama',
        'base_url' => $baseUrl,
    ], 503);
}
