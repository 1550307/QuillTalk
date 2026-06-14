<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';

$data    = json_decode(file_get_contents('php://input'), true);
$token   = $data['token']   ?? '';
$peer_id = trim((string)($data['peer_id'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

// Ensure column exists
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'peer_id_alias'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN peer_id_alias VARCHAR(120) DEFAULT NULL");
    }
} catch (Throwable $e) {}

try {
    $pdo->prepare("UPDATE users SET peer_id_alias = ? WHERE id = ?")
        ->execute([$peer_id ?: null, (int)$row['user_id']]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
