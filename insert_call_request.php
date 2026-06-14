<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? '';
$caller_id = (int)($data['caller_id'] ?? 0);
$callee_id = (int)($data['callee_id'] ?? 0);

if ($token === '' || !$caller_id || !$callee_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

if ($caller_id === $callee_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You cannot call yourself']);
    exit;
}

// Validate session and user
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session || (int)$session['user_id'] !== $caller_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$friendshipCheck = $pdo->prepare("
    SELECT 1
    FROM friends
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
    LIMIT 1
");
$friendshipCheck->execute([$caller_id, $callee_id, $callee_id, $caller_id]);
if (!$friendshipCheck->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not friends']);
    exit;
}

$blockRelationship = qt_get_block_relationship($pdo, $caller_id, $callee_id);
if (!empty($blockRelationship['viewer_has_blocked'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot call users you have blocked.']);
    exit;
}
if (!empty($blockRelationship['blocked_viewer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot call users who have blocked you.']);
    exit;
}

// Ensure table exists (safe no-op if already present)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS call_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        caller_id INT UNSIGNED NOT NULL,
        callee_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_callee_status_created (callee_id, status, created_at),
        INDEX idx_caller_created (caller_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Insert pending call request
$insert = $pdo->prepare("
    INSERT INTO call_requests (caller_id, callee_id, status, created_at)
    VALUES (?, ?, 'pending', NOW())
");
$ok = $insert->execute([$caller_id, $callee_id]);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB insert failed']);
    exit;
}

$id = (int)$pdo->lastInsertId();

echo json_encode(['success' => true, 'id' => $id]);
