<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody ?: '', true);

$token = trim((string)($_POST['token'] ?? ($jsonBody['token'] ?? '')));
$onlineRaw = $_POST['online'] ?? ($jsonBody['online'] ?? null);
$online = (int)$onlineRaw === 1 ? 1 : 0;

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing token']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$userId = (int)($session['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user']);
    exit;
}

try {
    $update = $pdo->prepare("
        UPDATE users
        SET online = ?, last_seen_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $update->execute([$online, $userId]);

    echo json_encode([
        'success' => true,
        'online' => $online,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
