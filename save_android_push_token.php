<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$token = trim((string)($data['token'] ?? ''));
$fcmToken = trim((string)($data['fcm_token'] ?? ''));
$platform = trim((string)($data['platform'] ?? 'android'));

if ($token === '' || $fcmToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing token data']);
    exit;
}

$sessionStmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$sessionStmt->execute([$token]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$userId = (int)($session['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session user']);
    exit;
}

try {
    $saveStmt = $pdo->prepare("
        INSERT INTO android_push_tokens (user_id, fcm_token, platform)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            platform = VALUES(platform),
            updated_at = CURRENT_TIMESTAMP
    ");
    $saveStmt->execute([$userId, $fcmToken, $platform !== '' ? $platform : 'android']);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to save Android push token'
    ], JSON_UNESCAPED_UNICODE);
}
