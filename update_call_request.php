<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/blocking.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$token  = $data['token'] ?? '';
$id     = (int)($data['id'] ?? 0);
$status = $data['status'] ?? '';

// Temporary debug logging to trace accepts/rejects from SW or clients
try {
    $debugEntry = json_encode([
        'ts' => time(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'payload' => $data
    ]);
    @file_put_contents(__DIR__ . '/call_accept_debug.log', $debugEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (\Throwable $e) {
    // ignore
}

if ($token === '' || !$id || !in_array($status, ['accepted','rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
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
$user_id = (int)$session['user_id'];

// Allow either side of the call to close out the request, but never downgrade a resolved request.
$check = $pdo->prepare("SELECT caller_id, callee_id, status FROM call_requests WHERE id = ? LIMIT 1");
$check->execute([$id]);
$row = $check->fetch(PDO::FETCH_ASSOC);
if (
    !$row ||
    (
        (int)$row['callee_id'] !== $user_id &&
        (int)$row['caller_id'] !== $user_id
    )
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$currentStatus = $row['status'] ?? 'pending';
if ($currentStatus !== 'pending') {
    echo json_encode([
        'success' => true,
        'updated' => false,
        'status' => $currentStatus
    ]);
    exit;
}

$callerId = (int)($row['caller_id'] ?? 0);
$calleeId = (int)($row['callee_id'] ?? 0);
if ($status === 'accepted' && qt_has_block_between($pdo, $callerId, $calleeId)) {
    $pdo->prepare("UPDATE call_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'This call is no longer available.',
        'status' => 'rejected'
    ]);
    exit;
}

$update = $pdo->prepare("UPDATE call_requests SET status = ? WHERE id = ?");
$update->execute([$status, $id]);

echo json_encode([
    'success' => true,
    'updated' => true,
    'status' => $status
]);
