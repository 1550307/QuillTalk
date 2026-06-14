<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const QT_TENOR_DEFAULT_LIMIT = 16;
const QT_TENOR_CONFIG_TTL = 21600;
const QT_TENOR_FEATURED_TTL = 300;
const QT_TENOR_SEARCH_TTL = 60;
const QT_TENOR_HTTP_TIMEOUT = 20;
const QT_TENOR_FALLBACK_API_KEY = 'AIzaSyC-P6_qz3FzCoXGLk6tgitZo4jEJ5mLzD8';
const QT_TENOR_FALLBACK_CLIENT_KEY = 'tenor_web';
const QT_TENOR_FALLBACK_API_URL = 'https://tenor.googleapis.com/v2';

function qt_gif_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qt_gif_respond_raw(string $body, int $status = 200): void
{
    http_response_code($status);
    echo $body;
    exit;
}

function qt_gif_get_request_token(): string
{
    foreach ([$_GET['token'] ?? null, $_POST['token'] ?? null] as $candidate) {
        $normalized = trim((string)($candidate ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function qt_gif_require_user_id(PDO $pdo): int
{
    $token = qt_gif_get_request_token();
    if ($token !== '') {
        $stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $userId = (int)($stmt->fetchColumn() ?: 0);
        if ($userId > 0) {
            $_SESSION['user_id'] = $userId;
        }
        return $userId;
    }

    return (int)($_SESSION['user_id'] ?? 0);
}

function qt_gif_normalize_locale(string $value): string
{
    $normalized = str_replace('-', '_', trim($value));
    return preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $normalized) === 1 ? $normalized : 'en_US';
}

function qt_gif_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quilltalk_tenor_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return is_dir($dir) ? $dir : sys_get_temp_dir();
}

function qt_gif_cache_path(string $key): string
{
    return qt_gif_cache_dir() . DIRECTORY_SEPARATOR . 'gif_' . sha1($key) . '.json';
}

function qt_gif_read_cache(string $key, int $ttl): ?array
{
    $path = qt_gif_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload) || !isset($payload['saved_at'], $payload['body'])) {
        return null;
    }

    $savedAt = (int)($payload['saved_at'] ?? 0);
    $body = (string)($payload['body'] ?? '');
    if ($savedAt <= 0 || $body === '') {
        return null;
    }

    return [
        'is_fresh' => (time() - $savedAt) <= max(1, $ttl),
        'body' => $body,
    ];
}

