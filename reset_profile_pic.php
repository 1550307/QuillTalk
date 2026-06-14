<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';
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

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    if (ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode([
        'success' => false,
        'error' => 'Fatal profile picture reset error',
        'detail' => (string)($error['message'] ?? 'Unknown fatal error')
    ], JSON_UNESCAPED_UNICODE);
});

try {
    $data = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($data)) {
        $data = $_POST ?: [];
    }

    $token = trim((string)($data['token'] ?? ''));
    if ($token === '') {
        respond(['success' => false, 'error' => 'Missing token'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT users.id, COALESCE(users.profile_pic, '') AS profile_pic
        FROM sessions
        JOIN users ON sessions.user_id = users.id
        WHERE sessions.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        respond(['success' => false, 'error' => 'Invalid session'], 401);
    }

    $userId = (int)($session['id'] ?? 0);
    if ($userId <= 0) {
        respond(['success' => false, 'error' => 'Invalid session'], 401);
    }

    $currentProfilePic = trim((string)($session['profile_pic'] ?? ''));
    $update = $pdo->prepare("UPDATE users SET profile_pic = '' WHERE id = ?");
    $update->execute([$userId]);

    if ($currentProfilePic !== '' && str_starts_with($currentProfilePic, 'uploads/profiles/')) {
        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $currentProfilePic);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    respond(['success' => true]);
} catch (Throwable $e) {
    error_log('[RESET PROFILE PIC] ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Unable to reset your profile picture right now.'], 500);
}
