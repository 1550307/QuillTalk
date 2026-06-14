<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';
ini_set('display_errors', '0');
ini_set('html_errors', '0');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    if (ob_get_length() > 0) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function typingStorageCandidates(): array
{
    return [
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profiles',
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads',
    ];
}

function typingStorageDir(): string
{
    static $resolvedDir = null;

    if (is_string($resolvedDir)) {
        return $resolvedDir;
    }

    foreach (typingStorageCandidates() as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            $resolvedDir = $dir;
            return $resolvedDir;
        }
    }

    throw new RuntimeException('Unable to initialize typing storage');
}

function typingStoragePath(int $userId, int $peerId): string
{
    return typingStorageDir() . DIRECTORY_SEPARATOR . 'typing_' . $userId . '_' . $peerId . '.json';
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = $method === 'POST' ? $_POST : $_GET;

$token = trim((string)($input['token'] ?? ''));
$with = (int)($input['with'] ?? 0);

if ($token === '' || $with <= 0) {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    respond(['success' => false, 'error' => 'Invalid session'], 401);
}

$userId = (int)$session['user_id'];

if ($userId === $with) {
    respond(['success' => true, 'is_typing' => false]);
}

$check = $pdo->prepare("
    SELECT 1
    FROM friends
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
    LIMIT 1
");
$check->execute([$userId, $with, $with, $userId]);

if (!$check->fetch()) {
    respond(['success' => false, 'error' => 'Not friends'], 403);
}

if (qt_has_block_between($pdo, $userId, $with)) {
    respond(['success' => true, 'is_typing' => false]);
}

try {
    typingStorageDir();
} catch (Throwable $e) {
    respond(['success' => false, 'error' => 'Typing storage unavailable'], 500);
}

if ($method === 'POST') {
    $isTyping = (($input['is_typing'] ?? '0') === '1');
    $path = typingStoragePath($userId, $with);

    if ($isTyping) {
        $payload = json_encode([
            'is_typing' => true,
            'updated_at' => time(),
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false || @file_put_contents($path, $payload, LOCK_EX) === false) {
            respond(['success' => false, 'error' => 'Unable to store typing state'], 500);
        }
    } elseif (is_file($path)) {
        @unlink($path);
    }

    respond(['success' => true]);
}

if ($method !== 'GET') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

$path = typingStoragePath($with, $userId);
$isTyping = false;

if (is_file($path)) {
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $updatedAt = is_array($data) ? (int)($data['updated_at'] ?? 0) : 0;

    $isTyping = is_array($data)
        && !empty($data['is_typing'])
        && $updatedAt >= (time() - 6);

    if (!$isTyping) {
        @unlink($path);
    }
}

    respond([
        'success' => true,
        'is_typing' => $isTyping,
    ]);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'error' => 'Typing endpoint failed',
    ], 500);
}