function qt_gif_write_cache(string $key, string $body): void
{
    if ($body === '') {
        return;
    }

    $path = qt_gif_cache_path($key);
    @file_put_contents($path, json_encode([
        'saved_at' => time(),
        'body' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function qt_gif_http_get(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => QT_TENOR_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'QuillTalk GIF Search/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/html;q=0.9'],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => QT_TENOR_HTTP_TIMEOUT,
            'ignore_errors' => true,
            'header' => "User-Agent: QuillTalk GIF Search/1.0\r\nAccept: application/json, text/html;q=0.9\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$headerLine, $matches) === 1) {
            $status = (int)$matches[1];
            break;
        }
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'Request failed' : '',
    ];
}

function qt_gif_resolve_tenor_config(): array
{
    $configuredKey = trim((string)(getenv('TENOR_API_KEY') ?: ($_SERVER['TENOR_API_KEY'] ?? '')));
    $configuredClientKey = trim((string)(getenv('TENOR_CLIENT_KEY') ?: ($_SERVER['TENOR_CLIENT_KEY'] ?? '')));
    if ($configuredKey !== '') {
        return [
            'api_key' => $configuredKey,
            'client_key' => $configuredClientKey !== '' ? $configuredClientKey : 'quilltalk_web',
            'api_url' => QT_TENOR_FALLBACK_API_URL,
        ];
    }

    $cacheKey = 'tenor_public_config';
    $cachedConfig = qt_gif_read_cache($cacheKey, QT_TENOR_CONFIG_TTL);
    if ($cachedConfig && !empty($cachedConfig['body'])) {
        $decoded = json_decode((string)$cachedConfig['body'], true);
        if (
            is_array($decoded)
            && !empty($decoded['api_key'])
            && !empty($decoded['client_key'])
            && !empty($decoded['api_url'])
        ) {
            return $decoded;
        }
    }

    $pageResponse = qt_gif_http_get('https://tenor.com/search/gifs');
    if (
        $pageResponse['status'] === 200
        && preg_match('/<script id="data" type="text\/x-cache"[^>]*>([^<]+)<\/script>/', (string)$pageResponse['body'], $matches) === 1
    ) {
        $decodedConfigJson = base64_decode((string)$matches[1], true);
        $decodedConfig = is_string($decodedConfigJson) ? json_decode($decodedConfigJson, true) : null;
        if (
            is_array($decodedConfig)
            && !empty($decodedConfig['API_V2_KEY'])
            && !empty($decodedConfig['API_V2_CLIENT_KEY'])
            && !empty($decodedConfig['API_V2_URL'])
        ) {
            $resolved = [
                'api_key' => (string)$decodedConfig['API_V2_KEY'],
                'client_key' => (string)$decodedConfig['API_V2_CLIENT_KEY'],
                'api_url' => (string)$decodedConfig['API_V2_URL'],
            ];
            qt_gif_write_cache($cacheKey, json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $resolved;
        }
    }

    return [
        'api_key' => QT_TENOR_FALLBACK_API_KEY,
        'client_key' => QT_TENOR_FALLBACK_CLIENT_KEY,
        'api_url' => QT_TENOR_FALLBACK_API_URL,
    ];
}

$userId = qt_gif_require_user_id($pdo);
if ($userId <= 0) {
    qt_gif_respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$query = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(24, (int)($_GET['limit'] ?? QT_TENOR_DEFAULT_LIMIT)));
$locale = qt_gif_normalize_locale((string)($_GET['locale'] ?? 'en_US'));
$endpoint = $query === '' ? 'featured' : 'search';
$cacheTtl = $query === '' ? QT_TENOR_FEATURED_TTL : QT_TENOR_SEARCH_TTL;

$requestCacheKey = json_encode([
    'endpoint' => $endpoint,
    'query' => $query,
    'limit' => $limit,
    'locale' => $locale,
]);

$cachedResponse = qt_gif_read_cache((string)$requestCacheKey, $cacheTtl);
if ($cachedResponse && !empty($cachedResponse['is_fresh']) && !empty($cachedResponse['body'])) {
    qt_gif_respond_raw((string)$cachedResponse['body']);
}

$tenorConfig = qt_gif_resolve_tenor_config();
$params = [
    'key' => (string)$tenorConfig['api_key'],
    'client_key' => (string)$tenorConfig['client_key'],
    'limit' => (string)$limit,
    'contentfilter' => 'low',
    'locale' => $locale,
    'media_filter' => 'gif,tinygif,nanogif,mediumgif',
];

if ($query !== '') {
    $params['q'] = $query;
}

$requestUrl = rtrim((string)$tenorConfig['api_url'], '/') . '/' . $endpoint . '?' . http_build_query($params);
$response = qt_gif_http_get($requestUrl);

if ($response['status'] === 200 && trim((string)$response['body']) !== '') {
    qt_gif_write_cache((string)$requestCacheKey, (string)$response['body']);
    qt_gif_respond_raw((string)$response['body']);
}

if ($cachedResponse && !empty($cachedResponse['body'])) {
    qt_gif_respond_raw((string)$cachedResponse['body']);
}

qt_gif_respond([
    'success' => false,
    'error' => $response['status'] > 0
        ? ('GIF search provider returned status ' . $response['status'])
        : 'Could not reach the GIF search provider',
], 502);
