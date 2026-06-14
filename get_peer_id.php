<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';

$token   = $_GET['token']   ?? '';
$user_id = (int)($_GET['user_id'] ?? 0);

if ($token === '' || !$user_id) {
    http_response_code(400);
    echo json_encode(['peer_id' => null]);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
if (!$stmt->fetch()) {
    http_response_code(401);
    echo json_encode(['peer_id' => null]);
    exit;
}

// Try to add column if missing (safe on MySQL 5.7+)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'peer_id_alias'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN peer_id_alias VARCHAR(120) DEFAULT NULL");
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT peer_id_alias FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $peerId = ($row && !empty($row['peer_id_alias'])) ? $row['peer_id_alias'] : null;
} catch (Throwable $e) {
    $peerId = null;
}

echo json_encode(['peer_id' => $peerId]);
